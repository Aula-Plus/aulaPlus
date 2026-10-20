import { useEffect, useId, useState } from "react"
import { CircleCheck } from "lucide-react"
import { EmptyState } from "@/components/ui/empty-state"
import type { Alert } from "@/types"
import { AlertCard } from "./AlertCard"
import * as alertsApi from "./alertsApi"

/**
 * The «Alertas» section of «Alertas y seguimientos» (`/alertas`): the open
 * alerts that reach the current user, each with its three ways out (ClickUp
 * 86e3jpzcv / 86e3jpzdp / 86e3jdkkr). The server decides which alerts are
 * listed (`Alert::scopeVisibleTo`) and what the user may do with each one
 * (`alert.can`).
 *
 * A section, not a page: its heading is an <h2> under the page's <h1>, and it
 * is rendered before any loading/error state so the screen never shows a
 * loose «Cargando…» without saying what is loading.
 */
export function AlertsSection() {
  const headingId = useId()
  const [alerts, setAlerts] = useState<Alert[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    alertsApi
      .fetchMyAlerts()
      .then(setAlerts)
      .catch(() => setError("No pudimos cargar tus alertas."))
  }, [])

  function replace(updated: Alert) {
    setAlerts((current) =>
      (current ?? [])
        .map((alert) => (alert.id === updated.id ? updated : alert))
        .filter((alert) => !alert.resolved),
    )
    // A new way out may close an escalated alert: refresh the list quietly.
    alertsApi
      .fetchMyAlerts()
      .then(setAlerts)
      .catch(() => undefined)
  }

  return (
    <section aria-labelledby={headingId} className="grid gap-6">
      <div>
        <h2 id={headingId} className="text-xl font-semibold">
          Alertas
        </h2>
        <p className="text-sm text-muted-foreground">
          Una alerta no se cierra con «ya me encargué»: elegí una salida, con responsable y plazo.
        </p>
      </div>
      <AlertsSectionBody alerts={alerts} error={error} onChange={replace} />
    </section>
  )
}

function AlertsSectionBody({
  alerts,
  error,
  onChange,
}: {
  alerts: Alert[] | null
  error: string | null
  onChange: (updated: Alert) => void
}) {
  if (error) return <p className="text-sm text-destructive">{error}</p>
  if (!alerts) return <p className="text-muted-foreground">Cargando…</p>
  if (alerts.length === 0) return <EmptyState icon={CircleCheck} message="No tenés alertas abiertas." />

  const pending = alerts.filter((alert) => alert.can?.act)
  const others = alerts.filter((alert) => !alert.can?.act)

  return (
    <>
      {pending.length > 0 && (
        <section className="grid gap-3">
          <h3 className="text-lg font-semibold">Te toca elegir una salida</h3>
          {pending.map((alert) => (
            <AlertCard key={alert.id} alert={alert} onChange={onChange} />
          ))}
        </section>
      )}
      {others.length > 0 && (
        <section className="grid gap-3">
          <h3 className="text-lg font-semibold">En curso</h3>
          {others.map((alert) => (
            <AlertCard key={alert.id} alert={alert} onChange={onChange} />
          ))}
        </section>
      )}
    </>
  )
}
