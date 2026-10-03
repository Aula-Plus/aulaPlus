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
      teachers: [],
    },
    students: [
      { id: 3, full_name: "Juan Pérez", open_alerts_count: 2, has_active_accommodations: true },
      { id: 4, full_name: "Ana Gómez", open_alerts_count: 0, has_active_accommodations: false },
    ],
    trend: { period_days: 30, assessments_count: 5, comments_count: 8 },
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

  it("renders the trend, per-student summary rows and a per-student tracking link", async () => {
    vi.spyOn(trackingApi, "fetchGroupTracking").mockResolvedValue(tracking())
    vi.spyOn(trackingApi, "fetchGroupComments").mockResolvedValue([])

    renderPage()

    expect(await screen.findByText("Seguimiento — 3° A")).toBeInTheDocument()
    // Trend counts.
    expect(screen.getByText("5")).toBeInTheDocument()
    expect(screen.getByText("8")).toBeInTheDocument()

    const juanRow = screen.getByText("Juan Pérez").closest("tr")
    expect(juanRow).not.toBeNull()
    expect(juanRow!.textContent).toContain("Sí")

    const anaRow = screen.getByText("Ana Gómez").closest("tr")
    expect(anaRow!.textContent).toContain("No")

    const link = screen.getAllByRole("link", { name: /ver seguimiento/i })[0]
    expect(link).toHaveAttribute("href", "/alumnos/3/seguimiento")
  })

  it("hides the 'Comentarios cargados' card when comments_count is absent", async () => {
    // The backend omits comments_count for a viewer without a school-wide role
    // (docs/prompts/19-comentarios-alcance.md §5); the card must not render a
    // misleading 0.
    const data = tracking()
    delete data.trend.comments_count
    vi.spyOn(trackingApi, "fetchGroupTracking").mockResolvedValue(data)
    vi.spyOn(trackingApi, "fetchGroupComments").mockResolvedValue([])

    renderPage("teacher")

    expect(await screen.findByText("Seguimiento — 3° A")).toBeInTheDocument()
    expect(screen.getByText("5")).toBeInTheDocument()
    expect(screen.getByText("Evaluaciones tomadas")).toBeInTheDocument()
    expect(screen.queryByText("Comentarios cargados")).not.toBeInTheDocument()
  })
})
