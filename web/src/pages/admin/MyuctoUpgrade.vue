<script setup lang="ts">
/**
 * Přechod na MyÚčto.cz — nástupce MyInvoice od stejného autora.
 *
 * Stavěno po vzoru Systém → Aktualizace (stejná mechanika: preflight,
 * spuštění workera na pozadí, polling průběhu), ale s vlastním stavem
 * a jedním rozdílem navíc: tohle je jednosměrná operace, takže se bez
 * vědomého potvrzení zálohy nespustí.
 */
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import {
  myuctoUpgradeApi,
  type MyuctoUpgradeStatus,
  type MyuctoUpgradePreflight,
  type MyuctoUpgradeTriggerResponse,
} from '@/api/myuctoUpgrade'
import { useSessionAwarePolling } from '@/composables/useSessionAwarePolling'

const { t } = useI18n()
const auth = useAuthStore()

const status = ref<MyuctoUpgradeStatus | null>(null)
const preflight = ref<MyuctoUpgradePreflight | null>(null)
const triggerResult = ref<MyuctoUpgradeTriggerResponse | null>(null)
const errorMsg = ref<string | null>(null)

const checking = ref(false)
const triggering = ref(false)
const cancelling = ref(false)
const backupConfirmed = ref(false)

const pollingEnabled = ref(true)

/**
 * Fáze, ve které aplikace pod stránkou mizí.
 *
 * Progres se čte pollingem, jenže od okamžiku swapu se není koho ptát:
 * nejdřív brána údržby vrací na všechno 503 `maintenance`, a jakmile se
 * vymění kód, zmizí i routa `/admin/myucto-upgrade/*` — nástupce ji nemá,
 * je to funkce předchůdce. Bez ošetření stránka zůstane viset na posledním
 * úspěšném pollu (typicky „krok 5 z 9 — Rozbaluji bundle") a vypadá to jako
 * zásek, přestože přechod v pořádku doběhl.
 *
 * `maintenance` = běží swap a migrace, pollujeme dál.
 * `done`        = odpovídá už nástupce, stránka je poslední kus MyInvoice
 *                 v prohlížeči a jediné rozumné, co nabídnout, je reload.
 */
const handover = ref<null | 'maintenance' | 'done'>(null)

/** Přechod jsme spustili my, nebo jsme ho zastihli běžet — viz `handover`. */
const upgradeSeenRunning = ref(false)

/** @return HTTP status a kód chyby z axios chyby, bez závislosti na jejím typu. */
function errorInfo(e: unknown): { status?: number; code?: string } {
  const r = (e as { response?: { status?: number; data?: { error?: { code?: string } } } })?.response
  return { status: r?.status, code: r?.data?.error?.code }
}

const isAdmin = computed(() => auth.user?.role === 'admin')

/** Zvládne to instalace sama? Docker jede přes host, ne přes aplikaci. */
const canRunHere = computed(
  () => status.value?.environment === 'native' && preflight.value?.ok === true,
)

const canTrigger = computed(
  () =>
    !!status.value?.myucto_version &&
    !status.value.in_progress &&
    backupConfirmed.value &&
    !triggering.value,
)

/**
 * Výhody MyÚčta. Číslované klíče místo pole v i18n — `t()` vrací řetězec,
 * takže pole by se muselo tahat přes `tm()`/`rt()` a při chybějícím překladu
 * by tiše zmizelo celé. Takhle je vidět, co chybí.
 */
const BENEFIT_KEYS = ['ui', 'shortcuts', 'ai', 'mcp', 'reports', 'docs', 'dev'] as const

const benefits = computed<string[]>(() =>
  BENEFIT_KEYS.map((k) => t(`myucto_upgrade.benefit_${k}`)),
)

