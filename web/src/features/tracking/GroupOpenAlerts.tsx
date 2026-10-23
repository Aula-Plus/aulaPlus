import { useEffect, useState } from "react"
import { Link } from "react-router-dom"
import { RowLink } from "@/components/ui/row-link"
import { SectionCard } from "@/components/ui/section-card"
import { formatShortDate } from "@/lib/utils"
import { alertTypeLabels, type Alert } from "@/types"
import { alertHolderPhrase } from "@/features/alerts/alertHolder"
import { fetchGroupAlerts } from "./trackingApi"

const VISIBLE_LIMIT = 5

/** «La tiene X desde el dd/mm», once someone chose a way out. */
function HolderLine({ alert }: { alert: Alert }) {
  const phrase = alertHolderPhrase(alert)
  if (!phrase) return null
  const sentence = `${phrase.charAt(0).toUpperCase()}${phrase.slice(1)}`
  return <p className="text-xs text-muted-foreground">{sentence}</p>
}

interface GroupOpenAlertsProps {
  groupId: number
  /** Student names come from the group profile; alerts only carry `student_id`. */
  studentNames: Record<number, string>
}

/**
 * Perfil de grupo — "Alertas del grupo" card. Lists the group's open alerts
 * (student, alert type, since when, and — once someone chose a way out — who
 * has it: «la tiene X desde el dd/mm», ClickUp 86e3jpzdp) with a link to each
 * student, at most five; past that, "Ver todas" leads to «Alertas y
 * seguimientos» (`/alertas`), where every open alert that reaches the viewer
 * is listed with its ways out.
 *
 * Permissions are exactly the alerts endpoint's (`AlertPolicy::viewForGroup`):
 * a viewer who cannot read them gets a 403, and the card simply does not
 * render. It also does not render when there are no open alerts — never a
 * "0 alertas" placeholder. Who has an alert comes with the alert itself, so it
 * is shown only to whoever may see that alert (`Alert::scopeVisibleTo`).
 */
export function GroupOpenAlerts({ groupId, studentNames }: GroupOpenAlertsProps) {
  const [alerts, setAlerts] = useState<Alert[]>([])

  useEffect(() => {
    let cancelled = false
    fetchGroupAlerts(groupId)
      .then((data) => {
        if (!cancelled) setAlerts(data.filter((alert) => !alert.resolved))
      })
      .catch(() => {
        if (!cancelled) setAlerts([])
      })
    return () => {
      cancelled = true
    }
  }, [groupId])

  if (alerts.length === 0) return null

  const visible = alerts.slice(0, VISIBLE_LIMIT)

  return (
    <SectionCard title="Alertas del grupo">
      <ul className="grid gap-3">
        {visible.map((alert) => (
          <li
            key={alert.id}
            className="flex items-start justify-between gap-4 rounded-md border p-3 text-sm"
          >
            <div>
              <p className="font-medium">{studentNames[alert.student_id] ?? "Alumno"}</p>
              <p>
                {alertTypeLabels[alert.type]}
                {alert.type === "difficulty_signal" && alert.comment_category_name
                  ? ` — ${alert.comment_category_name}`
                  : ""}
              </p>
              {alert.created_at && (
                <p className="mt-1 text-xs text-muted-foreground">
                  Desde el {formatShortDate(alert.created_at)}
                </p>
              )}
              <HolderLine alert={alert} />
            </div>
            <RowLink to={`/alumnos/${alert.student_id}/seguimiento`} className="shrink-0">
              Ver alumno
            </RowLink>
          </li>
        ))}
      </ul>
      {alerts.length > VISIBLE_LIMIT && (
        <Link to="/alertas" className="mt-3 inline-block text-sm font-medium text-primary hover:underline">
          Ver todas ({alerts.length})
        </Link>
      )}
    </SectionCard>
  )
}
