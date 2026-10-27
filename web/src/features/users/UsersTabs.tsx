import { Link, useLocation } from "react-router-dom"
import { useAuth } from "@/features/auth/AuthContext"
import { canManageAiReportSetting } from "@/lib/permissions"
import { cn } from "@/lib/utils"

const LIMITS_PATH = "/usuarios/limites"

/**
 * Tab bar of «Usuarios y permisos». «Límites» is director-only (UX gate; the
 * endpoints behind it enforce the real rule). «Usuarios» stays active on its
 * nested create/edit routes, so it is simply "not Límites".
 */
export function UsersTabs() {
  const { user } = useAuth()
  const { pathname } = useLocation()
  if (!canManageAiReportSetting(user)) return null

  const onLimits = pathname === LIMITS_PATH
  const tabs = [
    { to: "/usuarios", label: "Usuarios", active: !onLimits },
    { to: LIMITS_PATH, label: "Límites", active: onLimits },
  ]

  return (
    <nav aria-label="Secciones de usuarios y permisos" className="flex gap-1 border-b">
      {tabs.map((tab) => (
        <Link
          key={tab.to}
          to={tab.to}
          aria-current={tab.active ? "page" : undefined}
          className={cn(
            "-mb-px border-b-2 px-3 py-2 text-sm font-medium",
            tab.active
              ? "border-primary text-foreground"
              : "border-transparent text-muted-foreground hover:text-foreground",
          )}
        >
          {tab.label}
        </Link>
      ))}
    </nav>
  )
}
