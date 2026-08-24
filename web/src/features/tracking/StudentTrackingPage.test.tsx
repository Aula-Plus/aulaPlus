import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import { StudentTrackingPage } from "./StudentTrackingPage"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import * as trackingApi from "./trackingApi"
import type { Role, StudentTracking } from "@/types"

function baseTracking(): StudentTracking {
  return {
    student: {
      id: 3,
      full_name: "Juan Pérez",
      photo_url: null,
      birth_date: "2015-03-01",
      enrollment_year: 2024,
      has_therapeutic_companion: true,
      groups: [{ id: 1, name: "3° A", school_year: 2026 }],
    },
    recent_assessments: [
      { id: 10, group_id: 1, type: "written", variant_number: 2, created_at: "2026-08-01T10:00:00+00:00" },
    ],
    accommodations_count: 1,
    barriers_count: 0,
    recent_comments: [],
    open_alerts_count: 1,
  }
}

function renderPage(role: Role) {
  const value: AuthContextValue = {
    user: { id: 1, name: "Ana", email: "ana@escuela.test", roles: [role] },
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
  }

  return render(
    <AuthContext value={value}>
      <MemoryRouter initialEntries={["/alumnos/3/seguimiento"]}>
        <Routes>
          <Route path="/alumnos/:id/seguimiento" element={<StudentTrackingPage />} />
        </Routes>
      </MemoryRouter>
    </AuthContext>,
  )
}

describe("StudentTrackingPage", () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("renders the student header, indicators and a clinical alert with a resolve button for a school-wide role", async () => {
    const tracking = baseTracking()
    tracking.alerts = [
      {
        id: 5,
        student_id: 3,
        type: "behavior",
        severity: "medium",
        description: "Acumuló observaciones preocupantes.",
        resolved: false,
        resolved_by_id: null,
        resolved_at: null,
        created_at: "2026-08-10T10:00:00+00:00",
      },
    ]
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])
    const resolveAlert = vi.spyOn(trackingApi, "resolveAlert").mockResolvedValue({
      ...tracking.alerts[0],
      resolved: true,
    })

    renderPage("psychopedagogue")

    expect(await screen.findByText("Seguimiento — Juan Pérez")).toBeInTheDocument()
    expect(screen.getByText("Acumuló observaciones preocupantes.")).toBeInTheDocument()

    await userEvent.click(screen.getByRole("button", { name: /resolver/i }))
    expect(resolveAlert).toHaveBeenCalledWith(5)
  })

  it("shows an alert count only (no detail, no resolve) when the viewer lacks clinical access", async () => {
    // A teacher's payload omits the clinical arrays entirely (server-side gate).
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(baseTracking())
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

    renderPage("teacher")

    expect(await screen.findByText("Seguimiento — Juan Pérez")).toBeInTheDocument()
    expect(screen.getByText(/no tenés permiso para ver el detalle clínico/i)).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /resolver/i })).not.toBeInTheDocument()
  })

  it("creates a comment and reloads the comment list", async () => {
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(baseTracking())
    const fetchComments = vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])
    const createComment = vi
      .spyOn(trackingApi, "createStudentComment")
      .mockResolvedValue({
        id: 9,
        author_id: 1,
        commentable_type: "Student",
        commentable_id: 3,
        content: "Nueva observación",
        tone: null,
        visible_to: null,
        created_at: "2026-08-20T10:00:00+00:00",
      })

    renderPage("teacher")

    await screen.findByText("Seguimiento — Juan Pérez")
    await userEvent.type(screen.getByLabelText(/nuevo comentario/i), "Nueva observación")
    await userEvent.click(screen.getByRole("button", { name: /comentar/i }))

    expect(createComment).toHaveBeenCalledWith(3, { content: "Nueva observación", tone: null })
    // loadComments runs once on mount and again after creating.
    expect(fetchComments).toHaveBeenCalledTimes(2)
  })
})
