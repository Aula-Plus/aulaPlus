import { fireEvent, render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import { AssessmentsPage } from "./AssessmentsPage"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import * as trackingApi from "@/features/tracking/trackingApi"
import * as assessmentsApi from "./assessmentsApi"
import type { Assessment, GroupTracking, Role } from "@/types"

function groupTracking(teacherIds: number[]): GroupTracking {
  return {
    group: {
      id: 1,
      name: "3° A",
      level: "Primaria",
      school_year: 2026,
      group_profile: null,
      related_documents: null,
      teachers: teacherIds.map((teacherId) => ({ id: teacherId, name: `Docente ${teacherId}` })),
    },
    students: [
      { id: 3, full_name: "Juan Pérez", open_alerts_count: 0, has_active_accommodations: false },
      { id: 4, full_name: "Ana Gómez", open_alerts_count: 0, has_active_accommodations: false },
    ],
    trend: { period_days: 30, assessments_count: 0, comments_count: 0 },
  }
}

function assessment(): Assessment {
  return {
    id: 10,
    group_id: 1,
    teacher_id: 1,
    type: "written",
    purpose: "Unidad 1",
    duration_minutes: null,
    variant_number: null,
    administered_at: "2026-09-10",
    created_at: "2026-09-10T10:00:00+00:00",
  }
}

function renderPage({ role = "teacher" as Role } = {}) {
  const value: AuthContextValue = {
    // The authed user is id 1; whether they "own" the group is decided by the
    // teachers list in the mocked group tracking response, not here.
    user: { id: 1, name: "Ana", email: "ana@escuela.test", roles: [role] },
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
  }

  return render(
    <AuthContext value={value}>
      <MemoryRouter initialEntries={["/clases/1/evaluaciones"]}>
        <Routes>
          <Route path="/clases/:id/evaluaciones" element={<AssessmentsPage />} />
        </Routes>
      </MemoryRouter>
    </AuthContext>,
  )
}

describe("AssessmentsPage", () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("submits the correct payload when creating an assessment", async () => {
    vi.spyOn(trackingApi, "fetchGroupTracking").mockResolvedValue(groupTracking([1]))
    vi.spyOn(assessmentsApi, "fetchAssessments").mockResolvedValue([])
    const createAssessment = vi
      .spyOn(assessmentsApi, "createAssessment")
      .mockResolvedValue(assessment())

    renderPage()

    await userEvent.selectOptions(await screen.findByLabelText(/tipo/i), "oral")
    // Date inputs are set deterministically via fireEvent (RHF listens to change).
    fireEvent.change(screen.getByLabelText(/fecha/i), { target: { value: "2026-09-12" } })
    await userEvent.type(screen.getByLabelText(/propósito/i), "Parcial de lengua")
    await userEvent.click(screen.getByRole("button", { name: /crear evaluación/i }))

    expect(createAssessment).toHaveBeenCalledWith(1, {
      type: "oral",
      administered_at: "2026-09-12",
      purpose: "Parcial de lengua",
    })
  })

  it("upserts results with a single POST carrying the whole array", async () => {
    vi.spyOn(trackingApi, "fetchGroupTracking").mockResolvedValue(groupTracking([1]))
    vi.spyOn(assessmentsApi, "fetchAssessments").mockResolvedValue([assessment()])
    vi.spyOn(assessmentsApi, "fetchAssessmentResults").mockResolvedValue([])
    const saveAssessmentResults = vi
      .spyOn(assessmentsApi, "saveAssessmentResults")
      .mockResolvedValue([])

    renderPage()

    await userEvent.type(await screen.findByLabelText(/nota de juan pérez/i), "8.5")
    await userEvent.type(screen.getByLabelText(/nota de ana gómez/i), "9")
    await userEvent.click(screen.getByRole("button", { name: /guardar notas/i }))

    // Exactly one request, with both students in one array — never N requests.
    expect(saveAssessmentResults).toHaveBeenCalledTimes(1)
    expect(saveAssessmentResults).toHaveBeenCalledWith(10, [
      { student_id: 3, score: 8.5, feedback: null },
      { student_id: 4, score: 9, feedback: null },
    ])
  })

  it("is read-only for a user who does not own the group (no create form, no score inputs)", async () => {
    // The authed teacher (id 1) does NOT lead this group (teacher id 2 does).
    vi.spyOn(trackingApi, "fetchGroupTracking").mockResolvedValue(groupTracking([2]))
    vi.spyOn(assessmentsApi, "fetchAssessments").mockResolvedValue([assessment()])
    vi.spyOn(assessmentsApi, "fetchAssessmentResults").mockResolvedValue([
      {
        id: 1,
        assessment_id: 10,
        student_id: 3,
        score: 7,
        feedback: "Bien",
        created_by_id: 2,
        created_at: null,
        updated_at: null,
      },
    ])

    renderPage()

    expect(await screen.findByText("Evaluaciones — 3° A")).toBeInTheDocument()
    // Existing score is shown as read-only text once the editor loads.
    expect(await screen.findByText("7")).toBeInTheDocument()

    expect(screen.queryByText(/nueva evaluación/i)).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /crear evaluación/i })).not.toBeInTheDocument()
    expect(screen.queryByLabelText(/nota de juan pérez/i)).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /guardar notas/i })).not.toBeInTheDocument()
  })
})
