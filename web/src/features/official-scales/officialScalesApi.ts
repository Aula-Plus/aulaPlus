import { api } from "@/lib/api"
import type { NumericGradeSettings, OfficialScale } from "@/types"

/**
 * Screen 24 · «Escalas oficiales» (ClickUp 1dfd3mc4bgg). The scales are
 * read-only — there is no write endpoint; only the school's numeric-grade
 * settings are saved.
 */
export async function fetchOfficialScales(): Promise<OfficialScale[]> {
  const { data } = await api.get<{ data: OfficialScale[] }>("/api/v1/official-scales")
  return data.data
}

export async function fetchNumericGradeSettings(): Promise<NumericGradeSettings> {
  const { data } = await api.get<{ data: NumericGradeSettings }>("/api/v1/numeric-grade-settings")
  return data.data
}

export interface NumericGradeSettingsInput {
  show_numeric_grade: boolean
  /** Empty = no equivalence; otherwise one number 1..10 per level of the Primaria scale. */
  equivalences: { level_id: number; numeric_grade: number }[]
}

export async function updateNumericGradeSettings(
  input: NumericGradeSettingsInput,
): Promise<NumericGradeSettings> {
  const { data } = await api.put<{ data: NumericGradeSettings }>(
    "/api/v1/numeric-grade-settings",
    input,
  )
  return data.data
}