async function load(signal?: AbortSignal) {
  errorMsg.value = null
  try {
    const next = await myuctoUpgradeApi.status(signal)
    status.value = next

    // Backend zase odpovídá — okno údržby tedy skončilo a je to pořád tenhle
    // produkt (nástupce tuhle routu nemá). Bez vynulování by panel „vyměňují
    // se soubory" zůstal viset i po zrušeném nebo neúspěšném přechodu.
    if (handover.value === 'maintenance') {
      handover.value = null
    }

    // Prázdná / stará cache → dotáhni verzi na pozadí, ať uživatel nemusí
    // mačkat „Zkontrolovat". Tiché selhání je v pořádku, tlačítko zůstává.
    if (next.cache_stale && !checking.value) {
      void backgroundRefresh()
    }

    // Preflight zapisuje probe soubory kvůli kontrole práv — jen jednou,
    // ne na každém pollu.
    if (next.environment === 'native' && next.myucto_version && preflight.value === null) {
      try {
        preflight.value = await myuctoUpgradeApi.preflight(next.myucto_version, signal)
      } catch {
        /* ponech null — UI nabídne ruční postup */
      }
    }
  } catch (e: unknown) {
    const { status: httpStatus, code } = errorInfo(e)

    // Brána údržby: soubory se vyměňují, migrace běží. Není to chyba.
    if (httpStatus === 503 && code === 'maintenance') {
      handover.value = 'maintenance'
      upgradeSeenRunning.value = true
      errorMsg.value = null
      return
    }

    // Routa zmizela (404), nebo nová aplikace neuznala session (401) —
    // obojí znamená, že pod stránkou už běží nástupce. Hlásit „chyba" by
    // bylo zavádějící: tohle je cíl operace, ne její selhání.
    if (upgradeSeenRunning.value && (httpStatus === 404 || httpStatus === 401)) {
      handover.value = 'done'
      pollingEnabled.value = false
      errorMsg.value = null
      return
    }

    errorMsg.value = (e as Error)?.message ?? 'Failed to load status'
  }
}

async function backgroundRefresh() {
  if (checking.value) return
  checking.value = true
  try {
    status.value = await myuctoUpgradeApi.refresh()
  } catch {
    /* tiché selhání — zůstává cache + tlačítko */
  } finally {
    checking.value = false
  }
}

async function refresh() {
  if (checking.value) return
  checking.value = true
  errorMsg.value = null
  try {
    status.value = await myuctoUpgradeApi.refresh()
    preflight.value = null
  } catch (e: unknown) {
    errorMsg.value = (e as Error)?.message ?? 'Refresh failed'
  } finally {
    checking.value = false
  }
}

async function startUpgrade() {
  if (!canTrigger.value || !status.value?.myucto_version) return
  if (!confirm(t('myucto_upgrade.confirm', { version: status.value.myucto_version }))) return

  triggering.value = true
  triggerResult.value = null
  errorMsg.value = null
  try {
    const r = await myuctoUpgradeApi.trigger(backupConfirmed.value, status.value.myucto_version)
    triggerResult.value = r
    if (r.status === 'queued') {
      pollingEnabled.value = true
      upgradeSeenRunning.value = true
    } else {
      await load()
    }
  } catch (e: unknown) {
    errorMsg.value = (e as Error)?.message ?? 'Trigger failed'
  } finally {
    triggering.value = false
  }
}

async function cancelStuck() {
  if (cancelling.value) return
  if (!confirm(t('myucto_upgrade.cancel_confirm'))) return
  cancelling.value = true
  errorMsg.value = null
  try {
    await myuctoUpgradeApi.cancel()
    pollingEnabled.value = false
    triggerResult.value = null
    await load()
  } catch (e: unknown) {
    errorMsg.value = (e as Error)?.message ?? 'Cancel failed'
  } finally {
    cancelling.value = false
  }
}

async function poll(signal: AbortSignal) {
  await load(signal)
  if (handover.value === 'done') {
    pollingEnabled.value = false
    return
  }
  const running = status.value?.in_progress === true
  if (running) {
    upgradeSeenRunning.value = true
  }
  // V režimu údržby backend neodpovídá, takže `in_progress` nemáme z čeho
  // přečíst — polling ale musí běžet dál, jinak by se stránka o dokončení
  // nedozvěděla.
  pollingEnabled.value = running || handover.value === 'maintenance'
  // „Zařazeno" je jen překlenutí, než worker začne hlásit průběh — jakmile
  // doběhne, platný stav ukazuje panel s výsledkem.
  if (!running && triggerResult.value?.status === 'queued' && status.value?.last_result) {
    triggerResult.value = null
  }
}

useSessionAwarePolling(poll, 5000, pollingEnabled)

