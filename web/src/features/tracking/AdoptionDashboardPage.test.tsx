import { render, screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
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
      weekly_content_by_type: [
        {
          week_start: "2026-08-17",
          annual_plans: 1,
          class_sessions: 2,
          assessments: 0,
        },
      ],
    })

    renderPage("director")

    expect(await screen.findByText("75%")).toBeInTheDocument()
    expect(screen.getByText("40%")).toBeInTheDocument()
    expect(screen.getByText("Logins por semana")).toBeInTheDocument()
    expect(screen.getByText("Contenido creado por semana")).toBeInTheDocument()
    expect(screen.getByText("12")).toBeInTheDocument()
  })

  it("shows per-teacher counts alphabetically and reveals the last login only on demand", async () => {
    vi.spyOn(adoptionApi, "fetchAdoptionDashboard").mockResolvedValue({
      teacher_login_rate_30d: 0,
      teacher_planning_rate_30d: 0,
      weekly_login_series: [],
      weekly_content_series: [],
      weekly_content_by_type: [],
    })
    vi.spyOn(adoptionApi, "fetchAdoptionTeachers").mockResolvedValue([
      {
        id: 7,
        name: "Ana Fernández",
        subjects: ["Ciencias"],
        month_counts: { annual_plans: 2, class_sessions: 4, assessments: 1 },
      },
    ])
    const lastLogin = vi
      .spyOn(adoptionApi, "fetchTeacherLastLoginRange")
      .mockResolvedValue("this_week")

    renderPage("director")
    await userEvent.click(await screen.findByRole("tab", { name: "Por docente" }))

    expect(await screen.findByText(/Ana Fernández/)).toBeInTheDocument()
    expect(screen.getByText("4 clases, 1 evaluación y 2 programas este mes")).toBeInTheDocument()
    expect(screen.queryByText(/último ingreso/i)).not.toBeInTheDocument()
    expect(lastLogin).not.toHaveBeenCalled()

    await userEvent.click(screen.getByRole("button", { name: "Ver detalles de uso" }))

    expect(await screen.findByText("Último ingreso: esta semana")).toBeInTheDocument()
    expect(lastLogin).toHaveBeenCalledWith(42, 7)
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
