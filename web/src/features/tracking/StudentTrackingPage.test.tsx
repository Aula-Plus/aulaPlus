import { render, screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { AxiosError } from "axios"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { StudentTrackingPage } from "./StudentTrackingPage"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import * as trackingApi from "./trackingApi"
import * as scheduledFollowUpsApi from "./scheduledFollowUpsApi"
import * as performanceApi from "./performanceApi"
import * as subjectsApi from "@/features/subjects/subjectsApi"
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
    team_summary: null,
    support_chips: { accommodations: [], barriers: [] },
    recent_assessments: [
      { id: 10, group_id: 1, subject_id: 3, type: "written", variant_number: 2, created_at: "2026-08-01T10:00:00+00:00" },
    ],
    accommodations_count: 1,
    barriers_count: 0,
    recent_comments: [],
    open_alerts_count: 1,
    overall_average: 8,
    by_subject: [
      { subject_id: 1, subject_name: "Matemática", average: 7, assessment_count: 2 },
    ],
    grades: [
      { id: 1, assessment_id: 10, subject_id: 1, subject_name: "Matemática", type: "written", administered_at: "2026-08-01", score: 7, accommodations: [] },
    ],
    grades_truncated: false,
    grades_view: { mode: "own_subject", restricted: false, own_subject_ids: [], cutoff_at: null },
  }
}

