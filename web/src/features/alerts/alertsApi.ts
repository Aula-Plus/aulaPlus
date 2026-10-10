import { api } from "@/lib/api"
import type { Alert, AlertOutcome, Role } from "@/types"

/**
 * Data layer for the alerts that reach the current user, the three ways out
 * of an alert, sharing it and its notes (ClickUp 86e3jpzcv / 86e3jpzdp).
 */

export interface HandoffCandidate {
  id: number
  name: string
  roles: Role[]
}

export interface AlertOutcomeInput {
  outcome: AlertOutcome
  /** `YYYY-MM-DD`. */
  due_on: string
  /** Only for `handed_off`: who becomes responsible. */
  assignee_id?: number | null
  note?: string | null
}

export async function fetchMyAlerts(): Promise<Alert[]> {
  const { data } = await api.get<{ data: Alert[] }>("/api/v1/alerts")
  return data.data
}

export async function chooseAlertOutcome(alertId: number, input: AlertOutcomeInput): Promise<Alert> {
  const { data } = await api.post<{ data: Alert }>(`/api/v1/alerts/${alertId}/outcome`, input)
  return data.data
}

export async function fetchHandoffCandidates(alertId: number): Promise<HandoffCandidate[]> {
  const { data } = await api.get<{ data: HandoffCandidate[] }>(
    `/api/v1/alerts/${alertId}/handoff-candidates`,
  )
  return data.data
}

export interface ShareAlertInput {
  /** The co-responsible: someone from `fetchHandoffCandidates`. */
  user_id: number
  note?: string | null
}

/** Share the alert with a co-responsible: both are in charge from then on. */
export async function shareAlert(alertId: number, input: ShareAlertInput): Promise<Alert> {
  const { data } = await api.post<{ data: Alert }>(`/api/v1/alerts/${alertId}/share`, input)
  return data.data
}

/** Leave a note in the alert's thread. */
export async function addAlertNote(alertId: number, note: string): Promise<Alert> {
  const { data } = await api.post<{ data: Alert }>(`/api/v1/alerts/${alertId}/notes`, { note })
  return data.data
}

export async function resolveAlert(alertId: number): Promise<Alert> {
  const { data } = await api.post<{ data: Alert }>(`/api/v1/alerts/${alertId}/resolve`)
  return data.data
}
