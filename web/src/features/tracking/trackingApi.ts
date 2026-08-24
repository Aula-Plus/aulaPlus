import { api } from "@/lib/api"
import type { Alert, Comment, CommentTone, GroupTracking, Role, StudentTracking } from "@/types"

/**
 * Data layer for the institutional tracking feature (Sesión 8), talking to the
 * versioned backend endpoints under `/api/v1` (docs/prompts/04-seguimiento-
 * institucional.md). Every response is wrapped in `{ data: ... }` by the
 * Laravel Resources, matching the convention already used by studentsApi /
 * groupsApi. The CSRF cookie is established once at login (see authApi), so —
 * like those modules — the mutations here do not fetch it again.
 */

export interface CommentInput {
  content: string
  tone?: CommentTone | null
  /**
   * Roles allowed to see the comment.
   *
   * IMPORTANT (docs/prompts/08 §3): when the author selects no roles, this
   * field must be OMITTED or `null` — never `[]`. The backend treats an empty
   * array as "visible to nobody", which would hide the comment even from its
   * own author. Callers build the payload conditionally (see CommentsPanel);
   * `undefined` here is dropped from the JSON by axios, so omission is the
   * default, safe behaviour.
   */
  visible_to?: Role[] | null
}

export async function fetchStudentTracking(studentId: number): Promise<StudentTracking> {
  const { data } = await api.get<{ data: StudentTracking }>(
    `/api/v1/students/${studentId}/tracking`,
  )
  return data.data
}

export async function fetchGroupTracking(groupId: number): Promise<GroupTracking> {
  const { data } = await api.get<{ data: GroupTracking }>(`/api/v1/groups/${groupId}/tracking`)
  return data.data
}

export async function fetchStudentComments(studentId: number): Promise<Comment[]> {
  const { data } = await api.get<{ data: Comment[] }>(`/api/v1/students/${studentId}/comments`)
  return data.data
}

export async function createStudentComment(
  studentId: number,
  input: CommentInput,
): Promise<Comment> {
  const { data } = await api.post<{ data: Comment }>(
    `/api/v1/students/${studentId}/comments`,
    input,
  )
  return data.data
}

export async function fetchGroupComments(groupId: number): Promise<Comment[]> {
  const { data } = await api.get<{ data: Comment[] }>(`/api/v1/groups/${groupId}/comments`)
  return data.data
}

export async function createGroupComment(groupId: number, input: CommentInput): Promise<Comment> {
  const { data } = await api.post<{ data: Comment }>(`/api/v1/groups/${groupId}/comments`, input)
  return data.data
}

export async function fetchStudentAlerts(studentId: number): Promise<Alert[]> {
  const { data } = await api.get<{ data: Alert[] }>(`/api/v1/students/${studentId}/alerts`)
  return data.data
}

export async function fetchGroupAlerts(groupId: number): Promise<Alert[]> {
  const { data } = await api.get<{ data: Alert[] }>(`/api/v1/groups/${groupId}/alerts`)
  return data.data
}

export async function resolveAlert(alertId: number): Promise<Alert> {
  const { data } = await api.post<{ data: Alert }>(`/api/v1/alerts/${alertId}/resolve`)
  return data.data
}
