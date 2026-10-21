import type { ScheduledFollowUp } from "@/types"

/**
 * What a follow-up says. Psychopedagogy's review task for a direction
 * accommodation proposal (ClickUp 86e3qkwkk) carries no description — the
 * server copies no proposal content into it — so its title comes from here.
 */
export function followUpTitle(followUp: Pick<ScheduledFollowUp, "accommodation_id" | "description">): string {
  return followUp.accommodation_id ? "Revisar propuesta de ajuste de Dirección" : followUp.description
}
