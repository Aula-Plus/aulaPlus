/**
 * Pure date helpers for the Calendar. Every day is a school-local
 * `YYYY-MM-DD` string; dates are built at local noon so no timezone shift can
 * move a day (same concern as `formatShortDate`).
 */

export type CalendarView = "month" | "week" | "list"

export function parseDay(iso: string): Date {
  const [y, m, d] = iso.split("-").map(Number)
  return new Date(y, m - 1, d, 12)
}

export function toDay(date: Date): string {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, "0")
  const d = String(date.getDate()).padStart(2, "0")
  return `${y}-${m}-${d}`
}

export function addDays(iso: string, days: number): string {
  const date = parseDay(iso)
  date.setDate(date.getDate() + days)
  return toDay(date)
}

/** The Monday of the week the day falls in (weeks start on Monday). */
export function mondayOf(iso: string): string {
  const weekday = (parseDay(iso).getDay() + 6) % 7
  return addDays(iso, -weekday)
}

export function firstOfMonth(iso: string): string {
  return `${iso.slice(0, 7)}-01`
}

export function addMonths(iso: string, months: number): string {
  const date = parseDay(firstOfMonth(iso))
  date.setMonth(date.getMonth() + months)
  return toDay(date)
}

function lastOfMonth(iso: string): string {
  return addDays(addMonths(iso, 1), -1)
}

/**
 * The days a view shows around an anchor day: the month grid (whole weeks,
 * Monday to Sunday), the week, or — for the list — the month itself.
 */
export function rangeFor(view: CalendarView, anchor: string): { from: string; to: string } {
  if (view === "week") {
    const from = mondayOf(anchor)
    return { from, to: addDays(from, 6) }
  }
  if (view === "list") {
    return { from: firstOfMonth(anchor), to: lastOfMonth(anchor) }
  }
  const from = mondayOf(firstOfMonth(anchor))
  const to = addDays(mondayOf(lastOfMonth(anchor)), 6)
  return { from, to }
}

/** Every day between two days, inclusive. */
export function daysBetween(from: string, to: string): string[] {
  const days: string[] = []
  for (let day = from; day <= to; day = addDays(day, 1)) days.push(day)
  return days
}

/** Move the anchor one step back/forward in the given view. */
export function shiftAnchor(view: CalendarView, anchor: string, step: -1 | 1): string {
  return view === "week" ? addDays(anchor, 7 * step) : addMonths(anchor, step)
}

const MONTHS = [
  "enero",
  "febrero",
  "marzo",
  "abril",
  "mayo",
  "junio",
  "julio",
  "agosto",
  "setiembre",
  "octubre",
  "noviembre",
  "diciembre",
]

export const WEEKDAY_SHORT = ["Lun", "Mar", "Mié", "Jue", "Vie", "Sáb", "Dom"]

/** «Diciembre 2026». */
export function monthTitle(iso: string): string {
  const name = MONTHS[parseDay(iso).getMonth()]
  return `${name.charAt(0).toUpperCase()}${name.slice(1)} ${iso.slice(0, 4)}`
}

/** «15/12». */
export function dayMonth(iso: string): string {
  return `${iso.slice(8, 10)}/${iso.slice(5, 7)}`
}

/** «Martes 15/12». */
export function weekdayDayMonth(iso: string): string {
  const names = ["Domingo", "Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado"]
  return `${names[parseDay(iso).getDay()]} ${dayMonth(iso)}`
}

export function viewTitle(view: CalendarView, anchor: string): string {
  if (view === "week") {
    const from = mondayOf(anchor)
    return `Semana del ${dayMonth(from)} al ${dayMonth(addDays(from, 6))}`
  }
  return monthTitle(anchor)
}
