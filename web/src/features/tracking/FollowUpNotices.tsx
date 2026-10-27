import { Link } from "react-router-dom"
import { Button } from "@/components/ui/button"
import { SectionCard } from "@/components/ui/section-card"
import { formatShortDate } from "@/lib/utils"
import type { FollowUpsInbox } from "./useFollowUpsInbox"

/**
 * «Avisos»: follow-ups assigned to the caller, alerts they have that reached
 * their deadline without a way out («Llegó a su fecha sin salida»), shared
 * extracts, returned proposals and screening design changes. A notice is read
 * and dismissed — it is not a new alert and asks for nothing. Renders nothing
 * when there are none.
 *
 * `showError`: the Inicio shows a failed «Entendido» here, next to the notice;
 * «Mis seguimientos» shows it at the top of its section.
 */
export function FollowUpNotices({ inbox, showError = false }: { inbox: FollowUpsInbox; showError?: boolean }) {
  const { notifications, followUps, dismiss } = inbox
  if (notifications.length === 0) return null

  // Notices carry ids only; the student's name comes from the pending list.
  const studentNameByFollowUp = new Map(
    (followUps ?? []).map((followUp) => [followUp.id, followUp.student?.full_name]),
  )

  return (
    <SectionCard title="Avisos" headingLevel={3}>
      {showError && inbox.noticeError && <p className="mb-2 text-sm text-destructive">{inbox.noticeError}</p>}
      <ul className="grid gap-2">
        {notifications.map((notice) => (
          <li key={notice.id} className="flex items-center justify-between gap-3 rounded-md border p-3 text-sm">
            {notice.data.type === "comment_shared" ? (
              <span>
                Psicopedagogía te compartió un extracto
                {notice.data.student_name ? ` sobre ${notice.data.student_name}` : ""}.{" "}
                <Link className="underline" to={`/alumnos/${notice.data.student_id}/seguimiento`}>
                  Ver ficha del alumno
                </Link>
              </span>
            ) : notice.data.type === "alert_overdue" ? (
              <span>
                Una alerta que tenés llegó a su fecha sin salida (plazo al{" "}
                {formatShortDate(notice.data.due_date)}).{" "}
                <Link className="underline" to="/alertas">
                  Ver alertas
                </Link>
              </span>
            ) : notice.data.type === "accommodation_proposal_returned" ? (
              <span>
                Psicopedagogía te devolvió una propuesta de ajuste para corregir.{" "}
                <Link className="underline" to={`/alumnos/${notice.data.student_id}/seguimiento`}>
                  Ver ficha del alumno
                </Link>
              </span>
            ) : notice.data.type === "follow_up_assigned" ? (
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
            ) : (
              <span>
                {notice.data.type === "screening_design_changed"
                  ? "Cambiaron las franjas"
                  : "Dirección volvió atrás tu cambio en las franjas"}
                {notice.data.screening_test_type_name
                  ? ` del screening «${notice.data.screening_test_type_name}»`
                  : " de un screening"}
                .{" "}
                <Link className="underline" to={`/pruebas-de-sondeo/tipos?abrir=${notice.data.screening_test_type_id}`}>
                  Ver diseño
                </Link>
              </span>
            )}
            <Button type="button" size="sm" variant="outline" onClick={() => dismiss(notice.id)}>
              Entendido
            </Button>
          </li>
        ))}
      </ul>
    </SectionCard>
  )
}
