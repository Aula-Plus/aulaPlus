import { useId, useState } from "react"
import { Link } from "react-router-dom"
import { CalendarClock } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { EmptyState } from "@/components/ui/empty-state"
import { SectionCard } from "@/components/ui/section-card"
import { formatShortDate } from "@/lib/utils"
import { FollowUpDetailDialog } from "./FollowUpDetailDialog"
import { FollowUpNotices } from "./FollowUpNotices"
import { responsiblesLine } from "./followUpDetailUtils"
import { followUpTitle } from "./followUpTitle"
import { useFollowUpsInbox, type FollowUpsInbox } from "./useFollowUpsInbox"

/**
 * The «Mis seguimientos» section of «Alertas y seguimientos» (`/alertas`): the
 * caller's pending follow-ups (responsible for, or shared with them) plus their
 * notices: follow-ups assigned to them, and alerts they have that reached their
 * deadline without a way out («Llegó a su fecha sin salida»). A notice is read
 * and dismissed — it is not a new alert and asks for nothing.
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
 *
 * The Inicio (ClickUp 1dfd3mc2wwz) renders FollowUpsSectionView `compact` over
 * an inbox it shares with the alerts block.
 */
export function FollowUpsSection() {
  const inbox = useFollowUpsInbox()
  return <FollowUpsSectionView inbox={inbox} />
}

/**
 * The section over an inbox its page owns. `compact` (Inicio): titled
 * «Seguimientos», without the explanatory line and without the notices — the
 * Inicio shows those with the alerts (P01-DEC-03).
 */
export function FollowUpsSectionView({ inbox, compact = false }: { inbox: FollowUpsInbox; compact?: boolean }) {
  const headingId = useId()
  const [openId, setOpenId] = useState<number | null>(null)
  const { followUps, loadFailed, reload } = inbox
  const error = inbox.loadError ?? (compact ? null : inbox.noticeError)

  return (
    <section aria-labelledby={headingId} className="grid gap-6">
      <div>
        <h2 id={headingId} className="text-xl font-semibold">
          {compact ? "Seguimientos" : "Mis seguimientos"}
        </h2>
        {!compact && (
          <p className="text-sm text-muted-foreground">Los seguimientos que tenés a cargo o que te compartieron.</p>
        )}
      </div>

      {error && <p className="text-sm text-destructive">{error}</p>}

      {!compact && <FollowUpNotices inbox={inbox} />}

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
                    <p className="text-base font-semibold">{followUpTitle(followUp)}</p>
                    {followUp.student && <p className="font-medium">{followUp.student.full_name}</p>}
                    {/* Without a title the description is already the heading. */}
                    {followUp.title && followUp.description && (
                      <p className="whitespace-pre-wrap text-muted-foreground">{followUp.description}</p>
                    )}
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
      <FollowUpDetailDialog followUpId={openId} onClose={() => setOpenId(null)} onChanged={() => reload()} />
    </section>
  )
}
