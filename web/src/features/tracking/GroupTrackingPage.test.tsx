import { render, screen } from "@testing-library/react"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import { GroupTrackingPage } from "./GroupTrackingPage"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import * as trackingApi from "./trackingApi"
import type { GroupTracking, Role } from "@/types"

function tracking(): GroupTracking {
  return {
    group: {
      id: 1,
      name: "3° A",
      level: "Primaria",
      school_year: 2026,
      group_profile: null,
      related_documents: null,
      education_level: null,
      school_grade: null,
      teachers: [],
    },
    students: [
      { id: 3, full_name: "Juan Pérez", open_alerts_count: 2, has_active_accommodations: true },
      { id: 4, full_name: "Ana Gómez", open_alerts_count: 0, has_active_accommodations: false },
    ],
    trend: { period_days: 30, comments_count: 8 },
  }
}

function renderPage(role: Role = "teacher") {
  const value: AuthContextValue = {
    user: { id: 1, name: "Ana", email: "ana@escuela.test", roles: [role] },
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
  }

  return render(
    <AuthContext value={value}>
      <MemoryRouter initialEntries={["/clases/1/seguimiento"]}>
        <Routes>
          <Route path="/clases/:id/seguimiento" element={<GroupTrackingPage />} />
        </Routes>
      </MemoryRouter>
    </AuthContext>,
  )
}

describe("GroupTrackingPage", () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("renders per-student summary rows with the accommodation flag for psychopedagogy", async () => {
    vi.spyOn(trackingApi, "fetchGroupTracking").mockResolvedValue(tracking())
    vi.spyOn(trackingApi, "fetchGroupComments").mockResolvedValue([])
    vi.spyOn(trackingApi, "fetchGroupAlerts").mockResolvedValue([])

    renderPage("psychopedagogue")

    expect(await screen.findByText("Perfil del grupo — 3° A")).toBeInTheDocument()
    expect(screen.getByRole("columnheader", { name: "Ajustes activos" })).toBeInTheDocument()

    const juanRow = screen.getByText("Juan Pérez").closest("tr")
    expect(juanRow).not.toBeNull()
    expect(juanRow!.textContent).toContain("Sí")

    const anaRow = screen.getByText("Ana Gómez").closest("tr")
    expect(anaRow!.textContent).toContain("No")

    const link = screen.getAllByRole("link", { name: /ver alumno/i })[0]
    expect(link).toHaveAttribute("href", "/alumnos/3/seguimiento")
  })

  it("does not show the accommodations column to a teacher", async () => {
    const data = tracking()
    data.students = data.students.map(({ has_active_accommodations: _omitted, ...rest }) => rest)
    vi.spyOn(trackingApi, "fetchGroupTracking").mockResolvedValue(data)
    vi.spyOn(trackingApi, "fetchGroupComments").mockResolvedValue([])
    vi.spyOn(trackingApi, "fetchGroupAlerts").mockResolvedValue([])

    renderPage("teacher")

    expect(await screen.findByText("Perfil del grupo — 3° A")).toBeInTheDocument()
    expect(screen.queryByRole("columnheader", { name: "Ajustes activos" })).not.toBeInTheDocument()
    expect(screen.getByText("Juan Pérez").closest("tr")!.textContent).not.toMatch(/Sí|No/)
    expect(screen.getByRole("columnheader", { name: "Alertas abiertas" })).toBeInTheDocument()
  })

  it("no longer shows the trend counter nor the 'Comentarios cargados' card", async () => {
    vi.spyOn(trackingApi, "fetchGroupTracking").mockResolvedValue(tracking())
    vi.spyOn(trackingApi, "fetchGroupComments").mockResolvedValue([])
    vi.spyOn(trackingApi, "fetchGroupAlerts").mockResolvedValue([])

    renderPage("psychopedagogue")

    expect(await screen.findByText("Perfil del grupo — 3° A")).toBeInTheDocument()
    expect(screen.queryByText("Evaluaciones tomadas")).not.toBeInTheDocument()
    expect(screen.queryByText(/Tendencia de los últimos/)).not.toBeInTheDocument()
    expect(screen.queryByText("Comentarios cargados")).not.toBeInTheDocument()
    expect(screen.queryByText("Alertas del grupo")).not.toBeInTheDocument()
  })
})
