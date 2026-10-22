import { useEffect, useId, useState } from "react"
import { CircleCheck } from "lucide-react"
import { EmptyState } from "@/components/ui/empty-state"
import type { Alert } from "@/types"
import { AlertCard } from "./AlertCard"
import { isInChargeOfAlert } from "./alertHolder"
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
    // Refresh the list quietly: a way out may also close a legacy escalated
    // alert, and the server decides what this user may do now.
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

function AlertGroup({
  title,
  alerts,
  onChange,
}: {
  title: string
  alerts: Alert[]
  onChange: (updated: Alert) => void
}) {
  if (alerts.length === 0) return null
  return (
    <section className="grid gap-3">
      <h3 className="text-lg font-semibold">{title}</h3>
      {alerts.map((alert) => (
        <AlertCard key={alert.id} alert={alert} onChange={onChange} />
      ))}
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

  // Grouped as in screen 11 (decision of 21/10, option A: each one reads their
  // own situation). An alert the viewer already has — a way out chosen and
  // they are the one responsible, e.g. because it was handed to them — is «a
  // tu cargo», never under «Te toca elegir una salida»: nothing is left to
  // choose. «Te toca elegir una salida» is only the new ones nobody has yet.
  const inCharge: Alert[] = []
  const pending: Alert[] = []
  const others: Alert[] = []
  for (const alert of alerts) {
    if (isInChargeOfAlert(alert)) inCharge.push(alert)
    else if (alert.can?.act) pending.push(alert)
    else others.push(alert)
  }

  return (
    <>
      <AlertGroup title="Te toca elegir una salida" alerts={pending} onChange={onChange} />
      <AlertGroup title="A tu cargo" alerts={inCharge} onChange={onChange} />
      <AlertGroup title="En curso" alerts={others} onChange={onChange} />
    </>
  )
}
