/**
 * Pomocné formátovací funkce pro peníze, datumy a procentua.
 */

import { i18n } from '@/i18n'
import { isInvoiceDateOverdue } from '@/utils/invoiceOverdue'

// Per-currency default decimals (ISO 4217). JPY/KRW/HUF = 0, BHD/JOD = 3, ostatní 2.
// Volající může override přes parametr `decimals`.
const CURRENCY_DECIMALS: Record<string, number> = {
  JPY: 0, KRW: 0, HUF: 0, ISK: 0, CLP: 0, VND: 0,
  BHD: 3, IQD: 3, JOD: 3, KWD: 3, LYD: 3, OMR: 3, TND: 3,
}

function defaultDecimals(currency: string): number {
  return CURRENCY_DECIMALS[(currency || '').toUpperCase()] ?? 2
}

function activeLocale(): string {
  return i18n.global.locale.value === 'en' ? 'en-US' : 'cs-CZ'
}

export function formatMoney(value: number | null | undefined, currency: string = 'CZK', decimals?: number): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—'
  const dec = decimals ?? defaultDecimals(currency)
  const formatter = new Intl.NumberFormat(activeLocale(), {
    style: 'decimal',
    minimumFractionDigits: dec,
    maximumFractionDigits: dec,
  })
  const symbol = currency === 'CZK' ? 'Kč' : currency === 'EUR' ? '€' : currency
  return `${formatter.format(value)} ${symbol}`
}

export function formatDate(date: string | null | undefined): string {
  if (!date) return '—'
  const d = new Date(date)
  if (Number.isNaN(d.getTime())) return date
  return new Intl.DateTimeFormat(activeLocale(), { day: '2-digit', month: '2-digit', year: 'numeric' }).format(d)
}

export function formatMonth(yyyymm: string): string {
  const [y, m] = yyyymm.split('-').map(Number)
  if (!y || !m) return yyyymm
  const monthsCs = ['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec']
  const monthsEn = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
  const months = i18n.global.locale.value === 'en' ? monthsEn : monthsCs
  return `${months[m - 1]} ${y}`
}

export function formatPercent(value: number): string {
  return `${value.toFixed(value % 1 === 0 ? 0 : 2)} %`
}

export function statusLabel(status: string): string {
  const t = i18n.global.t
  const key = `status.${status}`
  const v = t(key)
  return v === key ? status : v
}

export function typeLabel(type: string): string {
  const t = i18n.global.t
  const key = `type.${type}`
  const v = t(key)
  return v === key ? type : v
}

/**
 * Vrací class objekt pro status badge.
 */
export function statusBadgeClass(status: string): string {
  const classes: Record<string, string> = {
    draft:     'bg-neutral-100 text-neutral-600',
    issued:    'bg-primary-100 text-primary-700',
    sent:      'bg-teal-50 text-teal-600',
    reminded:  'bg-warning-50 text-warning-600',
    paid:      'bg-success-50 text-success-600',
    cancelled: 'bg-neutral-100 text-neutral-400',
    // Odvozený platební stav (#89) — zobrazuje se místo lifecycle badge, když nese informaci.
    partially_paid: 'bg-amber-50 text-amber-700',
    overpaid:       'bg-purple-50 text-purple-700',
  }
  return classes[status] ?? 'bg-neutral-100 text-neutral-600'
}

/**
 * Badge stav k zobrazení: lifecycle status, přepsaný odvozeným platebním stavem
 * (partially_paid / overpaid), pokud nese informaci navíc (#89).
 */
export function displayStatus(status: string, paymentStatus?: string | null): string {
  if (paymentStatus === 'partially_paid') return 'partially_paid'
  if (paymentStatus === 'overpaid') return 'overpaid'
  return status
}

/**
 * Drobné barevné odlišení DUZP (tax_date), když se liší od data vystavení:
 *  - DUZP dříve než vystaveno (nižší)   → amber (text-warning-600)
 *  - DUZP později než vystaveno (vyšší) → modrá (text-accent-600)
 *  - shodné / chybějící                 → neutrální
 * Porovnává ISO řetězce 'YYYY-MM-DD' (lexikograficky = chronologicky), bez parsování.
 */
export function taxDateClass(taxDate: string | null | undefined, issueDate: string | null | undefined): string {
  if (!taxDate || !issueDate || taxDate === issueDate) return 'text-neutral-600'
  return taxDate < issueDate ? 'text-warning-600' : 'text-accent-600'
}

export function isOverdue(dueDate: string, status: string): boolean {
  if (status !== 'issued' && status !== 'sent' && status !== 'reminded') return false
  return isInvoiceDateOverdue(dueDate)
}

/**
 * Vrací CSS classy pro řádek faktury podle stavu — vizuální zvýraznění:
 *  - overdue (issued/sent + past due_date) → jemný červený podklad
 *  - paid                                  → ztlumený text (jako „hotovo")
 *  - cancelled                             → ještě více ztlumený + přeškrtnutý
 *  - jinak                                 → bez změny
 */
export function invoiceRowClass(dueDate: string, status: string): string {
  if (isOverdue(dueDate, status)) return 'bg-danger-50/60 hover:bg-danger-50'
  if (status === 'paid')      return 'bg-success-50/40 hover:bg-success-50'
  if (status === 'cancelled') return 'opacity-40 line-through hover:opacity-70'
  return ''
}
