import { roleLabels } from "@/types"
import type { ScheduledFollowUpDetail, StaffMember } from "@/types"

/** Runs a mutation and swaps the dialog's record for the server's answer. */
export type RunAction = (action: () => Promise<ScheduledFollowUpDetail>) => Promise<boolean>

export function personLabel(person: StaffMember | null | undefined): string {
  if (!person) return "—"
  return person.role ? `${person.name} (${roleLabels[person.role]})` : person.name
}
