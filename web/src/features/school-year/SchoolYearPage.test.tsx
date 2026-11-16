import { render, screen, within } from "@testing-library/react"
import { MemoryRouter } from "react-router-dom"
import userEvent from "@testing-library/user-event"
import { afterEach, describe, expect, it, vi } from "vitest"
import { AuthContext } from "@/features/auth/AuthContext"
import type { Role, User } from "@/types"
import { SchoolYearPage } from "./SchoolYearPage"
import * as schoolYearApi from "./schoolYearApi"
import type { SchoolYearState } from "./schoolYearApi"

function openYear(overrides: Partial<SchoolYearState["year"]> = {}): SchoolYearState {
  return {
    year: {
      id: 7,
      year: 2026,
      status: "open",
      closing_enabled_from: "2026-12-01",
      can_close: false,
      closed_at: null,
      closed_by_name: null,
      ...overrides,
    },
    next_year: 2027,
    previous: { year: 2025, status: "closed", closed_at: "2025-12-15T15:00:00+00:00" },
    active_groups: 7,
    carried_accommodations: 27,
    programs: [
      {
        id: 2,
        name: "ANEP Bachillerato",
        periods: [
          { id: 21, name: "Trimestre 2", starts_on: "2026-06-01", state: "past" },
          { id: 22, name: "Trimestre 3", starts_on: "2026-09-14", state: "current" },
        ],
      },
      {
        id: 1,
        name: "ANEP EBI",
        periods: [{ id: 11, name: "Semestre 2", starts_on: "2026-07-27", state: "current" }],
      },
    ],
    closed_year_groups: [],
    write_windows: [],
  }
}

function closedYear(): SchoolYearState {
  const state = openYear({ status: "closed", can_close: false, closed_at: "2026-12-01T13:00:00+00:00", closed_by_name: "Laura" })
  return {
    ...state,
    programs: state.programs.map((program) => ({
      ...program,
      periods: program.periods.map((period) => ({ ...period, state: "past" as const })),
    })),
    closed_year_groups: [{ id: 5, name: "2°EMS-A", school_year: 2026 }],
  }
}

function renderPage(role: Role = "director") {
  const user = { id: 1, name: "Laura", email: "d@x.uy", school_id: 1, roles: [role] } as unknown as User
  return render(
    <AuthContext.Provider value={{ user, loading: false, login: vi.fn(), logout: vi.fn() } as never}>
      <MemoryRouter>
        <SchoolYearPage />
      </MemoryRouter>
    </AuthContext.Provider>,
  )
}

afterEach(() => vi.restoreAllMocks())

