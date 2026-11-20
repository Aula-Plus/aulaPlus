import { api } from "@/lib/api"

/** Screen 29 «Puesta en marcha» (ClickUp 1dfd3mc4bh8): read-only record + regime correction. */

export type LevelRegime = "enabled" | "authorized"
export type OnboardingStepStatus = "pending" | "in_progress" | "done"

export interface SupportChannels {
  whatsapp: string | null
  email: string | null
}

export interface OnboardingRecord {
  import_completed_on: string | null
  regime_configured_on: string | null
  opened_on: string | null
  offering_confirmed_on: string | null
  contact: { name: string | null; channel: string | null } | null
  regimes: { level_key: string; level_label: string; regime: LevelRegime | null; note: string | null }[]
  sources: { id: number; source: string; content: string; format: string | null; status: OnboardingStepStatus }[]
  log: { id: number; happened_on: string; step: string; status: OnboardingStepStatus }[]
  support_channels: SupportChannels
}

export async function fetchOnboarding(): Promise<OnboardingRecord> {
  const { data } = await api.get<{ data: OnboardingRecord }>("/api/v1/onboarding")
  return data.data
}

export async function requestRegimeCorrection(message: string): Promise<{ id: number; support_channels: SupportChannels }> {
  const { data } = await api.post<{ data: { id: number; support_channels: SupportChannels } }>(
    "/api/v1/onboarding/regime-correction",
    { message },
  )
  return data.data
}
