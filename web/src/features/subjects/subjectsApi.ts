import { api } from "@/lib/api"
import type { GroupTeacherAssignment, Subject } from "@/types"

/**
 * Data layer for the subjects ("materias") feature (backend Sesiones 1–2):
 * the school subject catalog CRUD plus per-group teacher-subject assignments.
 * Resource responses are `{ data: ... }` wrapped, matching groupsApi/assessmentsApi.
 */

export interface SubjectInput {
  name: string
  short_code?: string | null
  color?: string | null
}

export async function fetchSubjects(): Promise<Subject[]> {
  const { data } = await api.get<{ data: Subject[] }>("/api/v1/subjects")
  return data.data
}

export async function createSubject(input: SubjectInput): Promise<Subject> {
  const { data } = await api.post<{ data: Subject }>("/api/v1/subjects", input)
  return data.data
}

export async function updateSubject(id: number, input: Partial<SubjectInput>): Promise<Subject> {
  const { data } = await api.patch<{ data: Subject }>(`/api/v1/subjects/${id}`, input)
  return data.data
}

export async function deleteSubject(id: number): Promise<void> {
  await api.delete(`/api/v1/subjects/${id}`)
}

export async function fetchGroupAssignments(groupId: number): Promise<GroupTeacherAssignment[]> {
  const { data } = await api.get<{ data: GroupTeacherAssignment[] }>(
    `/api/v1/groups/${groupId}/teacher-assignments`,
  )
  return data.data
}

export async function createGroupAssignment(
  groupId: number,
  input: { teacher_id: number; subject_id: number },
): Promise<void> {
  await api.post(`/api/v1/groups/${groupId}/teacher-assignments`, input)
}

export async function deleteGroupAssignment(
  groupId: number,
  input: { teacher_id: number; subject_id: number },
): Promise<void> {
  // axios sends a DELETE body via the `data` option.
  await api.delete(`/api/v1/groups/${groupId}/teacher-assignments`, { data: input })
}
