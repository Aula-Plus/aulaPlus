import type { ReactNode } from "react"
import { NavLink } from "react-router-dom"
import { useAuth } from "@/features/auth/AuthContext"
import { Button } from "@/components/ui/button"

const navItems = [
  { to: "/", label: "Inicio" },
  { to: "/clases", label: "Clases" },
  { to: "/alumnos", label: "Alumnos" },
]

export function AppLayout({ children }: { children: ReactNode }) {
  const { logout } = useAuth()

  return (
    <div className="min-h-svh">
      <header className="bg-nav-background">
        <div className="mx-auto flex max-w-5xl items-center gap-6 p-4">
          <span className="text-sm font-bold text-nav-accent">Aula+</span>
          <nav className="flex flex-1 gap-4 text-sm font-semibold">
            {navItems.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.to === "/"}
                className={({ isActive }) =>
                  isActive ? "text-nav-accent" : "text-nav-foreground hover:text-white"
                }
              >
                {item.label}
              </NavLink>
            ))}
          </nav>
          <Button
            variant="outline"
            size="sm"
            className="border-nav-border bg-transparent text-white hover:bg-white/10 hover:text-white"
            onClick={() => logout()}
          >
            Cerrar sesión
          </Button>
        </div>
      </header>
      <main className="mx-auto max-w-5xl p-6">{children}</main>
    </div>
  )
}
