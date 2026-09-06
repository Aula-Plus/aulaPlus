import { render, screen, waitFor } from "@testing-library/react"
import { MemoryRouter } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import { AdoptionDashboardPage } from "./AdoptionDashboardPage"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import * as adoptionApi from "./adoptionApi"
import type { Role } from "@/types"

function renderPage(role: Role, withSchool = true) {
  const value: AuthContextValue = {
    user: {
      id: 1,
      name: "Ana",
      email: "ana@escuela.test",
      roles: [role],
      school: withSchool ? { id: 42, name: "Escuela Piloto" } : undefined,
    },
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
  }

  return render(
    <AuthContext value={value}>
      <MemoryRouter>
        <AdoptionDashboardPage />
      </MemoryRouter>
    </AuthContext>,
  )
}

describe("AdoptionDashboardPage", () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("does not fetch and shows a notice for a non-director", () => {
    const fetchDashboard = vi.spyOn(adoptionApi, "fetchAdoptionDashboard")

    renderPage("teacher")

    expect(screen.getByText(/solo para dirección/i)).toBeInTheDocument()
    expect(fetchDashboard).not.toHaveBeenCalled()
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

  it("shows a notice when a director has no school assigned", async () => {
    const fetchDashboard = vi.spyOn(adoptionApi, "fetchAdoptionDashboard")

    renderPage("director", false)

    await waitFor(() =>
      expect(screen.getByText(/no tenés una escuela asignada/i)).toBeInTheDocument(),
    )
    expect(fetchDashboard).not.toHaveBeenCalled()
  })
})
