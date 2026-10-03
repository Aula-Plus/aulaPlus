import { useCallback, useEffect, useState } from "react"
import { CalendarClock } from "lucide-react"
import { EmptyState } from "@/components/ui/empty-state"
import { RowLink } from "@/components/ui/row-link"
import { SectionCard } from "@/components/ui/section-card"
import { formatShortDate } from "@/lib/utils"
import type { ScheduledFollowUp } from "@/types"
import { fetchGroupScheduledFollowUps } from "./scheduledFollowUpsApi"

interface GroupOverdueFollowUpsProps {
  groupId: number
}

/**
 * Perfil de grupo — "Seguimientos vencidos" section (docs/prompts/27-frontend-
 * perfil-de-grupo.md §6). An operational list (not an aggregate) of the group's
 * overdue scheduled follow-ups: description, due date and a link to the student.
 *
 * Unlike the other two group sections it DOES carry `student_id` per row, and
 * links to that student's tracking page. That exposes nothing new: the backend
 * already filtered each row through ScheduledFollowUpPolicy::view, so the viewer
 * could reach that student directly anyway (spec §6). Fetched with
 * `?overdue=true`; the `is_overdue` flag is decided server-side.
 */
export function GroupOverdueFollowUps({ groupId }: GroupOverdueFollowUpsProps) {
  const [followUps, setFollowUps] = useState<ScheduledFollowUp[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setError(null)
    try {
      setFollowUps(await fetchGroupScheduledFollowUps(groupId, true))
    } catch {
      setError("No pudimos cargar los seguimientos vencidos.")
      setFollowUps([])
    }
  }, [groupId])

  useEffect(() => {
    load()
  }, [load])

  return (
    <SectionCard title="Seguimientos vencidos">
      {error ? (
        <p className="text-sm text-destructive">{error}</p>
      ) : followUps === null ? (
        <p className="text-muted-foreground">Cargando…</p>
      ) : followUps.length === 0 ? (
        <EmptyState icon={CalendarClock} message="No hay seguimientos vencidos." />
      ) : (
        <ul className="grid gap-3">
          {followUps.map((followUp) => (
            <li
              key={followUp.id}
              className="flex items-start justify-between gap-4 rounded-md border p-3 text-sm"
            >
              <div>
                <p className="whitespace-pre-wrap">{followUp.description}</p>
                <p className="mt-1 text-xs text-muted-foreground">
                  Vence: {formatShortDate(followUp.due_date)}
                </p>
              </div>
              <RowLink to={`/alumnos/${followUp.student_id}/seguimiento`} className="shrink-0">
                Ver alumno
              </RowLink>
            </li>
          ))}
        </ul>
      )}
    </SectionCard>
  )
}
