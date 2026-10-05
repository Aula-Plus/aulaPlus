import { api } from "@/lib/api"
import type { GradesVisibilityMode, GradesVisibilitySettings } from "@/types"

export interface GradesVisibilityInput {
  mode: GradesVisibilityMode
  cutoff_months?: number | null
}

export async function fetchGradesVisibility(): Promise<GradesVisibilitySettings> {
  const { data } = await api.get<{ data: GradesVisibilitySettings }>("/api/v1/grades-visibility")
  return data.data
}

export async function updateGradesVisibility(
  input: GradesVisibilityInput,
): Promise<GradesVisibilitySettings> {
  const { data } = await api.put<{ data: GradesVisibilitySettings }>("/api/v1/grades-visibility", input)
  return data.data
}
