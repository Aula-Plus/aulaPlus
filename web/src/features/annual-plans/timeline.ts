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
  const from = datePosition(unit.start_date, year)
  const to = datePosition(unit.end_date, year)
  return { left: from, width: Math.max(to - from, 0.8) }
}

/**
 * Where a single day sits on the year line, 0–100 (same math as the bars, so
 * the marks of classes, assessments and «hoy» line up with them). Clamped to
 * the plan's year.
 */
export function datePosition(iso: string, year: number): number {
  const start = Date.UTC(year, 0, 1) / 86_400_000
  const end = Date.UTC(year, 11, 31) / 86_400_000
  const span = end - start
  const offset = Math.min(Math.max(dayOf(iso) - start, 0), span)
  return (offset / span) * 100
}

/** Whether a YYYY-MM-DD date falls inside the plan's calendar year. */
export function inYear(iso: string, year: number): boolean {
  return Number(iso.slice(0, 4)) === year
}

/** Today as YYYY-MM-DD in local time. */
export function todayIso(now: Date = new Date()): string {
  const pad = (n: number) => String(n).padStart(2, "0")
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}

/** Share of a unit already given (its objectives), 0–100. */
export function givenShare(unit: PlanUnit): number {
  return unit.objectives_count === 0 ? 0 : Math.round((unit.given_count / unit.objectives_count) * 100)
}

/**
 * Share of a unit started but not given («empezado»), 0–100. Drawn as a
 * lighter fill after the given one; it never counts as given.
 */
export function startedShare(unit: PlanUnit): number {
  return unit.objectives_count === 0 ? 0 : Math.round((unit.started_count / unit.objectives_count) * 100)
}

function plural(n: number, one: string, many: string): string {
  return `${n} ${n === 1 ? one : many}`
}

/** «3 de 4 · 1 empezado» for the Unidades tab («Dados» column). */
export function givenLabel(unit: PlanUnit): string {
  const base = `${unit.given_count} de ${unit.objectives_count}`
  return unit.started_count > 0 ? `${base} · ${plural(unit.started_count, "empezado", "empezados")}` : base
}

/**
 * Tooltip of a unit on the Cronograma: «4 objetivos · 2 dados · 1 empezado ·
 * 1 sin planificar». Objectives with a class or assessment built but not
 * started yet are added as «planificados» only when there are any, so the
 * parts always add up to the total.
 */
export function unitSummary(unit: PlanUnit): string {
  if (unit.objectives_count === 0) return "Sin objetivos escritos todavía"
  const plannedOnly = Math.max(unit.planned_count - unit.given_count - unit.started_count, 0)
  const unplanned = Math.max(unit.objectives_count - unit.planned_count, 0)
  return [
    plural(unit.objectives_count, "objetivo", "objetivos"),
    plural(unit.given_count, "dado", "dados"),
    plural(unit.started_count, "empezado", "empezados"),
    ...(plannedOnly > 0 ? [plural(plannedOnly, "planificado", "planificados")] : []),
    `${unplanned} sin planificar`,
  ].join(" · ")
}
