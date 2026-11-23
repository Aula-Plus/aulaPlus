import { useEffect, useState } from "react"
import { Button } from "@/components/ui/button"
import { SectionCard } from "@/components/ui/section-card"
import { formatShortDate } from "@/lib/utils"
import type { MyMeetingAction } from "@/types"
import { completeMeetingAction, fetchMyMeetingActions } from "./psychopedagogySpaceApi"

/**
 * The actions agreed in a meeting that were left to me (screen 10,
 * P10-DEC-08): each one is a task in my Inicio, with its deadline, until I
 * mark it done. Every role gets it — a teacher never enters the space but
 * does get the actions left to them; only the action's text and deadline and
 * the meeting's type and date reach them, never the rest of the sheet.
 */
export function MeetingActionsTask() {
  const [actions, setActions] = useState<MyMeetingAction[]>([])
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    fetchMyMeetingActions().then(
      (list) => {
        if (!cancelled) setActions(list)
      },
      () => {
        if (!cancelled) setActions([])
      },
    )
    return () => {
      cancelled = true
    }
  }, [])

  async function markDone(action: MyMeetingAction) {
    setError(null)
    try {
      await completeMeetingAction(action.id)
      setActions((current) => current.filter((item) => item.id !== action.id))
    } catch {
      setError("No pudimos marcarla hecha.")
    }
  }

  if (actions.length === 0) return null

  return (
    <SectionCard title="Acciones de reuniones">
      <ul className="grid gap-2">
        {actions.map((action) => (
          <li key={action.id} className="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 text-sm">
            <div>
              <p className="font-medium">{action.description}</p>
              <p className="text-muted-foreground">
                Plazo: {formatShortDate(action.due_on)} · De la {action.meeting.sheet_type_name.toLowerCase()} del{" "}
                {formatShortDate(action.meeting.held_on)}
              </p>
            </div>
            <Button size="sm" variant="outline" onClick={() => void markDone(action)}>
              Marcar hecha
            </Button>
          </li>
        ))}
      </ul>
      {error && <p className="mt-2 text-sm text-destructive">{error}</p>}
    </SectionCard>
  )
}
