import { api } from "@/lib/api"

/**
 * Data layer for screen 12 of the living document — Programa anual
 * (ClickUp 1dfd3mc4bg8). The teacher builds the plan, its units and their
 * objectives; Psicopedagogía and Dirección read it. The status of each unit
 * and the coverage counters come computed from the server (they are never
 * chosen by hand, P12-DEC-03).
 */

export type UnitStatus = "unplanned" | "planned" | "given"

export const unitStatusLabels: Record<UnitStatus, string> = {
  unplanned: "Sin planificar",
  planned: "Planificada",
  given: "Dada",
}

interface NamedRef {
  id: number | null
  name: string | null
}

export interface AnnualPlanSummary {
  id: number
  year: number
  group: NamedRef
  subject: NamedRef
  teacher: NamedRef
  framework: NamedRef
  units_count: number
}

/**
 * Derived per objective by the server. «started» (empezado) means a class
 * recorded «una parte»: it is shown, but it never counts as given
 * (P12-UNI-01, P12-COB-02).
 */
export type ObjectiveStatus = "given" | "started" | "planned" | "unplanned"

export const objectiveStatusLabels: Record<ObjectiveStatus, string> = {
  given: "Dado",
  started: "Empezado",
  planned: "Con clase o evaluación",
  unplanned: "Sin planificar",
}

/** The class where «una parte» was last recorded — what «Lo terminé» opens. */
export interface ClassToClose {
  id: number
  date: string | null
  title: string | null
}

export interface PlanObjective {
  id: number
  description: string
  position: number
  planned: boolean
  given: boolean
  status: ObjectiveStatus
  class_to_close: ClassToClose | null
}

export interface PlanUnit {
  id: number
  name: string
  position: number
  start_date: string
  end_date: string
  /** null when the unit has no objectives written yet: it shows no state. */
  status: UnitStatus | null
  objectives_count: number
  planned_count: number
  given_count: number
  started_count: number
  objectives: PlanObjective[]
}

export interface PlanCoverage {
  units_total: number
  units_given: number
  objectives_total: number
  objectives_planned: number
  objectives_given: number
  objectives_unplanned: number
}

export interface PlanMark {
  kind: "class" | "assessment"
  id: number
  date: string | null
  title: string | null
  unit_id: number | null
}

export interface AnnualPlanDetail extends AnnualPlanSummary {
  description: string
  can_edit: boolean
  units: PlanUnit[]
  coverage: PlanCoverage
  marks: PlanMark[]
}

export interface AnnualPlanOptions {
  assignments: { group_id: number; group_name: string; subject_id: number; subject_name: string }[]
  frameworks: { id: number; name: string }[]
}

export interface NewAnnualPlan {
  group_id: number
  subject_id: number
  curricular_framework_id: number
  year: number
  description?: string
}

export interface UnitInput {
  name: string
  start_date: string
  end_date: string
}

export async function fetchAnnualPlans(): Promise<AnnualPlanSummary[]> {
  const { data } = await api.get<{ data: AnnualPlanSummary[] }>("/api/v1/annual-plans")
  return data.data
}

export async function fetchAnnualPlanOptions(): Promise<AnnualPlanOptions> {
  const { data } = await api.get<{ data: AnnualPlanOptions }>("/api/v1/annual-plans/options")
  return data.data
}

export async function fetchAnnualPlan(id: number): Promise<AnnualPlanDetail> {
  const { data } = await api.get<{ data: AnnualPlanDetail }>(`/api/v1/annual-plans/${id}`)
  return data.data
}

export async function createAnnualPlan(input: NewAnnualPlan): Promise<AnnualPlanDetail> {
  const { data } = await api.post<{ data: AnnualPlanDetail }>("/api/v1/annual-plans", input)
  return data.data
}

export async function updateAnnualPlan(
  id: number,
  input: { description?: string },
): Promise<AnnualPlanDetail> {
  const { data } = await api.patch<{ data: AnnualPlanDetail }>(`/api/v1/annual-plans/${id}`, input)
  return data.data
}

export async function createUnit(planId: number, input: UnitInput): Promise<void> {
  await api.post(`/api/v1/annual-plans/${planId}/units`, input)
}

export async function updateUnit(unitId: number, input: Partial<UnitInput>): Promise<void> {
  await api.patch(`/api/v1/units/${unitId}`, input)
}

export async function deleteUnit(unitId: number): Promise<void> {
  await api.delete(`/api/v1/units/${unitId}`)
}

export async function createObjective(unitId: number, description: string): Promise<void> {
  await api.post(`/api/v1/units/${unitId}/objectives`, { description })
}

export async function updateObjective(objectiveId: number, description: string): Promise<void> {
  await api.patch(`/api/v1/unit-objectives/${objectiveId}`, { description })
}

export async function deleteObjective(objectiveId: number): Promise<void> {
  await api.delete(`/api/v1/unit-objectives/${objectiveId}`)
}

export type ObjectiveCoverage = "full" | "partial" | "none"

/**
 * Corrects what a closed class recorded for an objective («Lo terminé»:
 * «una parte» → «todo»). The objective's state still derives from the class.
 */
export async function updateClassObjectiveCoverage(
  classSessionId: number,
  objectiveId: number,
  coverage: ObjectiveCoverage,
): Promise<void> {
  await api.patch(`/api/v1/class-sessions/${classSessionId}/objectives/${objectiveId}`, { coverage })
}