/**
 * Odchod na přehled nástupce.
 *
 * Tvrdá navigace, ne router push: v prohlížeči běží SPA MyInvoice, kterou na
 * disku nahradila SPA nástupce, takže se přejít dá jen novým načtením
 * dokumentu.
 *
 * A NE reload téhle adresy: `/admin/upgrade` je stránka předchůdce a nástupce
 * ji nemá, takže by přechod skončil na 404 — po úspěšné operaci ta nejhorší
 * možná odměna. Míříme proto na kořen, odkud si router nástupce najde přehled
 * (nebo přihlášení, pokud session nepřežila).
 *
 * `replace` místo `assign`, aby mrtvá adresa nezůstala v historii a tlačítko
 * zpět na ni uživatele nevrátilo.
 */
function reloadIntoSuccessor(): void {
  window.location.replace('/')
}

function fmtDate(s?: string | null): string {
  if (!s) return '—'
  try {
    return new Date(s).toLocaleString()
  } catch {
    return s
  }
}

function stepLabel(step: string): string {
  const key = `myucto_upgrade.step_${step}`
  const label = t(key)
  return label === key ? step : label
}
</script>

<template>
  <div class="max-w-4xl mx-auto">
    <header class="mb-6">
      <h1 class="text-2xl font-semibold text-neutral-900">{{ t('myucto_upgrade.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('myucto_upgrade.subtitle') }}</p>
    </header>

    <div v-if="!isAdmin" class="rounded-md bg-warning-50 border border-warning-200 p-4 text-sm text-warning-800">
      {{ t('myucto_upgrade.no_admin') }}
    </div>

    <div v-else class="space-y-6">
      <div v-if="errorMsg" class="rounded-md bg-danger-50 border border-danger-500/40 p-3 text-sm text-danger-600">
        {{ errorMsg }}
      </div>

      <!-- ── Co je MyÚčto ── -->
      <section class="rounded-lg border border-primary-300 bg-primary-50/40 p-5">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('myucto_upgrade.about_title') }}</h2>
        <p class="text-sm text-neutral-700 mt-1.5">{{ t('myucto_upgrade.about_intro') }}</p>

        <h3 class="text-sm font-semibold text-neutral-800 mt-5">{{ t('myucto_upgrade.benefits_title') }}</h3>
        <ul class="mt-2 grid gap-1.5 sm:grid-cols-2">
          <li v-for="b in benefits" :key="b" class="flex gap-2 text-sm text-neutral-700">
            <svg class="w-4 h-4 mt-0.5 shrink-0 text-success-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            <span>{{ b }}</span>
          </li>
        </ul>

        <p class="text-xs text-neutral-600 mt-4">
          <strong class="font-medium text-neutral-700">{{ t('myucto_upgrade.paid_title') }}:</strong>
          {{ t('myucto_upgrade.paid_text') }}
        </p>

        <div class="mt-4 flex flex-wrap gap-2">
          <a href="https://myucto.cz" target="_blank" rel="noopener"
            class="inline-flex items-center gap-1.5 rounded-md border border-neutral-300 bg-surface px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
            MyUcto.cz
          </a>
          <a href="https://github.com/radekhulan/myucto" target="_blank" rel="noopener"
            class="inline-flex items-center gap-1.5 rounded-md border border-neutral-300 bg-surface px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50">
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0 0 24 12c0-6.63-5.37-12-12-12z"/></svg>
            GitHub
          </a>
        </div>
      </section>

      <template v-if="status">
        <!-- ── Verze ── -->
        <section class="rounded-lg border border-neutral-200 bg-surface p-5">
          <div class="flex flex-wrap items-baseline justify-between gap-4">
            <div>
              <div class="text-xs uppercase tracking-wider text-neutral-500">{{ t('myucto_upgrade.current_version') }}</div>
              <div class="text-3xl font-semibold text-neutral-900 leading-tight mt-0.5">
                MyInvoice v{{ status.current_version }}
              </div>
            </div>
            <svg class="w-6 h-6 text-neutral-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
            <div class="text-right">
              <div class="text-xs uppercase tracking-wider text-neutral-500">{{ t('myucto_upgrade.target_version') }}</div>
              <div class="text-3xl font-semibold text-primary-700 leading-tight mt-0.5">
                {{ status.myucto_version ? 'MyÚčto v' + status.myucto_version : t('myucto_upgrade.no_check_yet') }}
              </div>
            </div>
          </div>

          <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-sm">
            <span class="text-neutral-500">
              {{ t('myucto_upgrade.environment') }}:
              <strong class="text-neutral-700 font-medium">
                {{ status.environment === 'docker' ? t('myucto_upgrade.env_docker') : t('myucto_upgrade.env_native') }}
              </strong>
            </span>
            <span class="text-neutral-500">
              {{ t('myucto_upgrade.last_check') }}: <span class="text-neutral-700">{{ fmtDate(status.last_check_at) }}</span>
            </span>
          </div>

          <div v-if="status.last_check_error"
            class="mt-3 rounded-md bg-danger-50 border border-danger-500/40 p-3 text-xs text-danger-600">
            {{ t('myucto_upgrade.last_check_error') }}: <span class="font-mono">{{ status.last_check_error }}</span>
          </div>

          <div class="mt-5">
            <button type="button" @click="refresh" :disabled="checking"
              class="inline-flex items-center gap-1.5 rounded-md border border-neutral-300 bg-surface px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50 disabled:opacity-60 disabled:cursor-not-allowed">
              <svg class="w-4 h-4" :class="{ 'animate-spin': checking }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 0 0 4.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 0 1-15.357-2m15.357 2H15"/></svg>
              {{ checking ? t('myucto_upgrade.checking') : t('myucto_upgrade.check_now') }}
            </button>
          </div>
        </section>

        <!-- ── Jak přechod probíhá ── -->
        <section class="rounded-lg border border-neutral-200 bg-surface p-5">
          <h2 class="text-lg font-semibold text-neutral-900">{{ t('myucto_upgrade.how_title') }}</h2>
          <p class="text-sm text-neutral-700 mt-1.5">{{ t('myucto_upgrade.how_text') }}</p>
          <ul class="mt-3 space-y-1.5">
            <li v-for="(step, i) in status.steps" :key="step" class="flex gap-2.5 text-sm text-neutral-700">
              <span class="shrink-0 w-5 h-5 rounded-full bg-neutral-100 text-neutral-600 text-xs font-medium flex items-center justify-center">{{ i + 1 }}</span>
              <span>{{ stepLabel(step) }}</span>
            </li>
          </ul>
        </section>

        <!-- ── Kontrola prostředí ──
             Ukazuje se i když všechno sedí: před nevratnou operací je „co se
             ověřilo a s jakou hodnotou" ta informace, podle které se člověk
             rozhoduje. Samotné mlčení by znamenalo důvěřovat bez důkazu. -->
        <section v-if="!status.in_progress" class="rounded-lg border border-neutral-300 bg-surface p-5">
          <h2 class="text-lg font-semibold text-neutral-900 flex items-center gap-2">
            <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
            {{ t('myucto_upgrade.checks_title') }}
          </h2>
          <p class="text-sm text-neutral-600 mt-1.5">{{ t('myucto_upgrade.checks_intro') }}</p>

          <ul v-if="preflight?.checks?.length" class="mt-3 divide-y divide-neutral-200 border-y border-neutral-200">
            <li v-for="c in preflight.checks" :key="c.id"
              class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 py-2 text-sm">
              <span class="shrink-0" :class="c.status === 'ok' ? 'text-success-600' : 'text-danger-600'" aria-hidden="true">
                {{ c.status === 'ok' ? '✓' : '✗' }}
              </span>
              <span class="font-medium text-neutral-800">{{ c.label }}</span>
              <span class="font-mono text-xs" :class="c.status === 'ok' ? 'text-neutral-700' : 'text-danger-700 font-semibold'">
                {{ c.actual }}
              </span>
              <span class="text-xs text-neutral-500">({{ t('myucto_upgrade.checks_expected') }}: {{ c.expected }})</span>
            </li>
          </ul>
          <p v-else class="mt-3 text-sm text-neutral-600">{{ t('myucto_upgrade.checks_pending') }}</p>
        </section>

        <!-- ── Záloha (povinná) ── -->
        <section v-if="!status.in_progress" class="rounded-lg border border-warning-300 bg-warning-50/40 p-5">
          <h2 class="text-lg font-semibold text-warning-900 flex items-center gap-2">
            <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
            {{ t('myucto_upgrade.backup_title') }}
          </h2>
          <p class="text-sm text-warning-900/90 mt-1.5">{{ t('myucto_upgrade.backup_text') }}</p>

          <div class="mt-3 rounded-md border px-3 py-2 text-sm"
            :class="status.backup.exists
              ? (status.backup.fresh ? 'border-success-500/40 bg-success-50 text-success-800' : 'border-neutral-300 bg-surface text-neutral-700')
              : 'border-neutral-300 bg-surface text-neutral-700'">
            <template v-if="status.backup.exists">
              {{ t('myucto_upgrade.backup_last', { date: fmtDate(status.backup.newest_at), file: status.backup.newest_file }) }}
            </template>
            <template v-else>{{ t('myucto_upgrade.backup_none') }}</template>
          </div>

          <label class="mt-4 flex items-start gap-2.5 text-sm text-warning-900 cursor-pointer">
            <input type="checkbox" v-model="backupConfirmed"
              class="mt-0.5 h-4 w-4 rounded border-neutral-400 text-primary-600 focus:ring-primary-500" />
            <span>{{ t('myucto_upgrade.backup_confirm') }}</span>
          </label>

          <div class="mt-4 flex flex-wrap items-center gap-3">
            <button type="button" @click="startUpgrade" :disabled="!canTrigger"
              class="inline-flex items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50 disabled:cursor-not-allowed">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
              {{ triggering ? t('myucto_upgrade.starting') : t('myucto_upgrade.start') }}
            </button>
            <span v-if="!status.myucto_version" class="text-xs text-neutral-600">{{ t('myucto_upgrade.need_check_first') }}</span>
            <span v-else-if="!backupConfirmed" class="text-xs text-neutral-600">{{ t('myucto_upgrade.need_backup_first') }}</span>
            <span v-else-if="!canRunHere" class="text-xs text-neutral-600">{{ t('myucto_upgrade.will_show_manual') }}</span>
          </div>

          <ul v-if="preflight && !preflight.ok && preflight.blockers.length" class="mt-3 space-y-1">
            <li v-for="b in preflight.blockers" :key="b" class="text-sm text-warning-800 flex gap-1.5">
              <span aria-hidden="true">•</span><span>{{ b }}</span>
            </li>
          </ul>
          <ul v-if="preflight?.warnings.length" class="mt-2 space-y-1">
            <li v-for="w in preflight.warnings" :key="w" class="text-xs text-neutral-600 flex gap-1.5">
              <span aria-hidden="true">•</span><span>{{ w }}</span>
            </li>
          </ul>
        </section>

        <!-- ── Výsledek spuštění ── -->
        <section v-if="triggerResult" class="rounded-lg border p-5"
          :class="triggerResult.status === 'queued'
            ? 'border-primary-300 bg-primary-50/40'
            : triggerResult.status === 'manual_required'
              ? 'border-neutral-300 bg-neutral-50'
              : 'border-danger-500/40 bg-danger-50/40'">
          <h2 class="text-lg font-semibold text-neutral-900">
            <template v-if="triggerResult.status === 'queued'">{{ t('myucto_upgrade.queued_title') }}</template>
            <template v-else-if="triggerResult.status === 'manual_required'">{{ t('myucto_upgrade.manual_title') }}</template>
            <template v-else>{{ t('myucto_upgrade.error_title') }}</template>
          </h2>
          <p v-if="triggerResult.message" class="text-sm text-neutral-700 mt-1.5">{{ triggerResult.message }}</p>
          <ul v-if="triggerResult.blockers?.length" class="mt-2 space-y-1">
            <li v-for="b in triggerResult.blockers" :key="b" class="text-sm text-warning-800 flex gap-1.5">
              <span aria-hidden="true">•</span><span>{{ b }}</span>
            </li>
          </ul>
          <pre v-if="triggerResult.instructions?.length"
            class="mt-3 rounded-md bg-neutral-900 text-neutral-100 p-3 text-xs leading-relaxed overflow-x-auto"><code>{{ triggerResult.instructions.join('\n') }}</code></pre>
        </section>

        <!-- ── Průběh ── -->
        <!-- ── Předání nástupci ──
             Od swapu dál se progres nemá kde číst: nejdřív brána údržby vrací
             503, pak zmizí celá routa. Bez těchhle dvou stavů by stránka
             zůstala viset na posledním kroku a vypadala jako zásek. -->
        <section v-if="handover === 'maintenance'" class="rounded-lg border border-primary-300 bg-primary-50/40 p-5">
          <h2 class="text-lg font-semibold text-neutral-900 flex items-center gap-2">
            <svg class="w-5 h-5 animate-spin text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 0 0 4.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 0 1-15.357-2m15.357 2H15"/></svg>
            {{ t('myucto_upgrade.handover_running_title') }}
          </h2>
          <p class="text-sm text-neutral-600 mt-1.5">{{ t('myucto_upgrade.handover_running_desc') }}</p>
        </section>

        <section v-else-if="handover === 'done'" class="rounded-lg border border-success-500/40 bg-success-50/50 p-5">
          <h2 class="text-lg font-semibold text-neutral-900 flex items-center gap-2">
            <svg class="w-5 h-5 text-success-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
            {{ t('myucto_upgrade.handover_done_title') }}
          </h2>
          <p class="text-sm text-neutral-600 mt-1.5">{{ t('myucto_upgrade.handover_done_desc') }}</p>
          <button type="button" @click="reloadIntoSuccessor"
            class="mt-4 inline-flex items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
            {{ t('myucto_upgrade.handover_reload') }}
          </button>
        </section>

        <section v-else-if="status.in_progress" class="rounded-lg border border-primary-300 bg-primary-50/40 p-5">
          <h2 class="text-lg font-semibold text-neutral-900 flex items-center gap-2">
            <svg class="w-5 h-5 animate-spin text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 0 0 4.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 0 1-15.357-2m15.357 2H15"/></svg>
            {{ t('myucto_upgrade.in_progress_title') }}
          </h2>
          <p class="text-sm text-neutral-600 mt-1.5">{{ t('myucto_upgrade.in_progress_desc') }}</p>

          <div v-if="status.progress" class="mt-3">
            <div class="flex items-baseline justify-between gap-3 text-sm">
              <span class="font-medium text-neutral-800">{{ status.progress.step_message }}</span>
              <span class="text-xs text-neutral-500 whitespace-nowrap">
                {{ t('myucto_upgrade.step_of', { index: status.progress.step_index, count: status.progress.step_count }) }}
              </span>
            </div>
            <div class="mt-2 h-1.5 rounded-full bg-primary-100 overflow-hidden">
              <div class="h-full bg-primary-600 transition-all duration-500"
                :style="{ width: Math.round((status.progress.step_index / Math.max(1, status.progress.step_count)) * 100) + '%' }"></div>
            </div>
            <p class="text-xs text-neutral-500 mt-2">{{ stepLabel(status.progress.step) }}</p>
          </div>

          <div class="mt-3 pt-3 border-t border-primary-200/60">
            <button type="button" @click="cancelStuck" :disabled="cancelling"
              class="text-xs text-neutral-600 hover:text-danger-600 underline decoration-dotted disabled:opacity-60">
              {{ cancelling ? t('myucto_upgrade.cancelling') : t('myucto_upgrade.cancel_stuck') }}
            </button>
          </div>
        </section>

        <!-- ── Poslední výsledek ── -->
        <section v-if="status.last_result" class="rounded-lg border p-5"
          :class="status.last_result.status === 'applied'
            ? 'border-success-500/40 bg-success-50/50'
            : 'border-danger-500/40 bg-danger-50/40'">
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ status.last_result.status === 'applied'
              ? t('myucto_upgrade.result_applied_title')
              : t('myucto_upgrade.result_failed_title') }}
          </h2>
          <p v-if="status.last_result.message" class="text-sm text-neutral-700 mt-1.5">{{ status.last_result.message }}</p>
          <div class="text-xs text-neutral-500 mt-2 space-y-0.5">
            <div v-if="status.last_result.applied_at">{{ t('myucto_upgrade.result_at') }}: {{ fmtDate(status.last_result.applied_at) }}</div>
            <div v-if="status.last_result.log_path" class="font-mono break-all">{{ status.last_result.log_path }}</div>
          </div>
        </section>
      </template>
    </div>
  </div>
</template>
