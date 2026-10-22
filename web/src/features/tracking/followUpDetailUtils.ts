import { roleLabels } from "@/types"
import type { ScheduledFollowUp, ScheduledFollowUpDetail, StaffMember } from "@/types"

/** Runs a mutation and swaps the dialog's record for the server's answer. */
export type RunAction = (action: () => Promise<ScheduledFollowUpDetail>) => Promise<boolean>

export function personLabel(person: StaffMember | null | undefined): string {
  if (!person) return "—"
  return person.role ? `${person.name} (${roleLabels[person.role]})` : person.name
}

/**
 * «Responsable: …» line of a follow-up in a list. A follow-up can have several
 * general responsibles; `assigned_to` is only the first of them, so it is the
 * fallback for payloads that don't carry the full list. `null` when nobody.
 */
export function responsiblesLine(followUp: ScheduledFollowUp): string | null {
  const people = followUp.general_responsibles?.length
    ? followUp.general_responsibles
    : followUp.assigned_to
      ? [followUp.assigned_to]
      : []
  if (people.length === 0) return null
  return `${people.length > 1 ? "Responsables" : "Responsable"}: ${people.map(personLabel).join(", ")}`
}
