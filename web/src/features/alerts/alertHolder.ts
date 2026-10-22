import { formatShortDate } from "@/lib/utils"
import type { Alert } from "@/types"

/**
 * «la tiene X desde el dd/mm», from what the server already returned for an
 * alert the viewer can see. An alert is always held by one person: handing it
 * off transfers it (review of 14/10). `null` while nobody chose a way out.
 */
export function alertHolderPhrase(alert: Alert): string | null {
  const holder = alert.assignee?.name
  if (!alert.outcome || !holder) return null
  return `la tiene ${holder} desde el ${formatShortDate(alert.outcome_at)}`
}

/** A date as `dd/mm` (e.g. «20/10»), or "—" when absent/invalid. */
export function formatDayMonth(iso: string | null | undefined): string {
  if (!iso) return "—"
  // Date-only strings are parsed as local time, as in formatShortDate, so a
  // deadline never shifts to the previous day in negative-offset zones.
  const dateOnly = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso)
  const date = dateOnly
    ? new Date(Number(dateOnly[1]), Number(dateOnly[2]) - 1, Number(dateOnly[3]))
    : new Date(iso)
  if (Number.isNaN(date.getTime())) return "—"
  const day = String(date.getDate()).padStart(2, "0")
  const month = String(date.getMonth() + 1).padStart(2, "0")
  return `${day}/${month}`
}

/**
 * Whether the viewer has the alert now (a way out was chosen and they are the
 * one responsible) — `is_in_charge`, decided by the server. Nothing is left to
 * choose for them: it is «a tu cargo» (documento vivo screen 11; Eitán's
 * decision of 21/10, option A).
 */
export function isInChargeOfAlert(alert: Alert): boolean {
  return Boolean(alert.outcome) && alert.is_in_charge === true
}

/**
 * The label whoever received a handed-off alert reads: «A tu cargo desde el
 * 20/10 · plazo al 27/10» — their own situation, not «Pasada a otro rol»,
 * which is written from the side of whoever handed it off (decision of 21/10).
 */
export function inChargeLabel(alert: Alert): string {
  return `A tu cargo desde el ${formatDayMonth(alert.outcome_at)} · plazo al ${formatDayMonth(alert.due_on)}`
}
