import { useAuth } from "@/features/auth/AuthContext"
import { AlertsSection } from "@/features/alerts/AlertsSection"
import { PageHeader } from "@/components/ui/page-header"
import { FollowUpsSection } from "@/features/tracking/FollowUpsSection"

/**
 * Inicio (route `/`, documento vivo screen 01 «¿Qué tenés que hacer hoy?»;
 * ClickUp 1dfd3mc2wwz). One screen for the three roles: the alerts that reach
 * the user, each with its three ways out, and their pending follow-ups with
 * their deadline. Both lists come from the same records and endpoints as
 * «Alertas y seguimientos», so what each role sees is decided server-side and
 * a row leaves as soon as the alert or follow-up is resolved (P01-DEC-02).
 * The greeting stays small, above what there is to do. The teacher's classes
 * of the day wait for the planner.
 */
export function DashboardPage() {
  const { user } = useAuth()

  if (!user) return null

  return (
    <div className="grid gap-10">
      <PageHeader title="¿Qué tenés que hacer hoy?" description={`Hola, ${user.name}`} />
      <AlertsSection compact />
      <FollowUpsSection compact />
    </div>
  )
}
