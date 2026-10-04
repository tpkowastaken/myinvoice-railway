import assert from 'node:assert/strict'
import { registerHooks } from 'node:module'
import test from 'node:test'
import { computed } from 'vue'

// Stejné aliasy jako Vite; překlady nejsou součástí těchto testů kalendáře.
const hooks = registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier === '@/i18n') {
      return nextResolve('data:text/javascript,export const i18n = {}', context)
    }
    if (specifier.startsWith('@/')) {
      return nextResolve(new URL(`../src/${specifier.slice(2)}.ts`, import.meta.url).href, context)
    }
    return nextResolve(specifier, context)
  },
})
const { overdueDays, appIsoDate, setAppTimeZone } = await import('../src/utils/date.ts')
const { isInvoiceDateOverdue, setOverdueIncludesToday } = await import('../src/utils/invoiceOverdue.ts')
const { isOverdue } = await import('../src/composables/useFormat.ts')
hooks.deregister()

for (const [timezone, expectedDate, expectedDays] of [
  ['UTC', '2026-09-07', 0],
  ['Europe/Prague', '2026-09-08', 1],
  ['America/New_York', '2026-09-07', 0],
  ['Asia/Tokyo', '2026-09-08', 1],
]) {
  test(`kalendář respektuje nastavené pásmo ${timezone}`, (t) => {
    t.after(() => setAppTimeZone('Europe/Prague'))
    t.mock.timers.enable({ apis: ['Date'], now: new Date('2026-09-07T22:30:00Z') })
    const overdue = computed(() => isInvoiceDateOverdue('2026-09-07'))
    assert.equal(overdue.value, true)
    setAppTimeZone(timezone)
    assert.equal(appIsoDate(), expectedDate)
    assert.equal(overdueDays('2026-09-07'), expectedDays)
    assert.equal(overdue.value, expectedDays > 0)
  })
}

for (const timezone of ['UTC', 'Europe/Prague', 'America/New_York', 'Asia/Tokyo']) {
  for (const includesToday of [false, true]) {
    for (const now of ['2026-09-06T22:00:00Z', '2026-09-07T12:00:00Z', '2026-09-07T21:59:59Z']) {
      test(`splatnost: ${timezone}, includesToday=${includesToday}, ${now}`, (t) => {
        const previousTimezone = process.env.TZ
        process.env.TZ = timezone
        t.after(() => {
          if (previousTimezone === undefined) delete process.env.TZ
          else process.env.TZ = previousTimezone
          setOverdueIncludesToday(false)
        })
        t.mock.timers.enable({ apis: ['Date'], now: new Date(now) })
        setOverdueIncludesToday(includesToday)
        for (const status of ['issued', 'sent', 'reminded']) {
          assert.equal(isOverdue('2026-09-06', status), true)
          assert.equal(isOverdue('2026-09-07', status), includesToday)
          assert.equal(isOverdue('2026-09-08', status), false)
        }
        for (const status of ['draft', 'paid', 'cancelled']) {
          assert.equal(isOverdue('2026-09-06', status), false)
        }
        assert.equal(overdueDays('2026-09-07'), 0, 'Dnešní doklad nelze upomínat ani v režimu true.')
      })
    }
  }
}

for (const [due, now, expected] of [
  ['2026-09-07', '2026-09-07T21:59:59Z', 0],
  ['2026-09-07', '2026-09-07T22:00:00Z', 1],
  ['2026-03-29', '2026-03-29T22:00:00Z', 1],
  ['2026-10-25', '2026-10-25T23:00:00Z', 1],
  ['2026-12-31', '2026-12-31T23:00:00Z', 1],
  ['2028-02-28', '2028-03-01T12:00:00Z', 2],
  ['2026-09-08', '2026-09-07T12:00:00Z', 0],
  ['', '2026-09-07T12:00:00Z', 0],
]) {
  test(`dny prodlení: ${due}, ${now}`, () => {
    assert.equal(overdueDays(due, new Date(now)), expected)
  })
}

test('načtení příznaku reaktivně změní zobrazení', (t) => {
  t.mock.timers.enable({ apis: ['Date'], now: new Date('2026-09-07T12:00:00Z') })
  t.after(() => setOverdueIncludesToday(false))
  const overdue = computed(() => isInvoiceDateOverdue('2026-09-07'))
  assert.equal(overdue.value, false)
  setOverdueIncludesToday(true)
  assert.equal(overdue.value, true)
  setOverdueIncludesToday(false)
  assert.equal(overdue.value, false)
})
