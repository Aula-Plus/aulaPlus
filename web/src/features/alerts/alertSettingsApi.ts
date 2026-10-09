import { api } from "@/lib/api"
import type {
  AlertCondition,
  AlertRecipient,
  AlertRoutingEntry,
  AlertRule,
  AlertSettings,
  AlertType,
} from "@/types"

/**
 * Data layer for the alert settings screen (ClickUp 86e3jpzcv): the school's
 * sustained-low-performance conditions and who each alert type reaches first.
 * Direction and psychopedagogy only (`AlertRulePolicy`).
 */

export interface AlertRuleInput {
  condition: AlertCondition
  threshold: number
  consecutive_count?: number | null
  period_days?: number | null
  subject_id?: number | null
  active?: boolean
}

export async function fetchAlertSettings(): Promise<AlertSettings> {
  const { data } = await api.get<{ data: AlertSettings }>("/api/v1/alert-settings")
  return data.data
}

export async function createAlertRule(input: AlertRuleInput): Promise<AlertRule> {
  const { data } = await api.post<{ data: AlertRule }>("/api/v1/alert-rules", input)
  return data.data
}

export async function updateAlertRule(id: number, input: Partial<AlertRuleInput>): Promise<AlertRule> {
  const { data } = await api.patch<{ data: AlertRule }>(`/api/v1/alert-rules/${id}`, input)
  return data.data
}

export async function deleteAlertRule(id: number): Promise<void> {
  await api.delete(`/api/v1/alert-rules/${id}`)
}

export async function updateAlertRouting(
  type: AlertType,
  recipients: AlertRecipient[],
): Promise<AlertRoutingEntry[]> {
  const { data } = await api.put<{ data: AlertRoutingEntry[] }>(`/api/v1/alert-routing/${type}`, {
    recipients,
  })
  return data.data
}
