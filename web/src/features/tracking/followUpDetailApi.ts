import { api } from "@/lib/api"
import type { ScheduledFollowUpDetail } from "@/types"

/**
 * Data layer for a follow-up's full record — stages, notes, people, history
 * (ClickUp 86e3qkwna). Kept apart from scheduledFollowUpsApi.ts (lists and
 * creation). Every mutation answers with the whole updated record as the
 * caller now sees it, so the dialog just replaces its state. Authorization is
 * the backend's (ScheduledFollowUpPolicy); the `can` flags only drive the UI.
 */

type Detail = Promise<ScheduledFollowUpDetail>

async function post(url: string, body: object = {}): Detail {
  const { data } = await api.post<{ data: ScheduledFollowUpDetail }>(url, body)
  return data.data
}

export interface StageInput {
  description: string
  responsible_id: number
  due_date: string
}

export async function fetchFollowUpDetail(id: number): Detail {
  const { data } = await api.get<{ data: ScheduledFollowUpDetail }>(`/api/v1/scheduled-follow-ups/${id}`)
  return data.data
}

export function addStage(followUpId: number, input: StageInput): Detail {
  return post(`/api/v1/scheduled-follow-ups/${followUpId}/stages`, input)
}

export async function updateStage(stageId: number, input: Partial<StageInput>): Detail {
  const { data } = await api.patch<{ data: ScheduledFollowUpDetail }>(`/api/v1/follow-up-stages/${stageId}`, input)
  return data.data
}

export function splitStage(stageId: number, stages: StageInput[]): Detail {
  return post(`/api/v1/follow-up-stages/${stageId}/split`, { stages })
}

export function closeStage(stageId: number, conclusion: string): Detail {
  return post(`/api/v1/follow-up-stages/${stageId}/close`, { conclusion })
}

export function addStageNote(stageId: number, body: string): Detail {
  return post(`/api/v1/follow-up-stages/${stageId}/notes`, { body })
}

export function addParticipant(followUpId: number, userId: number): Detail {
  return post(`/api/v1/scheduled-follow-ups/${followUpId}/participants`, { user_id: userId })
}

/** The reason is optional; omitted entirely when empty. */
export function removeParticipant(followUpId: number, userId: number, reason?: string): Detail {
  return post(
    `/api/v1/scheduled-follow-ups/${followUpId}/participants/${userId}/remove`,
    reason ? { reason } : {},
  )
}

export function addGeneralResponsible(followUpId: number, userId: number): Detail {
  return post(`/api/v1/scheduled-follow-ups/${followUpId}/general-responsibles`, { user_id: userId })
}

export function removeGeneralResponsible(followUpId: number, userId: number): Detail {
  return post(`/api/v1/scheduled-follow-ups/${followUpId}/general-responsibles/${userId}/remove`)
}

export function withdraw(followUpId: number, reason: string, newGeneralResponsibleId?: number): Detail {
  return post(`/api/v1/scheduled-follow-ups/${followUpId}/withdraw`, {
    reason,
    ...(newGeneralResponsibleId ? { new_general_responsible_id: newGeneralResponsibleId } : {}),
  })
}

export function hide(followUpId: number): Detail {
  return post(`/api/v1/scheduled-follow-ups/${followUpId}/hide`)
}

/** Resolve with a general conclusion (required by the backend). */
export function resolve(followUpId: number, conclusion: string): Detail {
  return post(`/api/v1/scheduled-follow-ups/${followUpId}/resolve`, { resolution_note: conclusion })
}
