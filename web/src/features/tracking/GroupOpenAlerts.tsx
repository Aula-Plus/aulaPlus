import { useEffect, useState } from "react"
import { RowLink } from "@/components/ui/row-link"
import { SectionCard } from "@/components/ui/section-card"
import { formatShortDate } from "@/lib/utils"
import { alertTypeLabels, type Alert } from "@/types"
import { fetchGroupAlerts } from "./trackingApi"

const VISIBLE_LIMIT = 5

interface GroupOpenAlertsProps {
  groupId: number
  /** Student names come from the group profile; alerts only carry `student_id`. */
  studentNames: Record<number, string>
}

/**
 * Perfil de grupo — "Alertas del grupo" card. Lists the group's open alerts
 * (student, alert type, since when) with a link to each student, at most five
 * with a "Ver todas" toggle for the rest.
 *
 * Permissions are exactly the alerts endpoint's (`AlertPolicy::viewForGroup`):
 * a viewer who cannot read them gets a 403, and the card simply does not
 * render. It also does not render when there are no open alerts — never a
 * "0 alertas" placeholder.
 */
export function GroupOpenAlerts({ groupId, studentNames }: GroupOpenAlertsProps) {
  const [alerts, setAlerts] = useState<Alert[]>([])
  const [showAll, setShowAll] = useState(false)

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

  const visible = showAll ? alerts : alerts.slice(0, VISIBLE_LIMIT)

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
              <p>{alertTypeLabels[alert.type]}</p>
              {alert.created_at && (
                <p className="mt-1 text-xs text-muted-foreground">
                  Desde el {formatShortDate(alert.created_at)}
                </p>
              )}
            </div>
            <RowLink to={`/alumnos/${alert.student_id}/seguimiento`} className="shrink-0">
              Ver alumno
            </RowLink>
          </li>
        ))}
      </ul>
      {alerts.length > VISIBLE_LIMIT && !showAll && (
        <button
          type="button"
          className="mt-3 text-sm font-medium text-primary hover:underline"
          onClick={() => setShowAll(true)}
        >
          Ver todas ({alerts.length})
        </button>
      )}
    </SectionCard>
  )
}
