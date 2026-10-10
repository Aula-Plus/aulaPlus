import { useEffect, useState } from "react"
import { CircleCheck } from "lucide-react"
import { EmptyState } from "@/components/ui/empty-state"
import { PageHeader } from "@/components/ui/page-header"
import type { Alert } from "@/types"
import { AlertCard } from "./AlertCard"
import * as alertsApi from "./alertsApi"

/**
 * Route `/alertas`: the open alerts that reach the current user, each with its
 * three ways out (ClickUp 86e3jpzcv / 86e3jpzdp). The server decides which
 * alerts are listed (`Alert::scopeVisibleTo`) and what the user may do with
 * each one (`alert.can`). The fuller «Alertas y seguimientos» screen is
 * ClickUp 86e3jdkkr.
 */
export function MyAlertsPage({ embedded = false }: { embedded?: boolean } = {}) {
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

  if (error) return <p className="text-sm text-destructive">{error}</p>
  if (!alerts) return <p className="text-muted-foreground">Cargando…</p>

  const pending = alerts.filter((alert) => alert.can?.act)
  const others = alerts.filter((alert) => !alert.can?.act)

  return (
    <div className="grid gap-6">
      {embedded ? (
        <div>
          <h2 className="text-xl font-semibold">Alertas</h2>
          <p className="text-sm text-muted-foreground">
            Una alerta no se cierra con «ya me encargué»: elegí una salida, con responsable y plazo.
          </p>
        </div>
      ) : (
        <PageHeader
          title="Alertas"
          description="Una alerta no se cierra con «ya me encargué»: elegí una salida, con responsable y plazo."
        />
      )}
      {alerts.length === 0 ? (
        <EmptyState icon={CircleCheck} message="No tenés alertas abiertas." />
      ) : (
        <>
          {pending.length > 0 && (
            <section className="grid gap-3">
              <h3 className="text-lg font-semibold">Te toca elegir una salida</h3>
              {pending.map((alert) => (
                <AlertCard key={alert.id} alert={alert} onChange={replace} />
              ))}
            </section>
          )}
          {others.length > 0 && (
            <section className="grid gap-3">
              <h3 className="text-lg font-semibold">En curso</h3>
              {others.map((alert) => (
                <AlertCard key={alert.id} alert={alert} onChange={replace} />
              ))}
            </section>
          )}
        </>
      )}
    </div>
  )
}
