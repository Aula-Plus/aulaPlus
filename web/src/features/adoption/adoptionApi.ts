import { api } from "@/lib/api"
import type { AdoptionDashboard } from "@/types"

/**
 * Director-only pilot-adoption dashboard (docs/prompts/08-frontend-
 * seguimiento-institucional.md §3). Authorization is enforced server-side by
 * SchoolPolicy::viewAdoptionDashboard (director of that specific school); the
 * frontend only hides/gates the UI for non-directors as UX (see permissions.ts
 * → canViewAdoptionDashboard).
 */
export async function fetchAdoptionDashboard(schoolId: number): Promise<AdoptionDashboard> {
  const { data } = await api.get<{ data: AdoptionDashboard }>(
    `/api/v1/schools/${schoolId}/adoption-dashboard`,
  )
  return data.data
}
