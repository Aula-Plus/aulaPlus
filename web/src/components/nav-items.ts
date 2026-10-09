import type { LucideIcon } from "lucide-react"
import {
  BellRing,
  BookOpen,
  CalendarClock,
  ClipboardList,
  GraduationCap,
  MessageSquare,
  Home,
  TriangleAlert,
  LifeBuoy,
  TrendingUp,
  UserCog,
  Users,
} from "lucide-react"
import type { User } from "@/types"
import {
  canApproveScreeningTestDesign,
  canManageAlertSettings,
  canManageCommentSettings,
  canManageScreeningTests,
  canManageSubjects,
  canManageUsers,
  canViewAdoptionDashboard,
} from "@/lib/permissions"

export interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  /** Passed to `NavLink` so "/" only matches the index route exactly. */
  end?: boolean
  /** Passes the originating screen to the target (e.g. the support form). */
  passFrom?: boolean
}

export interface NavSection {
  /** Optional section heading, e.g. "Seguimiento". Hidden when the sidebar is collapsed. */
  heading?: string
  items: NavItem[]
}

/**
 * Build the sidebar navigation as data, filtered by role.
 *
 * The role gating here is UX only — it mirrors the backend Policies the same
 * way `web/src/lib/permissions.ts` does, and never decides real access (the
 * server enforces that on every request; see `CLAUDE.md` → "Non-negotiable").
 *
 * A section is only included when it has at least one visible item, so a role
 * never sees an empty section heading.
 */
export function buildNavSections(user: User | null): NavSection[] {
  const sections: NavSection[] = [
    { items: [{ to: "/", label: "Inicio", icon: Home, end: true }] },
    {
      heading: "Seguimiento",
      items: [
        { to: "/clases", label: "Clases", icon: Users },
        { to: "/alumnos", label: "Alumnos", icon: GraduationCap },
        // The open alerts that reach the user, for every role (ClickUp 86e3jpzdp).
        { to: "/alertas", label: "Alertas", icon: TriangleAlert },
        { to: "/mis-seguimientos", label: "Mis seguimientos", icon: CalendarClock },
        // The screening-test design screen is for psychopedagogy (manage) and
        // direction (approve) — docs/prompts/12 §4.
        ...(canManageScreeningTests(user) || canApproveScreeningTestDesign(user)
          ? [{ to: "/pruebas-de-sondeo/tipos", label: "Screenings", icon: ClipboardList }]
          : []),
      ],
    },
    {
      heading: "Administración",
      items: [
        // Staff management is director-only (mirror of UserPolicy).
        ...(canManageUsers(user)
          ? [{ to: "/usuarios", label: "Usuarios", icon: UserCog }]
          : []),
        // The subjects catalog is director-only (mirror of SubjectPolicy).
        ...(canManageSubjects(user)
          ? [{ to: "/materias", label: "Materias", icon: BookOpen }]
          : []),
        // Alert conditions and routing: direction and psychopedagogy
        // (mirror of AlertRulePolicy) — ClickUp 86e3jpzcv.
        ...(canManageAlertSettings(user)
          ? [{ to: "/configuracion/alertas", label: "Configurar alertas", icon: BellRing }]
          : []),
        // Comment categories + trend threshold: director-only.
        ...(canManageCommentSettings(user)
          ? [{ to: "/ajustes/comentarios", label: "Comentarios", icon: MessageSquare }]
          : []),
        // The adoption dashboard is director-only.
        ...(canViewAdoptionDashboard(user)
          ? [{ to: "/adopcion", label: "Adopción", icon: TrendingUp }]
          : []),
      ],
    },
    // Fixed item for every role: support / improvement form.
    {
      items: [{ to: "/ayuda", label: "Ayuda y sugerencias", icon: LifeBuoy, passFrom: true }],
    },
  ]

  return sections.filter((section) => section.items.length > 0)
}
