import { PageHeader } from "@/components/ui/page-header"
import { FollowUpsSection } from "@/features/tracking/FollowUpsSection"
import { AlertsSection } from "./AlertsSection"

/**
 * Route `/alertas`: «Alertas y seguimientos» (documento vivo screen 11; ClickUp
 * 86e3jdkkr) — one screen with the alerts that reach the user (each with its
 * three ways out) and their scheduled follow-ups («Mis seguimientos»). What
 * each list contains is decided server-side per role; this page only composes
 * the two sections (h1 page → h2 sections → h3 subsections).
 */
export function AlertsAndFollowUpsPage() {
  return (
    <div className="grid gap-10">
      <PageHeader
        title="Alertas y seguimientos"
        description="Lo que necesita tu atención sobre tus alumnos, en un solo lugar."
      />
      <AlertsSection />
      <FollowUpsSection />
    </div>
  )
}
