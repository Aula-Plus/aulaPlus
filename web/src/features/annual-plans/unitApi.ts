import { api } from "@/lib/api"
import type { AccommodationCategory } from "@/types"
import type { PlanUnit, PlanObjective } from "./annualPlansApi"

/**
 * Data layer for screen 13 of the living document — Unidad (ClickUp
 * 1dfd3mc4bga). The teacher fills the unit planner (no field is mandatory,
 * P13-DEC-07) and links each objective to curricular items of the program
 * catalog (P13-DEC-05); Psicopedagogía and Dirección read. The unit status
 * comes derived from the server, as in the Programa anual.
 */

export type PlannerFieldType =
  | "short_text"
  | "long_text"
  | "number"
  | "list"
  | "multiple_choice"
  | "automatic"

export type PlannerValue = string | number | string[] | null

export interface PlannerField {
  id: number
  source: "catalog" | "school"
  family: string | null
  key: string
  official_name: string | null
  school_name: string | null
  description: string | null
  type: PlannerFieldType | null
  options: string[] | null
  required_by_program: boolean
  value: PlannerValue
}

export interface UnitPlanner {
  frameworks: { id: number; name: string; planner_family: string | null }[]
  fields: PlannerField[]
}

/** A catalog element as it is shown and cited («con su fuente y página»). */
export interface CurricularItemRow {
  id: number
  code: string
  name: string
  type: string
  description: string | null
  parent_name: string | null
  catalog: string | null
  framework: string | null
  source: string | null
  page: string | null
}

export interface UnitObjective extends PlanObjective {
  curricular_items: CurricularItemRow[]
}

interface NamedRef {
  id: number | null
  name: string | null
}

export interface UnitDetail extends Omit<PlanUnit, "objectives"> {
  objectives: UnitObjective[]
  plan: {
    id: number
    year: number
    group: NamedRef
    subject: NamedRef
    teacher: NamedRef
    framework: NamedRef
  }
  planner: UnitPlanner
  can_edit: boolean
}

/** «A mano» and «Diferenciación»: the group's accommodations by type. */
export interface UnitAccommodationType {
  type: string
  category: AccommodationCategory | null
  student_count: number
  students: { id: number; full_name: string | null }[]
}

export async function fetchUnit(id: number): Promise<UnitDetail> {
  const { data } = await api.get<{ data: UnitDetail }>(`/api/v1/units/${id}`)
  return data.data
}

export async function saveUnitPlanner(
  id: number,
  values: Record<string, PlannerValue>,
): Promise<UnitPlanner> {
  const { data } = await api.patch<{ data: UnitPlanner }>(`/api/v1/units/${id}/planner`, { values })
  return data.data
}

export async function fetchUnitAccommodations(id: number): Promise<UnitAccommodationType[]> {
  const { data } = await api.get<{ data: UnitAccommodationType[] }>(`/api/v1/units/${id}/accommodations`)
  return data.data
}

export async function fetchUnitCurricularItems(id: number): Promise<CurricularItemRow[]> {
  const { data } = await api.get<{ data: CurricularItemRow[] }>(`/api/v1/units/${id}/curricular-items`)
  return data.data
}

export async function saveObjectiveCurricularItems(objectiveId: number, ids: number[]): Promise<void> {
  await api.put(`/api/v1/unit-objectives/${objectiveId}/curricular-items`, { curricular_item_ids: ids })
}

/** The label a planner field shows: the school's name, else the official one. */
export function fieldLabel(field: PlannerField): string {
  return field.school_name || field.official_name || field.key
}

/** «Fuente, página» when the catalog carries them, else the catalog. */
export function citation(item: CurricularItemRow): string {
  const where = [item.framework, item.catalog].filter(Boolean).join(" · ")
  const source = [item.source, item.page ? `p. ${item.page}` : null].filter(Boolean).join(", ")
  return [where, source].filter(Boolean).join(" — ")
}
