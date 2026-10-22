import { useCallback, useEffect, useId, useState } from "react"
import { Link } from "react-router-dom"
import { CalendarClock } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { EmptyState } from "@/components/ui/empty-state"
import { SectionCard } from "@/components/ui/section-card"
import { formatShortDate } from "@/lib/utils"
import type { AppNotification, ScheduledFollowUp } from "@/types"
import * as scheduledFollowUpsApi from "./scheduledFollowUpsApi"
import { refreshUnreadNoticeCount } from "./unreadNotices"
import { FollowUpDetailDialog } from "./FollowUpDetailDialog"
import { responsiblesLine } from "./followUpDetailUtils"

/**
 * The «Mis seguimientos» section of «Alertas y seguimientos» (`/alertas`): the
 * caller's pending follow-ups (responsible for, or shared with them) plus the
 * notices of follow-ups assigned to them. A notice is read and dismissed — it
 * is not an alert and asks for nothing.
 *
 * Wording (PO review 14/10): "Pendientes" is every open follow-up; one past
 * its date is still pending and only carries a "Fuera de fecha" badge —
 * reaching the date never closes it. "Entendido" only marks the notice read
 * (and drops the sidebar unread count); it never resolves the follow-up.
 *
 * A section, not a page: its heading is an <h2> under the page's <h1>, and its
 * cards («Avisos», «Pendientes») are <h3> subsections.
 *
 * "Ver detalle" opens FollowUpDetailDialog to manage the follow-up right here
 * (stages, notes, people, withdrawing, resolving).
 */
export function FollowUpsSection() {
  const headingId = useId()
  const [followUps, setFollowUps] = useState<ScheduledFollowUp[] | null>(null)
  const [notifications, setNotifications] = useState<AppNotification[]>([])
  const [error, setError] = useState<string | null>(null)
  // A failed load is not an empty list: the error says so, and «Pendientes»
  // is not rendered rather than claiming there are none.
  const [loadFailed, setLoadFailed] = useState(false)
  const [openId, setOpenId] = useState<number | null>(null)

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
      setLoadFailed(true)
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
    // Re-sync the sidebar count with the server (it disappears at 0).
    void refreshUnreadNoticeCount()
  }

  // Notices carry ids only; the student's name comes from the pending list.
  const studentNameByFollowUp = new Map(
    (followUps ?? []).map((followUp) => [followUp.id, followUp.student?.full_name]),
  )

  return (
    <section aria-labelledby={headingId} className="grid gap-6">
      <div>
        <h2 id={headingId} className="text-xl font-semibold">
          Mis seguimientos
        </h2>
        <p className="text-sm text-muted-foreground">Los seguimientos que tenés a cargo o que te compartieron.</p>
      </div>

      {error && <p className="text-sm text-destructive">{error}</p>}

      {notifications.length > 0 && (
        <SectionCard title="Avisos" headingLevel={3}>
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

      {!loadFailed && (
        <SectionCard title="Pendientes" headingLevel={3}>
          {followUps === null ? (
            <p className="text-muted-foreground">Cargando…</p>
          ) : followUps.length === 0 ? (
            <EmptyState icon={CalendarClock} message="No tenés seguimientos pendientes." />
          ) : (
            <ul className="grid gap-3">
              {followUps.map((followUp) => (
                <li key={followUp.id} className="flex items-start justify-between gap-3 rounded-md border p-3 text-sm">
                  <div>
                    {followUp.title && <p className="text-base font-semibold">{followUp.title}</p>}
                    {followUp.student && <p className="font-medium">{followUp.student.full_name}</p>}
                    <p className="whitespace-pre-wrap text-muted-foreground">{followUp.description}</p>
                    <p className="mt-1 text-xs text-muted-foreground">
                      Plazo: {formatShortDate(followUp.due_date)}
                      {responsiblesLine(followUp) && ` · ${responsiblesLine(followUp)}`}
                    </p>
                    <div className="mt-1 flex flex-wrap items-center gap-3">
                      <Button type="button" size="sm" variant="outline" onClick={() => setOpenId(followUp.id)}>
                        Ver detalle
                      </Button>
                      <Link className="text-xs underline" to={`/alumnos/${followUp.student_id}/seguimiento`}>
                        Ver ficha del alumno
                      </Link>
                    </div>
                  </div>
                  {followUp.is_overdue && <Badge tone="danger">Fuera de fecha</Badge>}
                </li>
              ))}
            </ul>
          )}
        </SectionCard>
      )}

      {/* Reload the list on every change: a resolved/hidden follow-up leaves it. */}
      <FollowUpDetailDialog followUpId={openId} onClose={() => setOpenId(null)} onChanged={() => load()} />
    </section>
  )
}
