import { clsx, type ClassValue } from "clsx"
import { twMerge } from "tailwind-merge"

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

/** Format an ISO date string as a short Spanish date, or "—" when absent/invalid. */
export function formatShortDate(iso: string | null | undefined): string {
  if (!iso) return "—"
  const date = new Date(iso)
  return Number.isNaN(date.getTime())
    ? "—"
    : date.toLocaleDateString("es", { day: "2-digit", month: "short", year: "numeric" })
}
