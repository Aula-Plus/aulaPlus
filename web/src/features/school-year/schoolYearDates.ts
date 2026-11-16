/** «14/09/2026» from «2026-09-14» (as on screen 26). */
export function formatDay(iso: string | null | undefined): string {
  if (!iso) return "—"
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso)
  return match ? `${match[3]}/${match[2]}/${match[1]}` : "—"
}

/**
 * The same day one year later, as the server carries it: 29/02 becomes 28/02
 * when the next year is not a leap year.
 */
export function sameDateNextYear(iso: string): string {
  const [year, month, day] = iso.split("-").map(Number)
  const next = year + 1
  const lastDay = new Date(next, month, 0).getDate()
  const pad = (n: number) => String(n).padStart(2, "0")
  return `${next}-${pad(month)}-${pad(Math.min(day, lastDay))}`
}

