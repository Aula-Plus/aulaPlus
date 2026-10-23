import type { ScheduledFollowUp } from "@/types"

/**
 * What a follow-up is called: its title (ClickUp 86e3qkwna), or its
 * description for one without a title. Psychopedagogy's review task for a
 * direction accommodation proposal (ClickUp 86e3qkwkk) carries neither — the
 * server copies no proposal content into it — so its title comes from here.
 */
export function followUpTitle(
  followUp: Pick<ScheduledFollowUp, "accommodation_id" | "title" | "description">,
): string {
  return followUp.accommodation_id ? "Revisar propuesta de ajuste de Dirección" : followUp.title || followUp.description
}
