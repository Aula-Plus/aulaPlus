import { isAxiosError } from "axios"
import { api } from "@/lib/api"
import type { AIInputReview } from "@/types"

/**
 * «Ver lo que recibiría la IA» (ClickUp 1dfd3mc2wx2, P03-AJU-03). Who may read
 * and mark is decided by the backend (AIInputMarkPolicy); the SPA only follows
 * `can.mark`.
 */
export async function fetchAIInputReview(groupId: number, subjectId: number | null): Promise<AIInputReview> {
  const { data } = await api.get<{ data: AIInputReview }>(`/api/v1/groups/${groupId}/ai-input`, {
    params: subjectId === null ? {} : { subject_id: subjectId },
  })
  return data.data
}

/** «La IA lo entendió mal». */
export async function markAIInputLine(
  groupId: number,
  input: { line_key: string; subject_id: number | null; comment: string },
): Promise<void> {
  await api.post(`/api/v1/groups/${groupId}/ai-input/marks`, {
    line_key: input.line_key,
    subject_id: input.subject_id,
    comment: input.comment.trim() === "" ? null : input.comment.trim(),
  })
}

/** «El ajuste está bien». */
export async function confirmAIInputAdjustment(markId: number, comment: string): Promise<void> {
  await api.post(`/api/v1/ai-input-marks/${markId}/adjustment-ok`, {
    comment: comment.trim() === "" ? null : comment.trim(),
  })
}

/** The first validation message of a failed request, if the server sent one. */
export function validationMessage(error: unknown): string | null {
  if (!isAxiosError(error) || error.response?.status !== 422) return null
  const errors = (error.response.data as { errors?: Record<string, string[]> } | undefined)?.errors
  const first = errors ? Object.values(errors)[0] : undefined
  return first?.[0] ?? null
}
