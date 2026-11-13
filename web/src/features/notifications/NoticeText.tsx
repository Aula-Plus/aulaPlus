import { Link } from "react-router-dom"
import { formatShortDate } from "@/lib/utils"
import type { AppNotification } from "@/types"

/**
 * What one notice says, and the link to the screen where it is resolved
 * (screen 22: a notice says what happened and leads to where the role can see
 * it; it never names a diagnosis nor the content of a record). Shared by the
 * «Avisos» block of the Inicio / «Alertas y seguimientos» and by the
 * Notificaciones screen.
 *
 * `studentName` lets a caller that already knows the student (e.g. from its
 * pending follow-ups list) name them where the payload carries ids only.
 */
export function NoticeText({ notice, studentName }: { notice: AppNotification; studentName?: string | null }) {
  const data = notice.data
  switch (data.type) {
    case "comment_shared":
      return (
        <span>
          Psicopedagogía te compartió un extracto
          {data.student_name ? ` sobre ${data.student_name}` : ""}.{" "}
          <Link className="underline" to={`/alumnos/${data.student_id}/seguimiento`}>
            Ver ficha del alumno
          </Link>
        </span>
      )
    case "accommodation_confirmed":
      return (
        <span>
          Hay un ajuste nuevo vigente
          {data.student_name ? ` para ${data.student_name}` : " para un alumno de tu grupo"}.{" "}
          <Link className="underline" to={`/alumnos/${data.student_id}/seguimiento`}>
            Ver ajustes en la ficha
          </Link>
        </span>
      )
    case "alert_overdue":
      return (
        <span>
          Una alerta que tenés llegó a su fecha sin salida
          {data.due_date ? ` (plazo al ${formatShortDate(data.due_date)})` : ""}.{" "}
          <Link className="underline" to="/alertas">
            Ver alertas
          </Link>
        </span>
      )
    case "accommodation_proposal_returned":
      return (
        <span>
          Psicopedagogía te devolvió una propuesta de ajuste para corregir.{" "}
          <Link className="underline" to={`/alumnos/${data.student_id}/seguimiento`}>
            Ver ficha del alumno
          </Link>
        </span>
      )
    case "follow_up_assigned":
      return (
        <span>
          Te asignaron un seguimiento
          {studentName ? ` de ${studentName}` : ""} con fecha {formatShortDate(data.due_date)}.{" "}
          <Link className="underline" to={`/alumnos/${data.student_id}/seguimiento`}>
            Ver ficha del alumno
          </Link>
        </span>
      )
    default:
      return (
        <span>
          {data.type === "screening_design_changed"
            ? "Cambiaron las franjas"
            : "Dirección volvió atrás tu cambio en las franjas"}
          {data.screening_test_type_name ? ` del screening «${data.screening_test_type_name}»` : " de un screening"}.{" "}
          <Link className="underline" to={`/pruebas-de-sondeo/tipos?abrir=${data.screening_test_type_id}`}>
            Ver diseño
          </Link>
        </span>
      )
  }
}
