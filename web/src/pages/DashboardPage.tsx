import { useAuth } from "@/features/auth/AuthContext"
import { AlertsSection } from "@/features/alerts/AlertsSection"
import { PageHeader } from "@/components/ui/page-header"
import { FollowUpNotices } from "@/features/tracking/FollowUpNotices"
import { FollowUpsSectionView } from "@/features/tracking/FollowUpsSection"
import { useFollowUpsInbox } from "@/features/tracking/useFollowUpsInbox"
import { formatLongDate } from "@/lib/utils"

/**
 * Inicio (route `/`, documento vivo screen 01 «¿Qué tenés que hacer hoy?»;
 * ClickUp 1dfd3mc2wwz). One screen for the three roles: the alerts that reach
 * the user, each with its three ways out, and their pending follow-ups with
 * their deadline. Both lists come from the same records and endpoints as
 * «Alertas y seguimientos», so what each role sees is decided server-side and
 * a row leaves as soon as the alert or follow-up is resolved (P01-DEC-02).
 *
 * Alerts and notices together, tasks apart (P01-DEC-03): the «Avisos» go in
 * the «Alertas» block, below the alerts; «Seguimientos» is only the work with
 * a deadline. The page owns the follow-ups/notices state so both blocks share
 * one load, and an alert's way out (which may link a follow-up) reloads it.
 *
 * The greeting stays small, above what there is to do. The teacher's classes
 * of the day wait for the planner.
 */
export function DashboardPage() {
  const { user } = useAuth()
  const inbox = useFollowUpsInbox()

  if (!user) return null

  return (
    <div className="grid gap-10">
      <div className="grid gap-1">
        <p className="text-sm text-muted-foreground">Hola, {user.name}</p>
        <PageHeader title="¿Qué tenés que hacer hoy?" description={formatLongDate(new Date())} />
      </div>
      <AlertsSection compact onAlertChanged={() => void inbox.reload()}>
        <FollowUpNotices inbox={inbox} showError />
      </AlertsSection>
      <FollowUpsSectionView inbox={inbox} compact />
    </div>
  )
}
