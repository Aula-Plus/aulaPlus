import { api } from "@/lib/api"
import type { GroupPerformanceTimeline, StudentPerformanceTimeline } from "@/types"

/**
 * Data layer for the student performance timeline (backend Sesión 10 —
 * docs/prompts/20-linea-tiempo-alumno.md; frontend docs/prompts/26-frontend-
 * perfil-de-alumno.md). Kept in its own module rather than piling onto
 * trackingApi.ts — same criterion as scheduledFollowUpsApi.ts (Sesión 11): a
 * separable domain does not keep growing a file that already concentrates
 * comments/alerts/approval/barriers.
 *
 * Contract verified against the real backend (StudentPerformanceTimelineController):
 * the response body is the plain object `{ results, marks }` — the controller
 * returns the collection via `->resolve()`, NOT the default `{ data: ... }`
 * envelope the rest of the tracking API uses, so there is no `.data.data`
 * unwrap here. `marks` is always present (an empty array when the viewer sees
 * none); which mark *types* it contains is decided server-side.
 */
export async function fetchStudentPerformanceTimeline(
  studentId: number,
  range?: { from?: string; to?: string; subjectId?: number },
): Promise<StudentPerformanceTimeline> {
  // Only send the params the caller actually set — an empty `from`/`to` is
  // omitted rather than sent as `?from=` (the backend treats absence as "no
  // bound", spec §1). `subject_id` narrows the line to one subject (backend
  // Sesión 4); omitted means "all subjects".
  const params: Record<string, string> = {}
  if (range?.from) params.from = range.from
  if (range?.to) params.to = range.to
  if (range?.subjectId) params.subject_id = String(range.subjectId)

  const { data } = await api.get<StudentPerformanceTimeline>(
    `/api/v1/students/${studentId}/performance-timeline`,
    Object.keys(params).length > 0 ? { params } : undefined,
  )
  return data
}

/**
 * Group performance timeline (backend Sesión 11 — docs/prompts/21-perfil-de-
 * grupo.md; frontend docs/prompts/27-frontend-perfil-de-grupo.md §4). Same
 * plain `{ results, marks }` body and same authorization as GET
 * /groups/{group}/tracking — no `{ data }` envelope, `marks` always present.
 * Every mark is an aggregate count by (type, date); no `student_id` is ever
 * returned.
 */
export async function fetchGroupPerformanceTimeline(
  groupId: number,
  range?: { from?: string; to?: string },
): Promise<GroupPerformanceTimeline> {
  const params: Record<string, string> = {}
  if (range?.from) params.from = range.from
  if (range?.to) params.to = range.to

  const { data } = await api.get<GroupPerformanceTimeline>(
    `/api/v1/groups/${groupId}/performance-timeline`,
    Object.keys(params).length > 0 ? { params } : undefined,
  )
  return data
}
