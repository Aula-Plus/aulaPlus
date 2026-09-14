import { useCallback, useEffect, useState } from "react"
import { Link } from "react-router-dom"
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
    <section className="grid gap-3">
      <h2 className="text-lg font-semibold">Seguimientos vencidos</h2>
      {error ? (
        <p className="text-sm text-destructive">{error}</p>
      ) : followUps === null ? (
        <p className="text-muted-foreground">Cargando…</p>
      ) : followUps.length === 0 ? (
        <p className="text-muted-foreground">No hay seguimientos vencidos.</p>
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
              <Link
                className="shrink-0 text-primary underline-offset-4 hover:underline"
                to={`/alumnos/${followUp.student_id}/seguimiento`}
              >
                Ver alumno
              </Link>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
