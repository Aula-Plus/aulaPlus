import { useAuth } from "@/features/auth/AuthContext"
import { PageHeader } from "@/components/ui/page-header"
import { canManageAiReportSetting } from "@/lib/permissions"
import { AiReportSettingPanel } from "@/features/reports/AiReportSettingPage"
import { UsersTabs } from "./UsersTabs"
import { NoAccessPage } from "@/pages/NoAccessPage"

/**
 * «Usuarios y permisos › Límites» (route `/usuarios/limites`, screen 25 of the
 * living document, P25-LIM-01). For now it only holds the school-wide switch
 * for the AI reading of student reports. Director-only; the server enforces it.
 */
export function LimitsPage() {
  const { user } = useAuth()

  if (!canManageAiReportSetting(user)) {
    return <NoAccessPage />
  }

  return (
    <div className="grid gap-6">
      <PageHeader title="Usuarios y permisos" />
      <UsersTabs />
      <AiReportSettingPanel />
    </div>
  )
}
