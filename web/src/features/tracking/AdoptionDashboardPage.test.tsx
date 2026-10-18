import { render, screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import { AdoptionDashboardPage } from "./AdoptionDashboardPage"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import * as adoptionApi from "./adoptionApi"
import { formatDateTime } from "@/lib/utils"
import type { AdoptionDashboard, JourneyDataSince, Role } from "@/types"

const noData: JourneyDataSince = {
  form_opened: null,
  filling_started: null,
  generation_requested: null,
  regeneration_requested: null,
  generation_succeeded: null,
  generation_failed: null,
  creation_confirmed: null,
  draft_discarded: null,
}

function dashboard(overrides: Partial<AdoptionDashboard> = {}): AdoptionDashboard {
  return {
    teacher_started_rate_30d: 0,
    teacher_planning_rate_30d: 0,
    data_since: noData,
    weekly_content_series: [],
    weekly_content_by_type: [],
    ...overrides,
  }
}

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

  it("shows creation signals instead of the login indicators", async () => {
    vi.spyOn(adoptionApi, "fetchAdoptionDashboard").mockResolvedValue(
      dashboard({
        teacher_started_rate_30d: 75,
        teacher_planning_rate_30d: 40,
        data_since: { ...noData, form_opened: "2026-10-01T10:00:00-03:00" },
      }),
    )

    renderPage("director")

    expect(await screen.findByText("75%")).toBeInTheDocument()
    expect(screen.getByText("Docentes que empezaron a crear")).toBeInTheDocument()
    expect(screen.getByText("40%")).toBeInTheDocument()
    expect(screen.getByText("Docentes con creaciones confirmadas")).toBeInTheDocument()
    expect(screen.getByText("Contenido creado por semana")).toBeInTheDocument()
    expect(screen.queryByText(/docentes con login/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/logins por semana/i)).not.toBeInTheDocument()
    // Since when each step has data; no record reads «dato no disponible».
    expect(screen.getByText(/desde 01 oct 2026/i)).toBeInTheDocument()
    expect(screen.getAllByText("dato no disponible").length).toBeGreaterThan(0)
  })

  it("shows «dato no disponible» rather than 0 when nothing of a step was recorded", async () => {
    vi.spyOn(adoptionApi, "fetchAdoptionDashboard").mockResolvedValue(
      dashboard({ teacher_started_rate_30d: null }),
    )

    renderPage("director")

    const label = await screen.findByText("Docentes que empezaron a crear")
    expect(label.parentElement).toHaveTextContent("dato no disponible")
    expect(screen.queryByText(/no intentó/i)).not.toBeInTheDocument()
  })

  it("separates confirmed creations from attempts per teacher and reveals the last login only on demand", async () => {
    vi.spyOn(adoptionApi, "fetchAdoptionDashboard").mockResolvedValue(dashboard())
    vi.spyOn(adoptionApi, "fetchAdoptionTeachers").mockResolvedValue({
      rows: [
        {
          id: 7,
          name: "Ana Fernández",
          subjects: ["Ciencias"],
          month_counts: { annual_plans: 2, class_sessions: 4, assessments: 1 },
          month_attempts: { annual_plans: null, class_sessions: null, assessments: null },
        },
      ],
      dataSince: noData,
    })
    const lastLogin = vi
      .spyOn(adoptionApi, "fetchTeacherLastLoginRange")
      .mockResolvedValue("this_week")

    renderPage("director")
    await userEvent.click(await screen.findByRole("tab", { name: "Por docente" }))

    expect(await screen.findByText(/Ana Fernández/)).toBeInTheDocument()
    expect(
      screen.getByText("Creaciones confirmadas: 4 clases, 1 evaluación y 2 programas este mes"),
    ).toBeInTheDocument()
    expect(screen.getByText("Intentos con el asistente: dato no disponible")).toBeInTheDocument()
    expect(screen.queryByText(/proyecto/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/último ingreso/i)).not.toBeInTheDocument()
    expect(lastLogin).not.toHaveBeenCalled()

    await userEvent.click(screen.getByRole("button", { name: "Ver detalles de uso" }))

    expect(await screen.findByText("Último ingreso: esta semana")).toBeInTheDocument()
    expect(lastLogin).toHaveBeenCalledWith(42, 7)
  })

  it("opens a teacher's journeys with the date and time of each recorded step", async () => {
    vi.spyOn(adoptionApi, "fetchAdoptionDashboard").mockResolvedValue(dashboard())
    vi.spyOn(adoptionApi, "fetchAdoptionTeachers").mockResolvedValue({
      rows: [
        {
          id: 7,
          name: "Ana Fernández",
          subjects: [],
          month_counts: { annual_plans: 0, class_sessions: 0, assessments: 1 },
          month_attempts: { annual_plans: 0, class_sessions: 1, assessments: 0 },
        },
      ],
      dataSince: { ...noData, generation_requested: "2026-10-02T09:00:00Z" },
    })
    const fetchJourneys = vi.spyOn(adoptionApi, "fetchTeacherJourneys").mockResolvedValue([
      {
        id: "j-1",
        content_type: "assessment",
        flow: "manual",
        started_at: "2026-10-05T12:15:00Z",
        confirmed: true,
        last_step: { step: "creation_confirmed", at: "2026-10-05T12:20:00Z" },
        steps: [
          { step: "form_opened", at: "2026-10-05T12:15:00Z" },
          { step: "creation_confirmed", at: "2026-10-05T12:20:00Z" },
        ],
        title: null,
        group_name: "3° A",
        subject_name: "Ciencias",
        content: { type: "assessment", id: 5, group_id: 3 },
      },
      {
        id: "j-2",
        content_type: "class_session",
        flow: "ai",
        started_at: "2026-10-04T12:00:00Z",
        confirmed: false,
        last_step: { step: "generation_failed", at: "2026-10-04T12:01:00Z", failure_reason: "provider_error" },
        steps: [
          { step: "generation_requested", at: "2026-10-04T12:00:00Z" },
          { step: "generation_failed", at: "2026-10-04T12:01:00Z", failure_reason: "provider_error" },
        ],
        title: null,
        group_name: "3° A",
        subject_name: null,
        content: null,
      },
    ])

    renderPage("director")
    await userEvent.click(await screen.findByRole("tab", { name: "Por docente" }))
    expect(await screen.findByText("Intentos con el asistente: 1 clase")).toBeInTheDocument()
    expect(screen.getByText(/Datos de intentos desde 02 oct 2026/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole("button", { name: "Ver recorridos" }))

    const dialog = await screen.findByRole("dialog")
    expect(fetchJourneys).toHaveBeenCalledWith(42, 7)
    expect(await within(dialog).findByText("Recorridos de Ana Fernández")).toBeInTheDocument()

    const [manual, ai] = within(dialog).getAllByRole("list", { name: "Pasos registrados" })
    expect(within(manual).getByText("Abrió el formulario")).toBeInTheDocument()
    expect(within(manual).getAllByRole("time")[0]).toHaveTextContent(
      formatDateTime("2026-10-05T12:15:00Z"),
    )
    // Date AND time, e.g. "05 oct 2026, 09:15".
    expect(within(manual).getAllByRole("time")[0].textContent).toMatch(/2026.*\d{2}:\d{2}/)
    // Missing in both journeys (the AI one: no assistant screen reports it yet).
    expect(within(dialog).getAllByText("Empezó a completar: dato no disponible")).toHaveLength(2)
    expect(within(dialog).getByText("Intentos: no aplica (creación manual)")).toBeInTheDocument()
    expect(within(dialog).getByRole("link", { name: "Abrir evaluación" })).toHaveAttribute(
      "href",
      "/clases/3/evaluaciones",
    )

    expect(
      within(ai).getByText(
        "La generación falló: El asistente tuvo un problema y no pudo responder.",
      ),
    ).toBeInTheDocument()
    expect(within(dialog).getByText("Abrió el formulario: dato no disponible")).toBeInTheDocument()
    expect(within(dialog).queryByText(/no intentó|abandon/i)).not.toBeInTheDocument()
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
