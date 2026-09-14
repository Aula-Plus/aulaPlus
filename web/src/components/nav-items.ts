import type { LucideIcon } from "lucide-react"
import { ClipboardList, GraduationCap, Home, TrendingUp, Users } from "lucide-react"
import type { User } from "@/types"
import {
  canApproveScreeningTestDesign,
  canManageScreeningTests,
  canViewAdoptionDashboard,
} from "@/lib/permissions"

export interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  /** Passed to `NavLink` so "/" only matches the index route exactly. */
  end?: boolean
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
        // The screening-test design screen is for psychopedagogy (manage) and
        // direction (approve) — docs/prompts/12 §4.
        ...(canManageScreeningTests(user) || canApproveScreeningTestDesign(user)
          ? [{ to: "/pruebas-de-sondeo/tipos", label: "Pruebas de sondeo", icon: ClipboardList }]
          : []),
      ],
    },
    {
      heading: "Administración",
      items: [
        // The adoption dashboard is director-only.
        ...(canViewAdoptionDashboard(user)
          ? [{ to: "/adopcion", label: "Adopción", icon: TrendingUp }]
          : []),
      ],
    },
  ]

  return sections.filter((section) => section.items.length > 0)
}
