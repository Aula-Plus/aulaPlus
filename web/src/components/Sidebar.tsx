import { useEffect, useState } from "react"
import { NavLink, useLocation } from "react-router-dom"
import { LogOut, Menu, PanelLeftClose, PanelLeftOpen, X } from "lucide-react"
import { useAuth } from "@/features/auth/AuthContext"
import { buildNavSections } from "@/components/nav-items"
import { roleLabels } from "@/types"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"

const COLLAPSED_STORAGE_KEY = "aulaplus:sidebar-collapsed"

function readCollapsed(): boolean {
  try {
    return localStorage.getItem(COLLAPSED_STORAGE_KEY) === "1"
  } catch {
    return false
  }
}

/**
 * Primary navigation. A persistent left sidebar on desktop (collapsible to
 * icons, preference persisted in `localStorage`); a hamburger-triggered drawer
 * on screens narrower than `lg`. The hamburger is the responsive fallback only,
 * not the primary desktop pattern.
 *
 * Role gating lives in `buildNavSections` and is UX only — the server enforces
 * real access on every request (see `CLAUDE.md` → "Non-negotiable").
 */
export function Sidebar() {
  const [collapsed, setCollapsed] = useState(readCollapsed)
  const [mobileOpen, setMobileOpen] = useState(false)
  const location = useLocation()

  // Close the mobile drawer whenever the route changes.
  useEffect(() => {
    setMobileOpen(false)
  }, [location.pathname])

  // Let Escape close the mobile drawer.
  useEffect(() => {
    if (!mobileOpen) return
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") setMobileOpen(false)
    }
    window.addEventListener("keydown", onKeyDown)
    return () => window.removeEventListener("keydown", onKeyDown)
  }, [mobileOpen])

  const toggleCollapsed = () => {
    setCollapsed((prev) => {
      const next = !prev
      try {
        localStorage.setItem(COLLAPSED_STORAGE_KEY, next ? "1" : "0")
      } catch {
        // Ignore storage failures (e.g. private mode); state still toggles.
      }
      return next
    })
  }

  return (
    <>
      {/* Mobile top bar (hidden on desktop) */}
      <div className="fixed inset-x-0 top-0 z-30 flex h-14 items-center gap-3 bg-nav-background px-4 lg:hidden">
        <Button
          variant="ghost"
          size="icon"
          className="text-nav-foreground hover:bg-white/10 hover:text-white"
          aria-label="Abrir menú"
          aria-expanded={mobileOpen}
          onClick={() => setMobileOpen(true)}
        >
          <Menu />
        </Button>
        <span className="text-sm font-bold text-nav-accent">Aula+</span>
      </div>

      {/* Desktop persistent sidebar */}
      <aside
        className={cn(
          "sticky top-0 hidden h-svh shrink-0 flex-col bg-nav-background transition-[width] lg:flex",
          collapsed ? "w-16" : "w-60",
        )}
      >
        <SidebarContent
          collapsed={collapsed}
          onToggleCollapsed={toggleCollapsed}
        />
      </aside>

      {/* Mobile drawer */}
      {mobileOpen && (
        <div className="lg:hidden">
          <div
            className="fixed inset-0 z-40 bg-black/50"
            aria-hidden="true"
            onClick={() => setMobileOpen(false)}
          />
          <aside className="fixed inset-y-0 left-0 z-50 flex w-64 flex-col bg-nav-background">
            <SidebarContent
              collapsed={false}
              onCloseMobile={() => setMobileOpen(false)}
            />
          </aside>
        </div>
      )}
    </>
  )
}

interface SidebarContentProps {
  collapsed: boolean
  /** Present on the desktop sidebar: renders the collapse toggle. */
  onToggleCollapsed?: () => void
  /** Present on the mobile drawer: renders the close button. */
  onCloseMobile?: () => void
}

function SidebarContent({ collapsed, onToggleCollapsed, onCloseMobile }: SidebarContentProps) {
  const { user, logout } = useAuth()
  const sections = buildNavSections(user)

  return (
    <div className="flex h-full flex-col">
      {/* Header: brand + collapse/close control */}
      <div className="flex h-14 items-center gap-2 px-3">
        {!collapsed && <span className="flex-1 text-sm font-bold text-nav-accent">Aula+</span>}
        {onToggleCollapsed && (
          <Button
            variant="ghost"
            size="icon"
            className={cn("text-nav-foreground hover:bg-white/10 hover:text-white", collapsed && "mx-auto")}
            aria-label={collapsed ? "Expandir menú" : "Contraer menú"}
            aria-expanded={!collapsed}
            onClick={onToggleCollapsed}
          >
            {collapsed ? <PanelLeftOpen /> : <PanelLeftClose />}
          </Button>
        )}
        {onCloseMobile && (
          <Button
            variant="ghost"
            size="icon"
            className="text-nav-foreground hover:bg-white/10 hover:text-white"
            aria-label="Cerrar menú"
            onClick={onCloseMobile}
          >
            <X />
          </Button>
        )}
      </div>

      {/* Navigation sections */}
      <nav className="flex-1 space-y-4 overflow-y-auto px-2 py-2">
        {sections.map((section, index) => (
          <div key={section.heading ?? `section-${index}`} className="space-y-1">
            {section.heading && !collapsed && (
              <h2 className="px-2 text-xs font-semibold tracking-wide text-nav-foreground/70 uppercase">
                {section.heading}
              </h2>
            )}
            {section.items.map((item) => {
              const Icon = item.icon
              return (
                <NavLink
                  key={item.to}
                  to={item.to}
                  end={item.end}
                  title={collapsed ? item.label : undefined}
                  className={({ isActive }) =>
                    cn(
                      "flex items-center gap-3 rounded-md px-2 py-2 text-sm font-semibold transition-colors",
                      collapsed && "justify-center",
                      isActive
                        ? "bg-white/10 text-nav-accent"
                        : "text-nav-foreground hover:bg-white/10 hover:text-white",
                    )
                  }
                >
                  <Icon className="size-5 shrink-0" aria-hidden="true" />
                  {!collapsed && <span>{item.label}</span>}
                </NavLink>
              )
            })}
          </div>
        ))}
      </nav>

      {/* Footer: user + logout */}
      <div className="border-t border-nav-border p-2">
        {!collapsed && user && (
          <div className="px-2 pb-2">
            <p className="truncate text-sm font-semibold text-white">{user.name}</p>
            <p className="truncate text-xs text-nav-foreground">
              {user.roles.map((role) => roleLabels[role]).join(", ")}
            </p>
          </div>
        )}
        <Button
          variant="ghost"
          className={cn(
            "w-full text-nav-foreground hover:bg-white/10 hover:text-white",
            collapsed ? "justify-center px-0" : "justify-start",
          )}
          title={collapsed ? "Cerrar sesión" : undefined}
          onClick={() => logout()}
        >
          <LogOut className="size-5 shrink-0" aria-hidden="true" />
          {!collapsed && <span>Cerrar sesión</span>}
        </Button>
      </div>
    </div>
  )
}
