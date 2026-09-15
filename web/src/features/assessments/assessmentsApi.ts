import { api } from "@/lib/api"
import type { Assessment, AssessmentResult, AssessmentType } from "@/types"

/**
 * Data layer for the assessments feature (Sesión 10), talking to the versioned
 * backend endpoints under `/api/v1` (docs/prompts/13-evaluaciones-resultados.md,
 * backend Sesión 6). Every response is wrapped in `{ data: ... }` by the Laravel
 * Resources, matching the convention already used by groupsApi / trackingApi.
 * The CSRF cookie is established once at login (see authApi), so — like those
 * modules — the mutations here do not fetch it again.
 */

/** Payload for creating an assessment (the plain, non-AI form of this session). */
export interface AssessmentInput {
  type: AssessmentType
  /** The subject ("materia") the assessment belongs to (backend Sesión 3). */
  subject_id: number
  /** ISO date (`YYYY-MM-DD`). */
  administered_at: string
  purpose?: string | null
  duration_minutes?: number | null
}

/** One row of the results upsert batch. */
export interface AssessmentResultInput {
  student_id: number
  score: number
  feedback?: string | null
}

export async function fetchAssessments(groupId: number): Promise<Assessment[]> {
  const { data } = await api.get<{ data: Assessment[] }>(`/api/v1/groups/${groupId}/assessments`)
  return data.data
}

export async function createAssessment(
  groupId: number,
  input: AssessmentInput,
): Promise<Assessment> {
  const { data } = await api.post<{ data: Assessment }>(
    `/api/v1/groups/${groupId}/assessments`,
    input,
  )
  return data.data
}

export async function updateAssessment(
  assessmentId: number,
  input: Partial<AssessmentInput>,
): Promise<Assessment> {
  const { data } = await api.patch<{ data: Assessment }>(
    `/api/v1/assessments/${assessmentId}`,
    input,
  )
  return data.data
}

export async function deleteAssessment(assessmentId: number): Promise<void> {
  await api.delete(`/api/v1/assessments/${assessmentId}`)
}

export async function fetchAssessmentResults(assessmentId: number): Promise<AssessmentResult[]> {
  const { data } = await api.get<{ data: AssessmentResult[] }>(
    `/api/v1/assessments/${assessmentId}/results`,
  )
  return data.data
}

/**
 * Upsert every entered result in a single request. The backend keys the batch
 * by (assessment_id, student_id) and updates in place, so re-saving a student's
 * score never duplicates a row (docs/prompts/13 §2). This is deliberately ONE
 * POST carrying the whole array — never one request per student.
 */
export async function saveAssessmentResults(
  assessmentId: number,
  results: AssessmentResultInput[],
): Promise<AssessmentResult[]> {
  const { data } = await api.post<{ data: AssessmentResult[] }>(
    `/api/v1/assessments/${assessmentId}/results`,
    { results },
  )
  return data.data
}
