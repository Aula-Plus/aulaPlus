import { PageHeader } from "@/components/ui/page-header"
import { MyFollowUpsPage } from "@/features/tracking/MyFollowUpsPage"
import { MyAlertsPage } from "./MyAlertsPage"

/**
 * Route `/alertas`: «Alertas y seguimientos» (documento vivo screen 11; ClickUp
 * 86e3jdkkr) — one screen with the alerts that reach the user (each with its
 * three ways out) and their scheduled follow-ups («Mis seguimientos»). What
 * each list contains is decided server-side per role; this page only composes.
 */
export function AlertsAndFollowUpsPage() {
  return (
    <div className="grid gap-10">
      <PageHeader
        title="Alertas y seguimientos"
        description="Lo que necesita tu atención sobre tus alumnos, en un solo lugar."
      />
      <MyAlertsPage embedded />
      <MyFollowUpsPage embedded />
    </div>
  )
}
