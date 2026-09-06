import { render, screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import { StudentTrackingPage } from "./StudentTrackingPage"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import * as trackingApi from "./trackingApi"
import type { Accommodation, Role, StudentTracking } from "@/types"

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

function pendingAccommodation(overrides: Partial<Accommodation> = {}): Accommodation {
  return {
    id: 77,
    student_id: 3,
    type: "Tiempo extra en evaluaciones",
    active: true,
    description: "Doble tiempo en pruebas escritas.",
    focus_area: null,
    requires_external_approval: true,
    approved: null,
    is_effective: false,
    created_by_id: 5,
    created_at: "2026-08-05T10:00:00+00:00",
    updated_at: "2026-08-05T10:00:00+00:00",
    ...overrides,
  }
}

function renderPage(role: Role, userId = 1) {
  const value: AuthContextValue = {
    user: { id: userId, name: "Ana", email: "ana@escuela.test", roles: [role] },
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

  it("approves a pending accommodation in place using the response, without a full refetch", async () => {
    const tracking = baseTracking()
    tracking.accommodations = [pendingAccommodation()]
    const fetchTracking = vi
      .spyOn(trackingApi, "fetchStudentTracking")
      .mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])
    const approve = vi.spyOn(trackingApi, "approveAccommodation").mockResolvedValue(
      pendingAccommodation({ approved: true, is_effective: true }),
    )

    renderPage("psychopedagogue")

    await screen.findByText("Seguimiento — Juan Pérez")
    // Pending badge visible before, approved badge after — checks the in-place update.
    expect(screen.getByText(/pendiente de aprobación/i)).toBeInTheDocument()
    await userEvent.click(screen.getByRole("button", { name: /aprobar/i }))
    expect(approve).toHaveBeenCalledWith(77)
    expect(await screen.findByText(/aprobada/i)).toBeInTheDocument()
    // No approve/reject buttons remain once the decision is in.
    expect(screen.queryByRole("button", { name: /aprobar/i })).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /rechazar/i })).not.toBeInTheDocument()
    // The tracking aggregate is server-cached (~60s); a refetch could return
    // the stale value. §3 of the spec forbids a refetch, so we assert the
    // initial mount call is the only one.
    expect(fetchTracking).toHaveBeenCalledTimes(1)
  })

  it("rejects a pending accommodation in place using the response, without a full refetch", async () => {
    const tracking = baseTracking()
    tracking.accommodations = [pendingAccommodation()]
    const fetchTracking = vi
      .spyOn(trackingApi, "fetchStudentTracking")
      .mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])
    const reject = vi.spyOn(trackingApi, "rejectAccommodation").mockResolvedValue(
      pendingAccommodation({ approved: false }),
    )

    renderPage("director")

    await screen.findByText("Seguimiento — Juan Pérez")
    await userEvent.click(screen.getByRole("button", { name: /rechazar/i }))
    expect(reject).toHaveBeenCalledWith(77)
    expect(await screen.findByText(/rechazada/i)).toBeInTheDocument()
    expect(fetchTracking).toHaveBeenCalledTimes(1)
  })

  it("does not render approve/reject buttons for an accommodation with a decision already recorded", async () => {
    const tracking = baseTracking()
    tracking.accommodations = [pendingAccommodation({ approved: true, is_effective: true })]
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

    renderPage("psychopedagogue")

    await screen.findByText("Seguimiento — Juan Pérez")
    expect(screen.getByText(/aprobada/i)).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /aprobar/i })).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /rechazar/i })).not.toBeInTheDocument()
  })

  it("does not show the audit-history link to a teacher (no clinical access)", async () => {
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(baseTracking())
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

    renderPage("teacher")

    await screen.findByText("Seguimiento — Juan Pérez")
    expect(screen.queryByRole("link", { name: /historial de auditoría/i })).not.toBeInTheDocument()
  })

  it("shows the audit-history link to a school-wide viewer", async () => {
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(baseTracking())
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

    renderPage("director")

    const link = await screen.findByRole("link", { name: /historial de auditoría/i })
    expect(link).toHaveAttribute("href", "/alumnos/3/historial")
  })

  it("links and validates a barrier↔accommodation, updating the panel in place", async () => {
    const tracking = baseTracking()
    // A director user (id 1) can view the panel; but per BarrierPolicy::update
    // only teacher/psychopedagogue can propose, so we use psychopedagogue here
    // to exercise both the link form and the validate action against a link
    // that a DIFFERENT user proposed.
    tracking.accommodations = [
      { ...pendingAccommodation(), id: 91, requires_external_approval: false, approved: null, active: true },
    ]
    tracking.barriers = [
      { id: 55, description: "Dificultad de atención sostenida", coping_strategy: null, active: true },
    ]
    tracking.barriers_count = 1
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])
    // Panel opens empty…
    const fetchLinks = vi
      .spyOn(trackingApi, "fetchBarrierAccommodations")
      .mockResolvedValueOnce([])
    // …then a link is created against a DIFFERENT proposer so the current
    // psychopedagogue (id 1) may validate it (four-eyes rule).
    const linkApi = vi.spyOn(trackingApi, "linkAccommodationToBarrier").mockResolvedValue({
      id: 91,
      type: "Tiempo extra en evaluaciones",
      description: "Doble tiempo en pruebas escritas.",
      focus_area: null,
      proposed_by_id: 999,
      validated: false,
      validated_by_id: null,
    })
    const validateApi = vi
      .spyOn(trackingApi, "validateBarrierAccommodation")
      .mockResolvedValue({
        id: 91,
        type: "Tiempo extra en evaluaciones",
        description: "Doble tiempo en pruebas escritas.",
        focus_area: null,
        proposed_by_id: 999,
        validated: true,
        validated_by_id: 1,
      })

    renderPage("psychopedagogue")

    await screen.findByText("Seguimiento — Juan Pérez")
    await userEvent.click(screen.getByRole("button", { name: /ver adaptaciones vinculadas/i }))
    expect(fetchLinks).toHaveBeenCalledWith(55)
    await screen.findByText(/todavía no hay adaptaciones vinculadas/i)

    await userEvent.selectOptions(
      screen.getByLabelText(/vincular adaptación existente/i),
      "91",
    )
    await userEvent.click(screen.getByRole("button", { name: /^vincular$/i }))
    expect(linkApi).toHaveBeenCalledWith(55, 91)
    expect(await screen.findByText(/pendiente de validación/i)).toBeInTheDocument()

    await userEvent.click(screen.getByRole("button", { name: /validar/i }))
    expect(validateApi).toHaveBeenCalledWith(55, 91)
    expect(await screen.findByText(/^validada$/i)).toBeInTheDocument()
    // Validate action must NOT re-fetch the whole barrier list.
    expect(fetchLinks).toHaveBeenCalledTimes(1)
  })

  it("hides the 'Validar' button entirely when the current user is the proposer (four-eyes rule)", async () => {
    const tracking = baseTracking()
    tracking.accommodations = []
    tracking.barriers = [
      { id: 55, description: "Barrera X", coping_strategy: null, active: true },
    ]
    tracking.barriers_count = 1
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])
    // Same id as the auth user (userId = 7 below).
    vi.spyOn(trackingApi, "fetchBarrierAccommodations").mockResolvedValue([
      {
        id: 91,
        type: "Adaptación X",
        description: null,
        focus_area: null,
        proposed_by_id: 7,
        validated: false,
        validated_by_id: null,
      },
    ])

    renderPage("psychopedagogue", 7)

    await screen.findByText("Seguimiento — Juan Pérez")
    await userEvent.click(screen.getByRole("button", { name: /ver adaptaciones vinculadas/i }))
    const panel = await screen.findByText("Adaptación X")
    // The "Pendiente" badge should be there, but NOT a Validar button.
    expect(within(panel.closest("li") as HTMLElement).getByText(/pendiente/i)).toBeInTheDocument()
    expect(
      within(panel.closest("li") as HTMLElement).queryByRole("button", { name: /validar/i }),
    ).not.toBeInTheDocument()
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
