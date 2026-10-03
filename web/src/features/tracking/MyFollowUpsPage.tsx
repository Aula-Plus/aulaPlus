import { useCallback, useEffect, useState } from "react"
import { Link } from "react-router-dom"
import { CalendarClock } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { EmptyState } from "@/components/ui/empty-state"
import { PageHeader } from "@/components/ui/page-header"
import { SectionCard } from "@/components/ui/section-card"
import { formatShortDate } from "@/lib/utils"
import { roleLabels } from "@/types"
import type { AppNotification, ScheduledFollowUp } from "@/types"
import * as scheduledFollowUpsApi from "./scheduledFollowUpsApi"

/**
 * "Mis seguimientos": the caller's pending follow-ups (responsible for, or
 * shared with them) plus the notices of follow-ups assigned to them. A notice
 * is read and dismissed — it is not an alert and asks for nothing.
 */
export function MyFollowUpsPage() {
  const [followUps, setFollowUps] = useState<ScheduledFollowUp[] | null>(null)
  const [notifications, setNotifications] = useState<AppNotification[]>([])
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    try {
      const [mine, notices] = await Promise.all([
        scheduledFollowUpsApi.fetchMyFollowUps(),
        scheduledFollowUpsApi.fetchNotifications(),
      ])
      setFollowUps(mine)
      setNotifications(notices)
    } catch {
      setError("No pudimos cargar tus seguimientos.")
      setFollowUps([])
    }
  }, [])

  useEffect(() => {
    load()
  }, [load])

  async function dismiss(id: string) {
    try {
      await scheduledFollowUpsApi.markNotificationRead(id)
      setNotifications((prev) => prev.filter((notice) => notice.id !== id))
    } catch {
      setError("No pudimos marcar el aviso como leído.")
    }
  }

  // Notices carry ids only; the student's name comes from the pending list.
  const studentNameByFollowUp = new Map(
    (followUps ?? []).map((followUp) => [followUp.id, followUp.student?.full_name]),
  )

  return (
    <div className="grid gap-6">
      <PageHeader title="Mis seguimientos" description="Los seguimientos que tenés a cargo o que te compartieron." />

      {error && <p className="text-sm text-destructive">{error}</p>}

      {notifications.length > 0 && (
        <SectionCard title="Avisos">
          <ul className="grid gap-2">
            {notifications.map((notice) => (
              <li key={notice.id} className="flex items-center justify-between gap-3 rounded-md border p-3 text-sm">
                <span>
                  Te asignaron un seguimiento
                  {studentNameByFollowUp.get(notice.data.follow_up_id)
                    ? ` de ${studentNameByFollowUp.get(notice.data.follow_up_id)}`
                    : ""}{" "}
                  con fecha {formatShortDate(notice.data.due_date)}.{" "}
                  <Link className="underline" to={`/alumnos/${notice.data.student_id}/seguimiento`}>
                    Ver ficha del alumno
                  </Link>
                </span>
                <Button type="button" size="sm" variant="outline" onClick={() => dismiss(notice.id)}>
                  Entendido
                </Button>
              </li>
            ))}
          </ul>
        </SectionCard>
      )}

      <SectionCard title="Pendientes">
        {followUps === null ? (
          <p className="text-muted-foreground">Cargando…</p>
        ) : followUps.length === 0 ? (
          <EmptyState icon={CalendarClock} message="No tenés seguimientos pendientes." />
        ) : (
          <ul className="grid gap-3">
            {followUps.map((followUp) => (
              <li key={followUp.id} className="flex items-start justify-between gap-3 rounded-md border p-3 text-sm">
                <div>
                  {followUp.student && <p className="font-medium">{followUp.student.full_name}</p>}
                  <p className="whitespace-pre-wrap">{followUp.description}</p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    Vence: {formatShortDate(followUp.due_date)}
                    {followUp.assigned_to?.role && ` · Responsable: ${followUp.assigned_to.name} (${roleLabels[followUp.assigned_to.role]})`}
                  </p>
                  <Link className="text-xs underline" to={`/alumnos/${followUp.student_id}/seguimiento`}>
                    Ver ficha del alumno
                  </Link>
                </div>
                {followUp.is_overdue && <Badge tone="danger">Vencido</Badge>}
              </li>
            ))}
          </ul>
        )}
      </SectionCard>
    </div>
  )
}