function pendingAccommodation(overrides: Partial<Accommodation> = {}): Accommodation {
  return {
    id: 77,
    student_id: 3,
    type: "Tiempo extra en evaluaciones",
    category: "access",
    starts_on: "2026-03-15",
    subjects: [],
    assessment_types: null,
    active: true,
    deactivated_on: null,
    description: "Doble tiempo en pruebas escritas.",
    focus_area: null,
    origin: null,
    is_effective: false,
    review_status: null,
    proposed_by_id: null,
    proposed_by_name: null,
    reviewed_by_id: null,
    reviewed_by_name: null,
    reviewed_at: null,
    review_note: null,
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

/** Switch to a profile tab once the page has loaded (the six tabs are always present). */
async function openTab(name: string) {
  await userEvent.click(await screen.findByRole("tab", { name }))
}

describe("StudentTrackingPage", () => {
  beforeEach(() => {
    // The embedded ScheduledFollowUpsPanel and StudentPerformanceChart both
    // self-fetch on mount; stub them so these tests (about alerts/
    // accommodations/comments) don't hit the network.
    vi.spyOn(scheduledFollowUpsApi, "fetchScheduledFollowUps").mockResolvedValue([])
    vi.spyOn(scheduledFollowUpsApi, "fetchFollowUpCandidates").mockResolvedValue([])
    vi.spyOn(trackingApi, "fetchCommentCategories").mockResolvedValue([])
    vi.spyOn(trackingApi, "fetchStudentCommentShares").mockResolvedValue([])
    vi.spyOn(trackingApi, "fetchAccommodationProposals").mockResolvedValue([])
    vi.spyOn(subjectsApi, "fetchSubjects").mockResolvedValue([
      { id: 4, name: "Matemática", short_code: null, color: null },
      { id: 5, name: "Ciencias", short_code: null, color: null },
    ])
    vi.spyOn(performanceApi, "fetchStudentPerformanceTimeline").mockResolvedValue({
      results: [],
      marks: [],
    })
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("renders the student header, indicators and a clinical alert with a resolve button for a school-wide role", async () => {
    const tracking = baseTracking()
    tracking.alerts = [
      {
        id: 5,
        student_id: 3,
        type: "performance",
        severity: "medium",
        description: "Desempeño bajo sostenido.",
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

    expect(await screen.findByText("Perfil del alumno — Juan Pérez")).toBeInTheDocument()
    expect(screen.getByText("Desempeño bajo sostenido.")).toBeInTheDocument()

    await userEvent.click(screen.getByRole("button", { name: /resolver/i }))
    expect(resolveAlert).toHaveBeenCalledWith(5)
  })

  it("shows the overall average and per-subject performance", async () => {
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(baseTracking())
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

    renderPage("teacher")
    await openTab("Evaluaciones")

    expect(await screen.findByText("Perfil del alumno — Juan Pérez")).toBeInTheDocument()
    expect(screen.getByText(/promedio general/i)).toBeInTheDocument()
    expect(screen.getByText("Desempeño por materia")).toBeInTheDocument()
    expect(screen.getAllByText("Matemática").length).toBeGreaterThan(0)
  })

  it("tells a restricted teacher which cutoff the other subjects' grades are from", async () => {
    const tracking = baseTracking()
    tracking.grades_view = { mode: "all_periodic", restricted: true, own_subject_ids: [1], cutoff_at: "2026-07-01" }
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

    renderPage("teacher")
    await openTab("Evaluaciones")

    expect(await screen.findByRole("note")).toHaveTextContent(
      /notas de otras materias cargadas antes del corte del/i,
    )
  })

  it("hides the general average from a teacher limited to their own subjects", async () => {
    const tracking = baseTracking()
    tracking.overall_average = null
    tracking.grades_view = { mode: "own_subject", restricted: true, own_subject_ids: [1], cutoff_at: null }
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

    renderPage("teacher")
    await openTab("Evaluaciones")

    expect(await screen.findByText("Desempeño por materia")).toBeInTheDocument()
    expect(screen.queryByText(/promedio general/i)).not.toBeInTheDocument()
  })

  it("shows, next to each grade, the accommodations that were active that day", async () => {
    const tracking = baseTracking()
    tracking.grades = [
      {
        id: 1,
        assessment_id: 10,
        subject_id: 1,
        subject_name: "Matemática",
        type: "written",
        administered_at: "2026-04-10",
        score: 7,
        accommodations: [
          { id: 5, label: "Calculadora" },
          { id: 6, label: "Tiempo extendido" },
        ],
      },
      {
        id: 2,
        assessment_id: 11,
        subject_id: 1,
        subject_name: "Matemática",
        type: "oral",
        administered_at: "2026-04-20",
        score: 9,
        accommodations: [],
      },
    ]
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

    renderPage("teacher")
    await openTab("Evaluaciones")

    expect(await screen.findByRole("columnheader", { name: "Ajustes activos ese día" })).toBeInTheDocument()
    const [written, oral] = screen
      .getAllByRole("row")
      .filter((row) => within(row).queryByText(/^(7|9)$/))
    const writtenAccommodations = within(written).getByRole("list", { name: "Ajustes activos ese día" })
    expect(within(writtenAccommodations).getAllByRole("listitem").map((item) => item.textContent)).toEqual([
      "Calculadora",
      "Tiempo extendido",
    ])
    expect(within(oral).getByText("Ninguno")).toBeInTheDocument()
  })

  it("says when the grades list only shows the most recent ones", async () => {
    const tracking = baseTracking()
    tracking.grades_truncated = true
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

    renderPage("teacher")
    await openTab("Evaluaciones")

    expect(await screen.findByText(/se muestran las 1 notas más recientes/i)).toBeInTheDocument()
  })

  it("shows an empty state for per-subject performance when there are no scores", async () => {
    const tracking = baseTracking()
    tracking.overall_average = null
    tracking.by_subject = []
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

    renderPage("teacher")
    await openTab("Evaluaciones")

    expect(await screen.findByText("Perfil del alumno — Juan Pérez")).toBeInTheDocument()
    expect(screen.getByText(/sin evaluaciones por materia/i)).toBeInTheDocument()
  })

  it("shows an alert count only (no detail, no resolve) when the viewer lacks clinical access", async () => {
    // A teacher's payload omits the clinical arrays entirely (server-side gate).
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(baseTracking())
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

    renderPage("teacher")

    expect(await screen.findByText("Perfil del alumno — Juan Pérez")).toBeInTheDocument()
    expect(screen.getByText(/no tenés permiso para ver el detalle clínico/i)).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /resolver/i })).not.toBeInTheDocument()
  })

  it("never shows nor requests «Propuestas de ajuste» for a teacher", async () => {
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(baseTracking())
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

    renderPage("teacher")
    await openTab("Ajustes")

    await screen.findByText("Perfil del alumno — Juan Pérez")
    expect(screen.queryByText("Propuestas de ajuste")).not.toBeInTheDocument()
    expect(trackingApi.fetchAccommodationProposals).not.toHaveBeenCalled()
  })

  it("does not show the change-history links to a teacher", async () => {
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(baseTracking())
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

    renderPage("teacher")

    await screen.findByText("Perfil del alumno — Juan Pérez")
    expect(screen.queryByRole("link", { name: /historial de cambios/i })).not.toBeInTheDocument()

    await openTab("Documentos")
    expect(screen.queryByRole("link", { name: /historial de cambios/i })).not.toBeInTheDocument()
  })

  it.each(["director", "psychopedagogue"] as const)(
    "shows «Ver historial de cambios» (not the old «quién abrió») in Documentos to a %s",
    async (role) => {
      vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(baseTracking())
      vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

      renderPage(role)

      const headerLink = await screen.findByRole("link", { name: /ver historial de cambios/i })
      expect(headerLink).toHaveAttribute("href", "/alumnos/3/historial")

      await openTab("Documentos")
      const links = screen.getAllByRole("link", { name: /ver historial de cambios/i })
      expect(links).toHaveLength(2)
      expect(links.every((link) => link.getAttribute("href") === "/alumnos/3/historial")).toBe(true)
      expect(screen.queryByText(/quién abrió esta ficha/i)).not.toBeInTheDocument()
    },
  )

  it("links and validates a barrier↔accommodation, updating the panel in place", async () => {
    const tracking = baseTracking()
    // A director user (id 1) can view the panel; but per BarrierPolicy::update
    // only teacher/psychopedagogue can propose, so we use psychopedagogue here
    // to exercise both the link form and the validate action against a link
    // that a DIFFERENT user proposed.
    tracking.accommodations = [
      { ...pendingAccommodation(), id: 91, active: true },
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
    await openTab("Ajustes")

    await screen.findByText("Perfil del alumno — Juan Pérez")
    await userEvent.click(screen.getByRole("button", { name: /ver ajustes vinculados/i }))
    expect(fetchLinks).toHaveBeenCalledWith(55)
    await screen.findByText(/todavía no hay ajustes vinculados/i)

    await userEvent.click(screen.getByLabelText(/vincular ajuste existente/i))
    await userEvent.click(await screen.findByRole("button", { name: /tiempo extra/i }))
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
        type: "Ajuste X",
        description: null,
        focus_area: null,
        proposed_by_id: 7,
        validated: false,
        validated_by_id: null,
      },
    ])

    renderPage("psychopedagogue", 7)
    await openTab("Ajustes")

    await screen.findByText("Perfil del alumno — Juan Pérez")
    await userEvent.click(screen.getByRole("button", { name: /ver ajustes vinculados/i }))
    const panel = await screen.findByText("Ajuste X")
    // The "Pendiente" badge should be there, but NOT a Validar button.
    expect(within(panel.closest("li") as HTMLElement).getByText(/pendiente/i)).toBeInTheDocument()
    expect(
      within(panel.closest("li") as HTMLElement).queryByRole("button", { name: /validar/i }),
    ).not.toBeInTheDocument()
  })

  // ── Sesión 12: accommodation create/edit + per-instance deactivation ──────

  it("does not allow submitting a new accommodation without a category", async () => {
    const tracking = baseTracking()
    tracking.accommodations = []
    tracking.accommodations_count = 0
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])
    const create = vi.spyOn(trackingApi, "createAccommodation").mockResolvedValue({
      ...pendingAccommodation(),
      id: 200,
      category: "content",
      is_effective: true,
    })

    renderPage("psychopedagogue")
    await openTab("Ajustes")

    await screen.findByText("Perfil del alumno — Juan Pérez")
    await userEvent.click(screen.getByRole("button", { name: /nuevo ajuste/i }))

    // Fill everything except the category, then try to save.
    await userEvent.type(screen.getByLabelText(/nombre del ajuste/i), "Lectura en voz alta")
    await userEvent.type(screen.getByLabelText(/descripción/i), "El docente lee la consigna")
    await userEvent.type(screen.getByLabelText(/área de enfoque/i), "Comprensión")
    await userEvent.click(screen.getByRole("button", { name: /^guardar$/i }))

    // Exact match (not a substring regex) so it hits the error message, not the
    // "Elegí el tipo de ajuste…" placeholder option.
    expect(await screen.findByText("Elegí el tipo de ajuste")).toBeInTheDocument()
    expect(create).not.toHaveBeenCalled()

    // Now choose a category and the write goes through with it.
    await userEvent.click(screen.getByLabelText(/^tipo de ajuste$/i))
    await userEvent.click(await screen.findByRole("button", { name: "Contenido" }))

    // Scope: since when, which subjects, which assessment types.
    const startsOn = screen.getByLabelText(/vigente desde/i)
    await userEvent.clear(startsOn)
    await userEvent.type(startsOn, "2026-04-02")
    await userEvent.click(screen.getByLabelText(/materias donde aplica/i))
    await userEvent.click(await screen.findByRole("button", { name: "Matemática" }))
    await userEvent.click(screen.getByRole("checkbox", { name: "Escrita" }))
    await userEvent.click(screen.getByRole("button", { name: /^guardar$/i }))

    expect(create).toHaveBeenCalledWith(3, {
      type: "Lectura en voz alta",
      description: "El docente lee la consigna",
      focus_area: "Comprensión",
      category: "content",
      active: true,
      starts_on: "2026-04-02",
      subject_ids: [4],
      assessment_types: ["written"],
      // «Origen» comes prefilled for what psychopedagogy registers itself.
      origin: "Registrado por Psicopedagogía",
    })
  })

  // ClickUp 86e3qkwkk: direction proposes; psychopedagogy confirms.
  it("lets direction propose an accommodation for psychopedagogy's review", async () => {
    const tracking = baseTracking()
    tracking.accommodations = [pendingAccommodation({ id: 5, is_effective: true })]
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])
    const create = vi
      .spyOn(trackingApi, "createAccommodation")
      .mockResolvedValue(pendingAccommodation({ id: 201, review_status: "pending" }))
    vi.spyOn(scheduledFollowUpsApi, "fetchFollowUpCandidates").mockResolvedValue([
      { id: 30, name: "Laura Ferrando", role: "psychopedagogue" },
      { id: 31, name: "Marta Psico", role: "psychopedagogue" },
    ])

    renderPage("director")
    await openTab("Ajustes")

    await screen.findByText("Perfil del alumno — Juan Pérez")
    // Direction never edits an accommodation in force.
    expect(screen.queryByRole("button", { name: /^editar$/i })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole("button", { name: /proponer ajuste/i }))

    expect(screen.getByText(/se envía a revisión de psicopedagogía/i)).toBeInTheDocument()
    // «Vigente desde» is set by psychopedagogy when it confirms; no switch-off either.
    expect(screen.queryByLabelText(/vigente desde/i)).not.toBeInTheDocument()
    expect(screen.queryByLabelText(/requiere aprobación externa/i)).not.toBeInTheDocument()
    // «Origen» is psychopedagogy's to write.
    expect(screen.queryByLabelText(/^origen$/i)).not.toBeInTheDocument()

    await userEvent.type(screen.getByLabelText(/nombre del ajuste/i), "Más tiempo en escritos")
    await userEvent.type(screen.getByLabelText(/descripción/i), "Media hora extra")
    await userEvent.type(screen.getByLabelText(/área de enfoque/i), "Escritura")
    await userEvent.click(screen.getByLabelText(/^tipo de ajuste$/i))
    await userEvent.click(await screen.findByRole("button", { name: "Acceso" }))

    // Direction picks the review deadline and who reviews it.
    await userEvent.click(screen.getByLabelText(/personas de psicopedagogía/i))
    await userEvent.click(screen.getByRole("button", { name: /enviar a revisión de psicopedagogía/i }))
    expect(await screen.findByText(/elegí hasta cuándo tiene para revisarla/i)).toBeInTheDocument()
    expect(screen.getByText(/elegí al menos una persona/i)).toBeInTheDocument()
    expect(create).not.toHaveBeenCalled()

    await userEvent.type(screen.getByLabelText(/plazo para la revisión/i), "2099-01-15")
    await userEvent.click(await screen.findByRole("checkbox", { name: "Marta Psico" }))
    await userEvent.click(screen.getByRole("button", { name: /enviar a revisión de psicopedagogía/i }))

    expect(create).toHaveBeenCalledWith(3, {
      type: "Más tiempo en escritos",
      description: "Media hora extra",
      focus_area: "Escritura",
      category: "access",
      active: true,
      subject_ids: [],
      assessment_types: [],
      review_due_date: "2099-01-15",
      review_recipients: "people",
      reviewer_ids: [31],
    })
  })

  it("tells direction when nobody in psychopedagogy can review the proposal", async () => {
    const tracking = baseTracking()
    tracking.accommodations = [pendingAccommodation({ id: 5, is_effective: true })]
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])
    vi.spyOn(scheduledFollowUpsApi, "fetchFollowUpCandidates").mockResolvedValue([])
    vi.spyOn(trackingApi, "createAccommodation").mockRejectedValue({
      isAxiosError: true,
      response: { status: 422, data: { errors: { review_recipients: ["No active psychopedagogue."] } } },
    })

    renderPage("director")
    await openTab("Ajustes")
    await userEvent.click(await screen.findByRole("button", { name: /proponer ajuste/i }))
    await userEvent.type(screen.getByLabelText(/nombre del ajuste/i), "Más tiempo en escritos")
    await userEvent.type(screen.getByLabelText(/descripción/i), "Media hora extra")
    await userEvent.type(screen.getByLabelText(/área de enfoque/i), "Escritura")
    await userEvent.click(screen.getByLabelText(/^tipo de ajuste$/i))
    await userEvent.click(await screen.findByRole("button", { name: "Acceso" }))
    await userEvent.type(screen.getByLabelText(/plazo para la revisión/i), "2099-01-15")
    await userEvent.click(screen.getByRole("button", { name: /enviar a revisión de psicopedagogía/i }))

    expect(
      await screen.findByText("No hay personas de Psicopedagogía activas para revisar la propuesta."),
    ).toBeInTheDocument()
  })

  it("hides 'Nuevo ajuste' and 'Editar' from a viewer without clinical access", async () => {
    // A teacher's payload omits the accommodations array entirely — the whole
    // section (and its manage buttons) never renders.
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(baseTracking())
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

    renderPage("teacher")
    await openTab("Ajustes")

    await screen.findByText("Perfil del alumno — Juan Pérez")
    expect(screen.queryByRole("button", { name: /nuevo ajuste/i })).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /^editar$/i })).not.toBeInTheDocument()
  })

  it("shows 'Desactivar para una evaluación' only on effective accommodations", async () => {
    const tracking = baseTracking()
    tracking.accommodations = [
      pendingAccommodation({
        id: 1,
        is_effective: true,
      }),
      // Not effective (switched off) → no deactivation action.
      pendingAccommodation({ id: 2, is_effective: false }),
    ]
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

    renderPage("director")
    await openTab("Ajustes")

    await screen.findByText("Perfil del alumno — Juan Pérez")
    expect(
      screen.getAllByRole("button", { name: /desactivar para una evaluación/i }),
    ).toHaveLength(1)
  })

  it("populates the deactivation select from recent_assessments without an extra fetch", async () => {
    const tracking = baseTracking()
    tracking.accommodations = [
      pendingAccommodation({
        id: 1,
        is_effective: true,
      }),
    ]
    const fetchTracking = vi
      .spyOn(trackingApi, "fetchStudentTracking")
      .mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

    renderPage("director")
    await openTab("Ajustes")

    await screen.findByText("Perfil del alumno — Juan Pérez")
    await userEvent.click(screen.getByRole("button", { name: /desactivar para una evaluación/i }))

    await userEvent.click(screen.getByLabelText(/^evaluación$/i))
    // The one recent assessment (Escrita, id 10) is offered — no assessments
    // endpoint is called (there isn't one).
    expect(await screen.findByRole("button", { name: /escrita/i })).toBeInTheDocument()
    // Only the initial mount fetch happened.
    expect(fetchTracking).toHaveBeenCalledTimes(1)
  })

  it("shows a readable message when deactivation is forbidden (not the assessment owner)", async () => {
    const tracking = baseTracking()
    tracking.accommodations = [
      pendingAccommodation({
        id: 1,
        is_effective: true,
      }),
    ]
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])
    const forbidden = new AxiosError("Forbidden", "403", undefined, undefined, {
      status: 403,
      statusText: "Forbidden",
      data: {},
      headers: {},
      config: { headers: undefined as never },
    })
    const deactivate = vi
      .spyOn(trackingApi, "deactivateAccommodationForAssessment")
      .mockRejectedValue(forbidden)

    renderPage("psychopedagogue")
    await openTab("Ajustes")

    await screen.findByText("Perfil del alumno — Juan Pérez")
    await userEvent.click(screen.getByRole("button", { name: /desactivar para una evaluación/i }))
    await userEvent.click(screen.getByLabelText(/^evaluación$/i))
    await userEvent.click(await screen.findByRole("button", { name: /escrita/i }))
    await userEvent.type(screen.getByLabelText(/motivo/i), "La evaluación es oral")
    await userEvent.click(screen.getByRole("button", { name: /^desactivar$/i }))

    expect(deactivate).toHaveBeenCalledWith(1, {
      assessment_id: 10,
      reason: "La evaluación es oral",
    })
    expect(
      await screen.findByText(/solo el docente responsable de esa evaluación/i),
    ).toBeInTheDocument()
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
        categories: [],
        visible_to: null,
        author_only: false,
        created_at: "2026-08-20T10:00:00+00:00",
      })

    renderPage("teacher")
    await openTab("Observaciones")

    await screen.findByText("Perfil del alumno — Juan Pérez")
    await userEvent.type(screen.getByLabelText(/nuevo comentario/i), "Nueva observación")
    await userEvent.click(screen.getByRole("button", { name: /comentar/i }))

    expect(createComment).toHaveBeenCalledWith(3, { content: "Nueva observación" })
    // loadComments runs once on mount and again after creating.
    expect(fetchComments).toHaveBeenCalledTimes(2)
  })

  it("shows the comment trend mark when the server sends it, and nothing when it is absent", async () => {
    const tracking = baseTracking()
    tracking.comment_trends = [
      { category_id: 3, category_name: "Social", count: 4, authors: 3, days: 21 },
    ]
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

    renderPage("psychopedagogue")

    expect(
      await screen.findByText(/tendencia: 4 comentarios de «social» de 3 personas en 21 días/i),
    ).toBeInTheDocument()
  })

  it("shows the private-notes signal only when the server sends it for this viewer", async () => {
    const tracking = baseTracking()
    tracking.private_notes_signal = true
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

    const { unmount } = renderPage("teacher")
    expect(
      await screen.findByText(/tenés varias notas privadas sobre este alumno en una categoría en este período/i),
    ).toBeInTheDocument()
    unmount()

    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue({
      ...baseTracking(),
      private_notes_signal: false,
    })
    renderPage("teacher")
    await screen.findByText("Perfil del alumno — Juan Pérez")
    expect(screen.queryByText(/notas privadas/i)).not.toBeInTheDocument()
  })

  it("offers «Compartir con» on each observation to psychopedagogy only, and lists shared extracts", async () => {
    const comment = {
      id: 9,
      author_id: 2,
      commentable_type: "Student",
      commentable_id: 3,
      content: "Observación reservada",
      categories: [],
      visible_to: ["psychopedagogue" as const],
      author_only: false,
      created_at: "2026-08-20T10:00:00+00:00",
    }
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(baseTracking())
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([comment])

    const { unmount } = renderPage("psychopedagogue")
    await openTab("Observaciones")
    expect(await screen.findByRole("button", { name: "Compartir con" })).toBeInTheDocument()
    unmount()

    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([{ ...comment, visible_to: null }])
    vi.spyOn(trackingApi, "fetchStudentCommentShares").mockResolvedValue([
      { id: 1, student_id: 3, author_id: 2, content: "Extracto para vos", created_at: "2026-08-21T10:00:00+00:00" },
    ])
    renderPage("teacher")
    await openTab("Observaciones")
    expect(await screen.findByText("Extracto para vos")).toBeInTheDocument()
    expect(screen.getByText("Extracto compartido por Psicopedagogía")).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Compartir con" })).not.toBeInTheDocument()
  })

  it("does not show a trend mark to a teacher (the field is absent)", async () => {
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(baseTracking())
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

    renderPage("teacher")

    await screen.findByText("Perfil del alumno — Juan Pérez")
    expect(screen.queryByText(/tendencia:/i)).not.toBeInTheDocument()
  })

  it("shows the six profile tabs, the team summary and support chips that lead to Ajustes (teacher)", async () => {
    const tracking = baseTracking()
    tracking.team_summary = {
      strengths: "Buena comprensión oral.",
      difficulties: "Leer textos largos.",
      adjustments: "Tiempo extendido 25%.",
      confirmed_at: "2026-09-01T10:00:00+00:00",
    }
    tracking.support_chips = {
      accommodations: [
        { id: 1, label: "Tiempo extendido 25%", starts_on: null, subjects: [], assessment_types: null },
      ],
      barriers: [{ id: 2, label: "Lectura de textos largos" }],
    }
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

    renderPage("teacher")

    expect(await screen.findAllByRole("tab")).toHaveLength(6)
    expect(screen.getByText("Buena comprensión oral.")).toBeInTheDocument()
    // The old big "Ajustes vigentes"/"Barreras activas" stat cards are gone.
    expect(screen.queryByText("Barreras activas")).not.toBeInTheDocument()
    // A teacher cannot edit the summary.
    expect(screen.queryByRole("button", { name: /editar resumen/i })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole("button", { name: "Lectura de textos largos" }))
    expect(screen.getByRole("tab", { name: "Ajustes" })).toHaveAttribute("aria-selected", "true")
    // Read-only list of names, no clinical controls.
    expect(screen.getByText("Tiempo extendido 25%")).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /nuevo ajuste/i })).not.toBeInTheDocument()
  })

  it("lets psychopedagogy write and confirm the team summary", async () => {
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(baseTracking())
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])
    const save = vi.spyOn(trackingApi, "updateTeamSummary").mockResolvedValue({
      strengths: "Fuerte",
      difficulties: "Cuesta",
      adjustments: "Ajusta",
      confirmed_at: "2026-10-04T10:00:00+00:00",
    })

    renderPage("psychopedagogue")

    await userEvent.click(await screen.findByRole("button", { name: /escribir resumen/i }))
    await userEvent.type(screen.getByLabelText("Fortalezas"), "Fuerte")
    await userEvent.type(screen.getByLabelText("Qué le cuesta"), "Cuesta")
    await userEvent.click(screen.getByRole("button", { name: /guardar y confirmar/i }))
    expect(screen.getByText(/completá las tres partes/i)).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText("Ajustes"), "Ajusta")
    await userEvent.click(screen.getByRole("button", { name: /guardar y confirmar/i }))

    expect(save).toHaveBeenCalledWith(3, {
      strengths: "Fuerte",
      difficulties: "Cuesta",
      adjustments: "Ajusta",
    })
    expect(await screen.findByText("Cuesta")).toBeInTheDocument()
  })

  // ── ClickUp 86e3dpbwy follow-up: since when and where each support applies ─

  it("shows a teacher each accommodation with since when and in which subjects it applies", async () => {
    const tracking = baseTracking()
    const year = new Date().getFullYear()
    tracking.support_chips = {
      accommodations: [
        {
          id: 1,
          label: "Ortografía no puntúa",
          starts_on: `${year}-04-02`,
          subjects: [
            { id: 7, name: "Idioma Español", deleted: false },
            { id: 8, name: "Literatura", deleted: false },
          ],
          assessment_types: null,
        },
        {
          id: 2,
          label: "Sentarla cerca del pizarrón",
          starts_on: "2025-03-15",
          subjects: [],
          assessment_types: null,
        },
        {
          id: 3,
          label: "Calculadora",
          starts_on: `${year}-05-04`,
          subjects: [{ id: 4, name: "Matemática", deleted: false }],
          assessment_types: ["written", "oral"],
        },
      ],
      barriers: [],
    }
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

    renderPage("teacher")
    await openTab("Ajustes")

    const items = within(await screen.findByRole("list")).getAllByRole("listitem")
    expect(items.map((item) => item.textContent)).toEqual([
      "Ortografía no puntúa — desde el 02/04 — en Idioma Español y Literatura",
      "Sentarla cerca del pizarrón — desde el 15/03/2025 — en todas las materias",
      "Calculadora — desde el 04/05 — en Matemática — solo en escritos y orales",
    ])
    // Never the origin, validator or review date (the server doesn't send them).
    expect(screen.queryByText(/origen|validó|revisión/i)).not.toBeInTheDocument()
  })

  it("shows psychopedagogy the same scope on each accommodation of the full list", async () => {
    const tracking = baseTracking()
    tracking.accommodations = [
      pendingAccommodation({
        is_effective: true,
        starts_on: "2024-04-02",
        subjects: [{ id: 7, name: "Idioma Español", deleted: false }],
      }),
    ]
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

    renderPage("psychopedagogue")
    await openTab("Ajustes")

    expect(await screen.findByText("Desde el 02/04/2024 — en Idioma Español")).toBeInTheDocument()
  })

  it("shows a soft-deleted subject of the edited accommodation as «(eliminada)» and lets it be removed", async () => {
    const tracking = baseTracking()
    const accommodation = pendingAccommodation({
      is_effective: true,
      focus_area: "Cálculo",
      subjects: [
        { id: 9, name: "Dibujo", deleted: true },
        { id: 4, name: "Matemática", deleted: false },
      ],
    })
    tracking.accommodations = [accommodation]
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])
    const update = vi.spyOn(trackingApi, "updateAccommodation").mockResolvedValue(accommodation)

    renderPage("psychopedagogue")
    await openTab("Ajustes")
    await userEvent.click(await screen.findByRole("button", { name: /^editar$/i }))

    // Not «Todas las materias»: the trigger shows both attached subjects.
    const trigger = await screen.findByLabelText(/materias/i)
    expect(await within(trigger).findByText("Dibujo (eliminada)")).toBeInTheDocument()
    expect(within(trigger).getByText("Matemática")).toBeInTheDocument()
    expect(within(trigger).queryByText("Todas las materias")).not.toBeInTheDocument()

    await userEvent.click(trigger)
    await userEvent.click(await screen.findByRole("button", { name: "Dibujo (eliminada)" }))
    await userEvent.click(screen.getByRole("button", { name: /^guardar$/i }))

    expect(update).toHaveBeenCalledWith(
      accommodation.id,
      expect.objectContaining({ subject_ids: [4] }),
    )
  })

  it("edits «Vigente desde» and deactivates without any end date typed in (the server records the day)", async () => {
    const tracking = baseTracking()
    const accommodation = pendingAccommodation({
      is_effective: true,
      focus_area: "Cálculo",
      starts_on: "2026-03-15",
    })
    tracking.accommodations = [accommodation]
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])
    const update = vi.spyOn(trackingApi, "updateAccommodation").mockResolvedValue(accommodation)

    renderPage("psychopedagogue")
    await openTab("Ajustes")
    await userEvent.click(await screen.findByRole("button", { name: /^editar$/i }))

    const startsOn = await screen.findByLabelText(/vigente desde/i)
    expect(startsOn).toHaveValue("2026-03-15")
    expect(screen.getByText(/se completa con el día en que se activa/i)).toBeInTheDocument()
    expect(screen.getByText(/registra el día de hoy como fecha de baja/i)).toBeInTheDocument()
    // No «hasta» / expiry field anywhere.
    expect(screen.queryByLabelText(/hasta|vence|vencimiento/i)).not.toBeInTheDocument()

    await userEvent.clear(startsOn)
    await userEvent.type(startsOn, "2026-03-02")
    await userEvent.click(screen.getByLabelText(/^activa$/i))
    await userEvent.click(screen.getByRole("button", { name: /^guardar$/i }))

    expect(update).toHaveBeenCalledWith(
      accommodation.id,
      expect.objectContaining({ starts_on: "2026-03-02", active: false }),
    )
    expect(update.mock.calls[0][1]).not.toHaveProperty("deactivated_on")
  })

  it("only offers, to deactivate an accommodation, the assessments it applies to", async () => {
    const tracking = baseTracking()
    tracking.recent_assessments = [
      { id: 10, group_id: 1, subject_id: 4, type: "written", variant_number: null, created_at: "2026-08-01T10:00:00+00:00" },
      { id: 11, group_id: 1, subject_id: 4, type: "oral", variant_number: null, created_at: "2026-08-02T10:00:00+00:00" },
      { id: 12, group_id: 1, subject_id: 5, type: "written", variant_number: null, created_at: "2026-08-03T10:00:00+00:00" },
    ]
    tracking.accommodations = [
      pendingAccommodation({
        is_effective: true,
        subjects: [{ id: 4, name: "Matemática", deleted: false }],
        assessment_types: ["written"],
      }),
    ]
    vi.spyOn(trackingApi, "fetchStudentTracking").mockResolvedValue(tracking)
    vi.spyOn(trackingApi, "fetchStudentComments").mockResolvedValue([])

    renderPage("director")
    await openTab("Ajustes")
    await userEvent.click(await screen.findByRole("button", { name: /desactivar para una evaluación/i }))
    await userEvent.click(screen.getByLabelText(/^evaluación$/i))

    // Only the written Matemática one: not the oral one, not the Ciencias one.
    expect(await screen.findByRole("button", { name: /escrita · /i })).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /oral · /i })).not.toBeInTheDocument()
    expect(screen.getAllByRole("button", { name: / · / })).toHaveLength(1)
  })
})
