import type { ScheduledFollowUp } from "@/types"

/**
 * What a follow-up is called: its title (ClickUp 86e3qkwna), or its
 * description for one without a title. Psychopedagogy's review task for an
 * accommodation proposal (ClickUp 86e3qkwkk) carries neither — the server
 * copies no proposal content into it — so its title comes from here, naming
 * who it comes from: direction or a teacher (ClickUp 1dfd3mc55zu).
 */
export function followUpTitle(
  followUp: Pick<ScheduledFollowUp, "accommodation_id" | "title" | "description"> &
    Partial<Pick<ScheduledFollowUp, "accommodation_proposed_as">>,
): string {
  if (!followUp.accommodation_id) return followUp.title || followUp.description
  return followUp.accommodation_proposed_as === "teacher"
    ? "Revisar propuesta de ajuste de un docente"
    : "Revisar propuesta de ajuste de Dirección"
}
