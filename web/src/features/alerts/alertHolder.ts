import { formatShortDate } from "@/lib/utils"
import type { Alert } from "@/types"

/**
 * «X y Y» in Spanish — «e» before a word starting with an /i/ sound
 * («Galia Cohen e Ignacio Pérez»).
 */
export function joinNames(names: string[]): string {
  if (names.length <= 1) return names[0] ?? ""
  const last = names[names.length - 1]
  const conjunction = /^h?[iíIÍ](?![aeiouáéíóú])/i.test(last) ? "e" : "y"
  return `${names.slice(0, -1).join(", ")} ${conjunction} ${last}`
}

/** Everyone in charge of the alert: the person responsible, then whoever it was shared with. */
export function alertHolders(alert: Alert): string[] {
  return [alert.assignee, ...(alert.collaborators ?? [])]
    .map((person) => person?.name)
    .filter((name): name is string => Boolean(name))
}

/**
 * «la tiene X desde el dd/mm» (or «la tienen X y Y…» once shared), from what
 * the server already returned for an alert the viewer can see. `null` while
 * nobody chose a way out.
 */
export function alertHolderPhrase(alert: Alert): string | null {
  const holders = alertHolders(alert)
  if (!alert.outcome || holders.length === 0) return null
  const verb = holders.length > 1 ? "la tienen" : "la tiene"
  return `${verb} ${joinNames(holders)} desde el ${formatShortDate(alert.outcome_at)}`
}
