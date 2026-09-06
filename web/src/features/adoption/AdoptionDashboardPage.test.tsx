import { render, screen } from "@testing-library/react"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import { AdoptionDashboardPage } from "./AdoptionDashboardPage"
import { AppLayout } from "@/components/AppLayout"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import * as adoptionApi from "./adoptionApi"
import type { Role } from "@/types"

function authValue(role: Role): AuthContextValue {
  return {
    user: {
      id: 1,
      name: "Ana",
      email: "ana@escuela.test",
      roles: [role],
      school: { id: 42, name: "Escuela Piloto" },
    },
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
  }
}

function renderPage(role: Role) {
  return render(
    <AuthContext value={authValue(role)}>
      <MemoryRouter initialEntries={["/panel-adopcion"]}>
        <Routes>
          <Route path="/" element={<div>Página de inicio</div>} />
          <Route path="/panel-adopcion" element={<AdoptionDashboardPage />} />
        </Routes>
      </MemoryRouter>
    </AuthContext>,
  )
}

describe("AdoptionDashboardPage", () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("renders the rates and weekly series for a director", async () => {
    vi.spyOn(adoptionApi, "fetchAdoptionDashboard").mockResolvedValue({
      teacher_login_rate_30d: 75,
      teacher_planning_rate_30d: 40,
      weekly_login_series: [{ week_start: "2026-08-17", count: 12 }],
      weekly_content_series: [{ week_start: "2026-08-17", count: 3 }],
    })

    renderPage("director")

    expect(await screen.findByText("75%")).toBeInTheDocument()
    expect(screen.getByText("40%")).toBeInTheDocument()
    expect(screen.getByText("Logins por semana")).toBeInTheDocument()
    expect(screen.getByText("Contenido creado por semana")).toBeInTheDocument()
    expect(screen.getByText("12")).toBeInTheDocument()
  })

  it("redirects a non-director away from the route and never fetches", () => {
    const fetchDashboard = vi.spyOn(adoptionApi, "fetchAdoptionDashboard")

    renderPage("teacher")

    expect(screen.getByText("Página de inicio")).toBeInTheDocument()
    expect(screen.queryByText(/tablero de adopción/i)).not.toBeInTheDocument()
    expect(fetchDashboard).not.toHaveBeenCalled()
  })

  it("shows the Adopción nav link only for a director", () => {
    const { rerender } = render(
      <AuthContext value={authValue("director")}>
        <MemoryRouter>
          <AppLayout>
            <p>contenido</p>
          </AppLayout>
        </MemoryRouter>
      </AuthContext>,
    )
    const link = screen.getByRole("link", { name: "Adopción" })
    expect(link).toHaveAttribute("href", "/panel-adopcion")

    rerender(
      <AuthContext value={authValue("teacher")}>
        <MemoryRouter>
          <AppLayout>
            <p>contenido</p>
          </AppLayout>
        </MemoryRouter>
      </AuthContext>,
    )
    expect(screen.queryByRole("link", { name: "Adopción" })).not.toBeInTheDocument()
  })
})
