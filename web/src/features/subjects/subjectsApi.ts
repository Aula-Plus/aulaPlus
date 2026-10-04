import { api } from "@/lib/api"
import type { GroupTeacherAssignment, Subject } from "@/types"

/**
 * Data layer for the subjects ("materias") feature (backend Sesiones 1–2):
 * the school subject catalog CRUD plus per-group teacher-subject assignments.
 * Resource responses are `{ data: ... }` wrapped, matching groupsApi/assessmentsApi.
 */

// The teacher-options endpoint (`GET /api/teachers`, TeacherOptionsController)
// already has a data-layer helper in groupsApi. Re-export it so the assignment
// panel imports its teacher source from one place instead of re-deriving it.
export { fetchTeachers } from "@/features/groups/groupsApi"
export type { Teacher } from "@/features/groups/groupsApi"

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

/** Upload or replace the subject's curricular program PDF (ClickUp 86e3dt6ag). */
export async function uploadSyllabus(id: number, file: File): Promise<Subject> {
  const form = new FormData()
  form.append("file", file)
  const { data } = await api.post<{ data: Subject }>(`/api/v1/subjects/${id}/syllabus`, form)
  return data.data
}

export async function deleteSyllabus(id: number): Promise<Subject> {
  const { data } = await api.delete<{ data: Subject }>(`/api/v1/subjects/${id}/syllabus`)
  return data.data
}

/** A short-lived URL to open the program PDF (never stored). */
export async function fetchSyllabusUrl(id: number): Promise<string> {
  const { data } = await api.get<{ data: { url: string } }>(`/api/v1/subjects/${id}/syllabus`)
  return data.data.url
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
