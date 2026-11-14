import { api } from "@/lib/api"

/** Which set of unit-planner fields a framework brings (`App\Enums\PlannerFamily`). */
export type PlannerFamily = "common" | "anep" | "ib_myp" | "ib_pyp" | "cambridge" | "igcse" | "ib_dp"

export type PlannerFieldType =
  | "short_text"
  | "long_text"
  | "number"
  | "list"
  | "multiple_choice"
  | "automatic"

export interface FrameworkOption {
  id: number
  name: string
  planner_family: PlannerFamily | null
  /** Part of the school's regime for the group's level: always there, cannot be removed. */
  locked: boolean
}

export interface CurricularOfferingRow {
  /** `level` is the group's education level label (null = «Sin nivel»). */
  group: { id: number; name: string; school_year: number; level: string | null }
  subject: { id: number; name: string }
  frameworks: FrameworkOption[]
  /** What the editor offers: the school's active programs for the group's level. */
  available_frameworks: FrameworkOption[]
}

/** Whether the AI reads a school field or it is only kept for the record. */
export type PlannerFieldPurpose = "ai" | "record"

export interface PlannerField {
  /** Catalog definition id (source "catalog") or the school field id (source "school"). */
  id: number
  source: "catalog" | "school"
  family: PlannerFamily | null
  key: string
  official_name: string | null
  school_name: string | null
  /** School fields only: what the field means for the school. */
  description: string | null
  purpose: PlannerFieldPurpose | null
  type: PlannerFieldType
  options: string[] | null
  required_by_program: boolean
  required_by_school: boolean
  shown: boolean
}

export interface PlannerFamilySettings {
  family: PlannerFamily
  fields: PlannerField[]
  custom_fields: PlannerField[]
}

export interface CustomFieldInput {
  name: string
  description: string | null
  purpose: PlannerFieldPurpose
  type: Exclude<PlannerFieldType, "automatic">
  /** null = every framework (shown under «Base común»). */
  family: Exclude<PlannerFamily, "common"> | null
  options?: string[]
  required_by_school: boolean
}

export async function fetchCurricularOfferings(): Promise<CurricularOfferingRow[]> {
  const { data } = await api.get<{ data: CurricularOfferingRow[] }>("/api/v1/curricular-offerings")
  return data.data
}

export async function updateCurricularOffering(
  groupId: number,
  subjectId: number,
  frameworkIds: number[],
): Promise<CurricularOfferingRow> {
  const { data } = await api.put<{ data: CurricularOfferingRow }>(
    `/api/v1/groups/${groupId}/subjects/${subjectId}/curricular-offering`,
    { curricular_framework_ids: frameworkIds },
  )
  return data.data
}

export async function fetchPlannerSettings(): Promise<PlannerFamilySettings[]> {
  const { data } = await api.get<{ data: PlannerFamilySettings[] }>("/api/v1/planner-settings")
  return data.data
}

export async function updatePlannerField(
  definitionId: number,
  input: { school_name: string | null; shown: boolean },
): Promise<PlannerFamilySettings[]> {
  const { data } = await api.put<{ data: PlannerFamilySettings[] }>(
    `/api/v1/planner-settings/fields/${definitionId}`,
    input,
  )
  return data.data
}

export async function createCustomField(input: CustomFieldInput): Promise<PlannerFamilySettings[]> {
  const { data } = await api.post<{ data: PlannerFamilySettings[] }>(
    "/api/v1/planner-settings/custom-fields",
    input,
  )
  return data.data
}

export async function deleteCustomField(fieldId: number): Promise<PlannerFamilySettings[]> {
  const { data } = await api.delete<{ data: PlannerFamilySettings[] }>(
    `/api/v1/planner-settings/custom-fields/${fieldId}`,
  )
  return data.data
}
