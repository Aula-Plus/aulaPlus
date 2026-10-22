import { fireEvent, render, screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { AssessmentsPage } from "./AssessmentsPage"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import * as trackingApi from "@/features/tracking/trackingApi"
import * as subjectsApi from "@/features/subjects/subjectsApi"
import * as assessmentsApi from "./assessmentsApi"
import { api } from "@/lib/api"
import type { Assessment, GroupTeacherAssignment, GroupTracking, Role } from "@/types"

/** One teacher-subject assignment for the authed teacher (id 1) in the group. */
function assignments(): GroupTeacherAssignment[] {
  return [{ teacher_id: 1, teacher_name: "Docente 1", subject_id: 3, subject_name: "Matemática" }]
}

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
    subject_id: 3,
    subject_name: "Matemática",
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

interface ReportedStep {
  journey_id: string
  step: string
  content_type: string
}

/** The creation-journey steps the form reported (POST /usage/journey-steps). */
function journeySteps(post: { mock: { calls: unknown[][] } }): ReportedStep[] {
  return post.mock.calls
    .filter(([url]) => url === "/api/v1/usage/journey-steps")
    .map(([, body]) => body as ReportedStep)
}

describe("AssessmentsPage", () => {
  beforeEach(() => {
    vi.spyOn(api, "post").mockResolvedValue({ data: null })
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("reports form_opened and filling_started once per journey and links the save to it", async () => {
    const post = vi.mocked(api.post)
    vi.spyOn(trackingApi, "fetchGroupTracking").mockResolvedValue(groupTracking([1]))
    vi.spyOn(assessmentsApi, "fetchAssessments").mockResolvedValue([])
    vi.spyOn(subjectsApi, "fetchGroupAssignments").mockResolvedValue(assignments())
    const createAssessment = vi
      .spyOn(assessmentsApi, "createAssessment")
      .mockResolvedValue(assessment())

    renderPage()

    // Nothing is reported just by landing on the page.
    await screen.findByRole("button", { name: /crear evaluación/i })
    expect(journeySteps(post)).toEqual([])

    await userEvent.click(screen.getByLabelText(/propósito/i))
    await userEvent.type(screen.getByLabelText(/propósito/i), "Repaso")
    await userEvent.click(screen.getByLabelText(/materia/i))
    await userEvent.click(await screen.findByRole("button", { name: "Matemática" }))

    const steps = journeySteps(post)
    expect(steps.map((step) => step.step)).toEqual(["form_opened", "filling_started"])
    expect(steps.every((step) => step.content_type === "assessment")).toBe(true)
    const journeyId = steps[0].journey_id
    expect(journeyId).toMatch(/^[0-9a-f-]{36}$/)
    expect(steps[1].journey_id).toBe(journeyId)
    // Only ids and step names travel — never what was typed.
    expect(JSON.stringify(steps)).not.toContain("Repaso")

    await userEvent.click(screen.getByRole("button", { name: /crear evaluación/i }))
    await waitFor(() =>
      expect(createAssessment).toHaveBeenCalledWith(
        1,
        expect.objectContaining({ journey_id: journeyId }),
      ),
    )

    // After saving, the next creation is a new journey.
    await userEvent.type(screen.getByLabelText(/propósito/i), "Otra")
    await waitFor(() => expect(journeySteps(post)).toHaveLength(4))
    const next = journeySteps(post).slice(2)
    expect(next.map((step) => step.step)).toEqual(["form_opened", "filling_started"])
    expect(next[0].journey_id).not.toBe(journeyId)
  })

  it("submits the correct payload when creating an assessment", async () => {
    vi.spyOn(trackingApi, "fetchGroupTracking").mockResolvedValue(groupTracking([1]))
    vi.spyOn(assessmentsApi, "fetchAssessments").mockResolvedValue([])
    vi.spyOn(subjectsApi, "fetchGroupAssignments").mockResolvedValue(assignments())
    const createAssessment = vi
      .spyOn(assessmentsApi, "createAssessment")
      .mockResolvedValue(assessment())

    renderPage()

    await userEvent.click(await screen.findByLabelText(/tipo/i))
    await userEvent.click(await screen.findByRole("button", { name: "Oral" }))
    // The subject picker is required (backend Sesión 3): pick the one subject
    // this teacher is assigned in the group.
    await userEvent.click(screen.getByLabelText(/materia/i))
    await userEvent.click(await screen.findByRole("button", { name: "Matemática" }))
    // Date inputs are set deterministically via fireEvent (RHF listens to change).
    fireEvent.change(screen.getByLabelText(/fecha/i), { target: { value: "2026-09-12" } })
    await userEvent.type(screen.getByLabelText(/propósito/i), "Parcial de lengua")
    await userEvent.click(screen.getByRole("button", { name: /crear evaluación/i }))

    await waitFor(() =>
      expect(createAssessment).toHaveBeenCalledWith(1, {
        type: "oral",
        subject_id: 3,
        administered_at: "2026-09-12",
        purpose: "Parcial de lengua",
        journey_id: expect.any(String),
      }),
    )
  })

  it("blocks submitting without a subject and sends subject_id once one is chosen", async () => {
    vi.spyOn(trackingApi, "fetchGroupTracking").mockResolvedValue(groupTracking([1]))
    vi.spyOn(assessmentsApi, "fetchAssessments").mockResolvedValue([])
    vi.spyOn(subjectsApi, "fetchGroupAssignments").mockResolvedValue(assignments())
    const createAssessment = vi
      .spyOn(assessmentsApi, "createAssessment")
      .mockResolvedValue(assessment())

    renderPage()

    // Submitting without a subject is blocked client-side.
    await userEvent.click(await screen.findByRole("button", { name: /crear evaluación/i }))
    expect(await screen.findByText("Elegí una materia")).toBeInTheDocument()
    expect(createAssessment).not.toHaveBeenCalled()

    // Choose the subject and the write goes through carrying subject_id.
    await userEvent.click(screen.getByLabelText(/materia/i))
    await userEvent.click(await screen.findByRole("button", { name: "Matemática" }))
    await userEvent.click(screen.getByRole("button", { name: /crear evaluación/i }))

    await waitFor(() =>
      expect(createAssessment).toHaveBeenCalledWith(
        1,
        expect.objectContaining({ subject_id: 3 }),
      ),
    )
  })

  it("upserts results with a single POST carrying the whole array", async () => {
    vi.spyOn(trackingApi, "fetchGroupTracking").mockResolvedValue(groupTracking([1]))
    vi.spyOn(assessmentsApi, "fetchAssessments").mockResolvedValue([assessment()])
    vi.spyOn(assessmentsApi, "fetchAssessmentResults").mockResolvedValue([])
    vi.spyOn(subjectsApi, "fetchGroupAssignments").mockResolvedValue(assignments())
    const saveAssessmentResults = vi
      .spyOn(assessmentsApi, "saveAssessmentResults")
      .mockResolvedValue([])

    renderPage()

    // Assessment cards start collapsed; expand it to reach the results editor.
    await userEvent.click(await screen.findByRole("button", { name: /escrita/i }))
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
    vi.spyOn(subjectsApi, "fetchGroupAssignments").mockResolvedValue(assignments())
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
    // Assessment cards start collapsed; expand it to reach the results editor.
    await userEvent.click(await screen.findByRole("button", { name: /escrita/i }))
    // Existing score is shown as read-only text once the editor loads.
    expect(await screen.findByText("7")).toBeInTheDocument()

    expect(screen.queryByText(/nueva evaluación/i)).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /crear evaluación/i })).not.toBeInTheDocument()
    expect(screen.queryByLabelText(/nota de juan pérez/i)).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /guardar notas/i })).not.toBeInTheDocument()
  })
})
