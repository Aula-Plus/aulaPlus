import type { LucideIcon } from "lucide-react"
import {
  BellRing,
  BookOpen,
  ClipboardList,
  GraduationCap,
  Eye,
  FileText,
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
  canEditScreeningTestDesign,
  canManageAiReportSetting,
  canManageGradesVisibility,
  canManageAlertSettings,
  canManageCommentSettings,
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
  /**
   * A count shown next to the label (e.g. unread follow-up notices on
   * "Alertas y seguimientos"). Omitted or 0 means no count is rendered.
   */
  badge?: number
}

export interface NavSection {
  /** Optional section heading, e.g. "Administración". Hidden when the sidebar is collapsed. */
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
export function buildNavSections(
  user: User | null,
  { unreadNotices = 0 }: { unreadNotices?: number } = {},
): NavSection[] {
  const sections: NavSection[] = [
    { items: [{ to: "/", label: "Inicio", icon: Home, end: true }] },
    {
      // No heading on purpose: Grupos / Alumnos are first-class entries, not a
      // "Seguimiento" sub-section (product rule — "seguimiento" is not a section).
      // Perfil de grupo / Perfil de alumno open from these lists.
      items: [
        { to: "/clases", label: "Grupos", icon: Users },
        { to: "/alumnos", label: "Alumnos", icon: GraduationCap },
        // One screen for the alerts that reach the user and their own scheduled
        // follow-ups, for every role (ClickUp 86e3jpzdp / 86e3jdkkr). The count
        // is unread notices only (not alerts); it goes away once read.
        { to: "/alertas", label: "Alertas y seguimientos", icon: TriangleAlert, badge: unreadNotices },
        // The screening design screen is for Psicopedagogía and Dirección,
        // who both edit designs (screen 08).
        ...(canEditScreeningTestDesign(user)
          ? [{ to: "/pruebas-de-sondeo/tipos", label: "Screenings", icon: ClipboardList }]
          : []),
        // Read-only subjects list for everyone but the director (who manages
        // it under Administración): teachers open their subject's program PDF
        // from there (ClickUp 86e3dt6ag).
        ...(user && !canManageSubjects(user)
          ? [{ to: "/materias", label: "Materias", icon: BookOpen }]
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
        // Grade visibility for teachers is director-only.
        ...(canManageGradesVisibility(user)
          ? [{ to: "/ajustes/notas", label: "Visibilidad de calificaciones", icon: Eye }]
          : []),
        // Switch for the AI reading of student reports: director-only.
        ...(canManageAiReportSetting(user)
          ? [{ to: "/ajustes/informes-ia", label: "IA en informes", icon: FileText }]
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
