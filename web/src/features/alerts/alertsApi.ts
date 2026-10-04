import { api } from "@/lib/api"
import type { Alert, AlertOutcome, Role } from "@/types"

/**
 * Data layer for the alerts that reach the current user and the three ways
 * out of an alert (ClickUp 86e3jpzcv / 86e3jpzdp).
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

export async function resolveAlert(alertId: number): Promise<Alert> {
  const { data } = await api.post<{ data: Alert }>(`/api/v1/alerts/${alertId}/resolve`)
  return data.data
}
