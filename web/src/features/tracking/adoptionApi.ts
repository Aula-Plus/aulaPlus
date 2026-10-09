import { api } from "@/lib/api"
import type { AdoptionDashboard, AdoptionTeacherRow, LastLoginRange } from "@/types"

/**
 * Director-only pilot-adoption dashboard (docs/prompts/04-seguimiento-
 * institucional.md §5). Authorization is enforced server-side by
 * SchoolPolicy::viewAdoptionDashboard (director of that specific school); the
 * frontend only hides the UI for non-directors as UX (see permissions.ts).
 */
export async function fetchAdoptionDashboard(schoolId: number): Promise<AdoptionDashboard> {
  const { data } = await api.get<{ data: AdoptionDashboard }>(
    `/api/v1/schools/${schoolId}/adoption-dashboard`,
  )
  return data.data
}

/** Director-only "Por docente" tab: alphabetical per-teacher counts. */
export async function fetchAdoptionTeachers(schoolId: number): Promise<AdoptionTeacherRow[]> {
  const { data } = await api.get<{ data: AdoptionTeacherRow[] }>(
    `/api/v1/schools/${schoolId}/adoption-dashboard/teachers`,
  )
  return data.data
}

/** On-demand "Ver detalles de uso": the teacher's last login as a coarse range. */
export async function fetchTeacherLastLoginRange(
  schoolId: number,
  teacherId: number,
): Promise<LastLoginRange> {
  const { data } = await api.get<{ data: { last_login_range: LastLoginRange } }>(
    `/api/v1/schools/${schoolId}/adoption-dashboard/teachers/${teacherId}/usage`,
  )
  return data.data.last_login_range
}
