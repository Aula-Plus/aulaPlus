import type { PlanUnit } from "./annualPlansApi"

/** Day index of a YYYY-MM-DD date (local, no timezone shift). */
function dayOf(iso: string): number {
  const [y, m, d] = iso.split("-").map(Number)
  return Date.UTC(y, m - 1, d) / 86_400_000
}

/**
 * Pairs of units whose periods overlap. The screen warns and never blocks
 * («Se superponen: Unidades 5 y 6. Puede ser a propósito»).
 */
export function overlappingUnits(units: PlanUnit[]): [PlanUnit, PlanUnit][] {
  const pairs: [PlanUnit, PlanUnit][] = []
  for (let i = 0; i < units.length; i++) {
    for (let j = i + 1; j < units.length; j++) {
      const a = units[i]
      const b = units[j]
      if (dayOf(a.start_date) <= dayOf(b.end_date) && dayOf(b.start_date) <= dayOf(a.end_date)) {
        pairs.push([a, b])
      }
    }
  }
  return pairs
}

/**
 * Where a unit's bar sits on the year line, as percentages of the plan's
 * calendar year (clamped so a unit spilling into the next year still shows).
 */
export function barPosition(unit: PlanUnit, year: number): { left: number; width: number } {
  const start = Date.UTC(year, 0, 1) / 86_400_000
  const end = Date.UTC(year, 11, 31) / 86_400_000
  const span = end - start
  const clamp = (v: number) => Math.min(Math.max(v, 0), span)
  const from = clamp(dayOf(unit.start_date) - start)
  const to = clamp(dayOf(unit.end_date) - start)
  return { left: (from / span) * 100, width: Math.max(((to - from) / span) * 100, 0.8) }
}

/** Share of a unit already given (its objectives), 0–100. */
export function givenShare(unit: PlanUnit): number {
  return unit.objectives_count === 0 ? 0 : Math.round((unit.given_count / unit.objectives_count) * 100)
}
