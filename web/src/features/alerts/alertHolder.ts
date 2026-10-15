import { formatShortDate } from "@/lib/utils"
import type { Alert } from "@/types"

/**
 * «la tiene X desde el dd/mm», from what the server already returned for an
 * alert the viewer can see. An alert is always held by one person: handing it
 * off transfers it (review of 14/10). `null` while nobody chose a way out.
 */
export function alertHolderPhrase(alert: Alert): string | null {
  const holder = alert.assignee?.name
  if (!alert.outcome || !holder) return null
  return `la tiene ${holder} desde el ${formatShortDate(alert.outcome_at)}`
}
