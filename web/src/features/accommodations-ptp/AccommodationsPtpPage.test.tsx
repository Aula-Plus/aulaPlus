import { render, screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import * as groupsApi from "@/features/groups/groupsApi"
import * as trackingApi from "@/features/tracking/trackingApi"
import type { AccommodationInboxRow, Role } from "@/types"
import * as ptpApi from "./accommodationsPtpApi"
import { AccommodationsPtpPage } from "./AccommodationsPtpPage"

function row(overrides: Partial<AccommodationInboxRow>): AccommodationInboxRow {
  return {
    id: 1,
    student_id: 10,
    type: "Tiempo extendido 25%",
    category: "access",
    active: true,
    starts_on: "2026-03-15",
    deactivated_on: null,
    subjects: [],
    assessment_types: null,
    description: "En todas las evaluaciones escritas formales.",
    focus_area: "lectura",
    origin: "Registrado por Psicopedagogía",
    origin_report_id: null,
    is_effective: true,
    review_status: null,
    proposed_by_id: null,
    proposed_as: null,
    proposed_by_name: null,
    reviewed_by_id: null,
    reviewed_by_name: null,
    reviewed_at: null,
    review_note: null,
    observation: null,
    created_by_id: 5,
    created_at: null,
    updated_at: null,
    inbox_status: "in_force",
    student: { id: 10, name: "Lucía Pereyra" },
    group: { id: 3, name: "2°EMS-A" },
    barriers: [{ id: 7, description: "lectura de textos largos" }],
    confirmed_by_name: "Laura Ferrando",
    ...overrides,
  }
}

function renderPage(role: Role, path = "/ajustes-y-ptp") {
  const value: AuthContextValue = {
    user: { id: 1, name: "Laura", email: "laura@escuela.test", roles: [role] },
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
  }
  return render(
    <AuthContext value={value}>
      <MemoryRouter initialEntries={[path]}>
        <AccommodationsPtpPage />
      </MemoryRouter>
    </AuthContext>,
  )
}

describe("AccommodationsPtpPage", () => {
  afterEach(() => vi.restoreAllMocks())

  it("lists the school's accommodations for psychopedagogy, with who confirmed and the barrier", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])
    const fetchInbox = vi.spyOn(ptpApi, "fetchAccommodationInbox").mockResolvedValue({
      data: [
        row({}),
        row({
          id: 2,
          type: "Anticipar los cambios de actividad",
          inbox_status: "pending",
          review_status: "pending",
          is_effective: false,
          proposed_by_name: "Adriana Ruiz",
          confirmed_by_name: null,
          barriers: [],
        }),
      ],
      meta: { current_page: 1, last_page: 1, total: 2 },
      report_proposals: [
        {
          report_id: 4,
          student: { id: 11, name: "Tomás Ibarra" },
          group: { id: 5, name: "1°EMS-A" },
          uploaded_on: "2026-11-10",
          pending_count: 2,
        },
      ],
    })

    renderPage("psychopedagogue")

    expect(await screen.findByText("Lucía Pereyra — 2°EMS-A · Tiempo extendido 25%")).toBeInTheDocument()
    expect(screen.getByText(/Barrera: lectura de textos largos/)).toBeInTheDocument()
    expect(screen.getByText(/Confirmó Laura Ferrando/)).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Dar de baja" })).toBeInTheDocument()
    // A direction proposal is reviewed right here.
    expect(screen.getByText(/Propuesta de Dirección por Adriana Ruiz/)).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Confirmar" })).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Devolver para corregir" })).toBeInTheDocument()
    // A report waiting: date and count, reviewed in the file.
    expect(screen.getByText(/Aula\+ interpretó 2 ajustes posibles/)).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "Revisar en la ficha" })).toHaveAttribute(
      "href",
      "/alumnos/11/seguimiento",
    )
    expect(fetchInbox).toHaveBeenCalledWith({})
  })

  it("switches an accommodation off from the inbox", async () => {
    const user = userEvent.setup()
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])
    vi.spyOn(ptpApi, "fetchAccommodationInbox").mockResolvedValue({
      data: [row({})],
      meta: { current_page: 1, last_page: 1, total: 1 },
      report_proposals: [],
    })
    const update = vi.spyOn(trackingApi, "updateAccommodation").mockResolvedValue(row({ active: false }))

    renderPage("psychopedagogue")

    await user.click(await screen.findByRole("button", { name: "Dar de baja" }))
    const dialog = await screen.findByRole("dialog")
    await user.click(within(dialog).getByRole("button", { name: "Dar de baja" }))

    expect(update).toHaveBeenCalledWith(1, expect.objectContaining({ active: false, category: "access" }))
  })

  it("marks this year's review from «Revisión anual»", async () => {
    const user = userEvent.setup()
    vi.spyOn(ptpApi, "fetchAnnualReviews").mockResolvedValue({
      schoolYear: 2026,
      rows: [
        {
          student: { id: 12, name: "Manuel Piñeiro" },
          group: { id: 5, name: "1°EMS-A" },
          accommodations_in_force_count: 1,
          last_review: null,
          reviewed_this_year: false,
        },
        {
          student: { id: 10, name: "Lucía Pereyra" },
          group: { id: 3, name: "2°EMS-A" },
          accommodations_in_force_count: 3,
          last_review: { reviewed_on: "2026-06-18", reviewed_by_name: "Laura Ferrando", school_year: 2026 },
          reviewed_this_year: true,
        },
      ],
    })
    const mark = vi.spyOn(ptpApi, "markAnnualReview").mockResolvedValue({
      reviewed_on: "2026-11-15",
      reviewed_by_name: "Laura Ferrando",
      school_year: 2026,
    })

    renderPage("psychopedagogue", "/ajustes-y-ptp?solapa=revision-anual")

    expect(screen.getByRole("tab", { name: "Revisión anual" })).toHaveAttribute("aria-selected", "true")
    expect(await screen.findByText("Nunca")).toBeInTheDocument()
    expect(screen.getAllByText(/· Laura Ferrando$/)).toHaveLength(1)

    await user.click(screen.getByRole("button", { name: "Marcar revisado hoy" }))

    expect(mark).toHaveBeenCalledWith(12)
    expect(await screen.findAllByText(/· Laura Ferrando$/)).toHaveLength(2)
    expect(screen.queryByRole("button", { name: "Marcar revisado hoy" })).not.toBeInTheDocument()
  })

  it("pages through the inbox and keeps the filters", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])
    const fetchInbox = vi
      .spyOn(ptpApi, "fetchAccommodationInbox")
      .mockResolvedValueOnce({
        data: [row({})],
        meta: { current_page: 1, last_page: 2, total: 51 },
        report_proposals: [],
      })
      .mockResolvedValueOnce({
        data: [row({ id: 9, type: "Segunda página" })],
        meta: { current_page: 2, last_page: 2, total: 51 },
        report_proposals: [],
      })

    renderPage("psychopedagogue")

    expect(await screen.findByText("Página 1 de 2 (51 ajustes)")).toBeInTheDocument()
    await userEvent.click(screen.getByRole("button", { name: "Siguiente" }))

    expect(await screen.findByText(/Segunda página/)).toBeInTheDocument()
    expect(fetchInbox).toHaveBeenLastCalledWith({ page: 2 })
    expect(screen.getByRole("button", { name: "Siguiente" })).toBeDisabled()
  })

  it.each([
    ["teacher", /en la ficha de cada uno/],
    ["director", /usá «Proponer ajuste» en la ficha/],
  ] as const)("shows %s where they see accommodations, without loading the inbox", (role, text) => {
    const fetchInbox = vi.spyOn(ptpApi, "fetchAccommodationInbox")

    renderPage(role)

    expect(screen.getByText(text)).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "Volver al inicio" })).toHaveAttribute("href", "/")
    expect(fetchInbox).not.toHaveBeenCalled()
  })
})
