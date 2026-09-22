import { clsx, type ClassValue } from "clsx"
import { twMerge } from "tailwind-merge"

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

/** Format an ISO date string as a short Spanish date, or "—" when absent/invalid. */
export function formatShortDate(iso: string | null | undefined): string {
  if (!iso) return "—"
  // Date-only strings (YYYY-MM-DD) are parsed as UTC midnight by `new Date`, which
  // shifts to the previous day in negative-offset zones (e.g. UTC-3). Parse those as
  // local time so a due date of 2026-09-16 renders as the 16th, not the 15th.
  const dateOnly = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso)
  const date = dateOnly
    ? new Date(Number(dateOnly[1]), Number(dateOnly[2]) - 1, Number(dateOnly[3]))
    : new Date(iso)
  return Number.isNaN(date.getTime())
    ? "—"
    : date.toLocaleDateString("es", { day: "2-digit", month: "short", year: "numeric" })
}
