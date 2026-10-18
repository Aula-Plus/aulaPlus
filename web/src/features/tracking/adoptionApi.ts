import { api } from "@/lib/api"
import type {
  AdoptionDashboard,
  AdoptionTeacherRow,
  CreationJourney,
  JourneyDataSince,
  LastLoginRange,
} from "@/types"

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

export interface AdoptionTeachers {
  rows: AdoptionTeacherRow[]
  dataSince: JourneyDataSince
}

/** Director-only "Por docente" tab: alphabetical per-teacher counts. */
export async function fetchAdoptionTeachers(schoolId: number): Promise<AdoptionTeachers> {
  const { data } = await api.get<{
    data: AdoptionTeacherRow[]
    meta: { data_since: JourneyDataSince }
  }>(`/api/v1/schools/${schoolId}/adoption-dashboard/teachers`)
  return { rows: data.data, dataSince: data.meta.data_since }
}

/**
 * Director-only "Ver recorridos": the creation journeys behind one teacher's
 * counts for the current month, each step with its date and time.
 */
export async function fetchTeacherJourneys(
  schoolId: number,
  teacherId: number,
): Promise<CreationJourney[]> {
  const { data } = await api.get<{ data: CreationJourney[] }>(
    `/api/v1/schools/${schoolId}/adoption-dashboard/teachers/${teacherId}/journeys`,
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