describe("SchoolYearPage («Año y calendario»)", () => {
  it("shows the open year with each program's periods separately", async () => {
    vi.spyOn(schoolYearApi, "fetchSchoolYear").mockResolvedValue(openYear())
    renderPage()

    expect(await screen.findByText("Año 2026 abierto")).toBeInTheDocument()
    expect(screen.getByText("2025 · cerrado el 15/12/2025")).toBeInTheDocument()
    expect(screen.getByText(/ANEP Bachillerato: Trimestre 3 en curso · ANEP EBI: Semestre 2 en curso/)).toBeInTheDocument()
    const table = screen.getByRole("table")
    expect(within(table).getByText("14/09/2026")).toBeInTheDocument()
    expect(within(table).getAllByText("En curso")).toHaveLength(2)
    expect(within(table).getByText("ANEP EBI")).toBeInTheDocument()
  })

  it("writes the consequence before the button and keeps it disabled until the chosen date", async () => {
    vi.spyOn(schoolYearApi, "fetchSchoolYear").mockResolvedValue(openYear())
    renderPage()

    expect(await screen.findByText(/no se borra ni se mueve nada/)).toBeInTheDocument()
    expect(screen.getByText(/pasan 27 ajustes vigentes/)).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Cerrar el año 2026" })).toBeDisabled()
    expect(screen.getByText("El cierre se habilita desde el 01/12/2026 y pide confirmación.")).toBeInTheDocument()
  })

  it("lets direction choose the closing date", async () => {
    vi.spyOn(schoolYearApi, "fetchSchoolYear").mockResolvedValue(openYear({ closing_enabled_from: null }))
    const update = vi
      .spyOn(schoolYearApi, "updateClosingDate")
      .mockResolvedValue(openYear({ closing_enabled_from: "2026-12-05" }))
    renderPage()

    const input = await screen.findByLabelText("Cierre habilitado desde")
    await userEvent.type(input, "2026-12-05")
    await userEvent.click(screen.getByRole("button", { name: "Guardar fecha" }))

    expect(update).toHaveBeenCalledWith(7, "2026-12-05")
    expect(await screen.findByText("Fecha de cierre guardada.")).toBeInTheDocument()
  })

  it("closes the year after confirming", async () => {
    vi.spyOn(schoolYearApi, "fetchSchoolYear").mockResolvedValue(openYear({ can_close: true }))
    const close = vi.spyOn(schoolYearApi, "closeYear").mockResolvedValue(closedYear())
    renderPage()

    await userEvent.click(await screen.findByRole("button", { name: "Cerrar el año 2026" }))
    expect(close).not.toHaveBeenCalled()
    await userEvent.click(screen.getByRole("button", { name: "Sí, cerrar el año 2026" }))

    expect(close).toHaveBeenCalledWith(7)
    expect(await screen.findByText("Año 2026 cerrado")).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Abrir el año 2027" })).toBeInTheDocument()
  })

  it("opens 2027 choosing per period: same date, other date or not carried", async () => {
    vi.spyOn(schoolYearApi, "fetchSchoolYear").mockResolvedValue(closedYear())
    const open = vi.spyOn(schoolYearApi, "openNextYear").mockResolvedValue(openYear())
    renderPage()

    await userEvent.click(await screen.findByRole("button", { name: "Abrir el año 2027" }))
    const trimestre2 = screen.getByRole("radiogroup", { name: "Trimestre 2 de ANEP Bachillerato" })
    expect(within(trimestre2).getByLabelText("Misma fecha (01/06/2027)")).toBeChecked()
    await userEvent.click(within(trimestre2).getByLabelText("Otra fecha"))
    const date = screen.getByLabelText("Nueva fecha de Trimestre 2 de ANEP Bachillerato")
    await userEvent.clear(date)
    await userEvent.type(date, "2027-06-07")
    const semestre = screen.getByRole("radiogroup", { name: "Semestre 2 de ANEP EBI" })
    await userEvent.click(within(semestre).getByLabelText("No pasa"))

    await userEvent.click(screen.getAllByRole("button", { name: "Abrir el año 2027" }).at(-1)!)

    expect(open).toHaveBeenCalledWith(7, [
      { id: 21, action: "other_date", starts_on: "2027-06-07" },
      { id: 22, action: "same_date" },
      { id: 11, action: "skip" },
    ])
  })

  it("enables loading in one group of the closed year with a reason and a date", async () => {
    vi.spyOn(schoolYearApi, "fetchSchoolYear").mockResolvedValue(closedYear())
    const withWindow: SchoolYearState = {
      ...closedYear(),
      write_windows: [
        {
          id: 3,
          group: { id: 5, name: "2°EMS-A", school_year: 2026 },
          reason: "Exámenes de febrero",
          until_on: "2027-02-28",
          enabled_by_name: "Laura",
          enabled_at: "2026-12-02T13:00:00+00:00",
          ended_at: null,
          ended_by_name: null,
          active: true,
        },
      ],
    }
    const create = vi.spyOn(schoolYearApi, "createWriteWindow").mockResolvedValue(withWindow)
    renderPage()

    await userEvent.selectOptions(await screen.findByLabelText("Grupo"), "5")
    await userEvent.type(screen.getByLabelText("Motivo"), "Exámenes de febrero")
    await userEvent.type(screen.getByLabelText("Hasta"), "2027-02-28")
    await userEvent.click(screen.getByRole("button", { name: "Habilitar la carga" }))

    expect(create).toHaveBeenCalledWith(7, { group_id: 5, reason: "Exámenes de febrero", until_on: "2027-02-28" })
    const list = await screen.findByRole("list", { name: "Habilitaciones de carga" })
    expect(within(list).getByText("2°EMS-A · Exámenes de febrero")).toBeInTheDocument()
    expect(within(list).getByText(/Hasta el 28\/02\/2027 · habilitó Laura el 02\/12\/2026/)).toBeInTheDocument()
    expect(within(list).getByText("Vigente")).toBeInTheDocument()
  })

  it("is closed to every role but direction", () => {
    const fetch = vi.spyOn(schoolYearApi, "fetchSchoolYear")
    renderPage("psychopedagogue")

    expect(screen.getByText("Esta pantalla no está disponible para tu cuenta")).toBeInTheDocument()
    expect(fetch).not.toHaveBeenCalled()
  })
})
