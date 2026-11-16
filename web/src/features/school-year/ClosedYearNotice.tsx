import type { Group } from "@/types"
import { formatDay } from "./schoolYearDates"

/**
 * Screen 26: on a group of a closed year, says it is read-only — or until
 * when direction enabled loading in it. UX only; the server rejects writes.
 */
export function ClosedYearNotice({ group }: { group: Pick<Group, "school_year" | "year_closed" | "read_only" | "writable_until"> }) {
  if (!group.year_closed) return null

  return (
    <p role="note" className="rounded-md border bg-muted/50 p-3 text-sm">
      {group.read_only
        ? `El año ${group.school_year} está cerrado: este grupo es solo para leer.`
        : `El año ${group.school_year} está cerrado, pero Dirección habilitó la carga en este grupo hasta el ${formatDay(group.writable_until)}.`}
    </p>
  )
}
