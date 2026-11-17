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
      can_reopen: false,
      reopened_at: null,
      reopened_by_name: null,
      ...overrides,
    },
    next_year: 2027,
    previous: { year: 2025, status: "closed", closed_at: "2025-12-15T15:00:00+00:00" },
    active_groups: 7,
    carried_accommodations: 27,
    calendars: [
      {
        key: "body:anep_upper_secondary",
        body: "anep_upper_secondary",
        curricular_framework_id: null,
        name: null,
        programs: ["ANEP Bachillerato"],
        can_split: false,
        periods: [
          { id: 21, name: "Trimestre 2", starts_on: "2026-06-01", state: "past" },
          { id: 22, name: "Trimestre 3", starts_on: "2026-09-14", state: "current" },
        ],
      },
      {
        key: "body:anep_basic",
        body: "anep_basic",
        curricular_framework_id: null,
        name: null,
        programs: ["ANEP EBI"],
        can_split: false,
        periods: [{ id: 11, name: "Semestre 2", starts_on: "2026-07-27", state: "current" }],
      },
    ],
    split_bodies: [],
    closed_year_groups: [],
    write_windows: [],
  }
}

function closedYear(overrides: Partial<SchoolYearState["year"]> = {}): SchoolYearState {
  const state = openYear({
    status: "closed",
    can_close: false,
    closed_at: "2026-12-01T13:00:00+00:00",
    closed_by_name: "Laura",
    can_reopen: true,
    ...overrides,
  })
  return {
    ...state,
    calendars: state.calendars.map((calendar) => ({
      ...calendar,
      periods: calendar.periods.map((period) => ({ ...period, state: "past" as const })),
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
  it("shows the open year with one row of periods per body", async () => {
    vi.spyOn(schoolYearApi, "fetchSchoolYear").mockResolvedValue(openYear())
    renderPage()

    expect(await screen.findByText("Año 2026 abierto")).toBeInTheDocument()
    expect(screen.getByText("2025 · cerrado el 15/12/2025")).toBeInTheDocument()
    expect(screen.getByText(/ANEP Bachillerato: Trimestre 3 en curso · ANEP Ciclo Básico y Primaria: Semestre 2 en curso/)).toBeInTheDocument()
    const table = screen.getByRole("table")
    expect(within(table).getByText("14/09/2026")).toBeInTheDocument()
    expect(within(table).getAllByText("En curso")).toHaveLength(2)
    expect(within(table).getByText("ANEP Ciclo Básico y Primaria")).toBeInTheDocument()
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
    const semestre = screen.getByRole("radiogroup", { name: "Semestre 2 de ANEP Ciclo Básico y Primaria" })
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

  it("still enables loading in a 2026 group once 2027 is open", async () => {
    const state: SchoolYearState = {
      ...openYear(),
      year: { ...openYear().year, id: 8, year: 2027 },
      next_year: 2028,
      closed_year_groups: [{ id: 5, name: "2°EMS-A", school_year: 2026 }],
    }
    vi.spyOn(schoolYearApi, "fetchSchoolYear").mockResolvedValue(state)
    const create = vi.spyOn(schoolYearApi, "createWriteWindow").mockResolvedValue(state)
    renderPage()

    await userEvent.selectOptions(await screen.findByLabelText("Grupo"), "5")
    expect(screen.getByRole("option", { name: "2°EMS-A (2026)" })).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText("Motivo"), "Exámenes de febrero")
    await userEvent.type(screen.getByLabelText("Hasta"), "2027-02-28")
    await userEvent.click(screen.getByRole("button", { name: "Habilitar la carga" }))

    expect(create).toHaveBeenCalledWith(8, { group_id: 5, reason: "Exámenes de febrero", until_on: "2027-02-28" })
  })

  it("splits Cambridge by program and joins it back", async () => {
    const cambridge: SchoolYearState["calendars"][number] = {
      key: "body:cambridge",
      body: "cambridge",
      curricular_framework_id: null,
      name: null,
      programs: ["Cambridge Lower Secondary", "Cambridge Primary"],
      can_split: true,
      periods: [{ id: 31, name: "Term 1", starts_on: "2026-03-02", state: "past" }],
    }
    const base = openYear()
    const split: SchoolYearState = {
      ...base,
      split_bodies: ["cambridge"],
      calendars: [
        ...base.calendars,
        ...[["Cambridge Lower Secondary", 41], ["Cambridge Primary", 42]].map(([name, id]) => ({
          key: `program:${id}`,
          body: "cambridge" as const,
          curricular_framework_id: id as number,
          name: name as string,
          programs: [name as string],
          can_split: false,
          periods: [{ id: (id as number) * 10, name: "Term 1", starts_on: "2026-03-02", state: "past" as const }],
        })),
      ],
    }
    vi.spyOn(schoolYearApi, "fetchSchoolYear").mockResolvedValue({ ...base, calendars: [...base.calendars, cambridge] })
    const splitBody = vi.spyOn(schoolYearApi, "splitBody").mockResolvedValue(split)
    const joinBody = vi.spyOn(schoolYearApi, "joinBody").mockResolvedValue(base)
    renderPage()

    expect(await screen.findByText("Cambridge Lower Secondary, Cambridge Primary")).toBeInTheDocument()
    expect(screen.getAllByRole("button", { name: "Separar por programa" })).toHaveLength(1)
    await userEvent.click(screen.getByRole("button", { name: "Separar por programa" }))

    expect(splitBody).toHaveBeenCalledWith(7, "cambridge")
    expect(await screen.findByText("Cambridge quedó separado por programa.")).toBeInTheDocument()
    expect(screen.getAllByText("Cambridge, separado por programa")).toHaveLength(2)

    // A new period goes to one program of the split body.
    const create = vi.spyOn(schoolYearApi, "createPeriod").mockResolvedValue(split)
    await userEvent.selectOptions(screen.getByLabelText("Organismo o programa"), "program:42")
    await userEvent.type(screen.getByLabelText("Nombre"), "Term 2")
    await userEvent.type(screen.getByLabelText("Desde"), "2026-08-03")
    await userEvent.click(screen.getByRole("button", { name: "Agregar período" }))
    expect(create).toHaveBeenCalledWith(7, { curricular_framework_id: 42, name: "Term 2", starts_on: "2026-08-03" })

    await userEvent.click(screen.getByRole("button", { name: "Volver a una fila: Cambridge" }))
    expect(joinBody).toHaveBeenCalledWith(7, "cambridge")
  })

  it("adds a period to a body row", async () => {
    vi.spyOn(schoolYearApi, "fetchSchoolYear").mockResolvedValue(openYear())
    const create = vi.spyOn(schoolYearApi, "createPeriod").mockResolvedValue(openYear())
    renderPage()

    await userEvent.selectOptions(await screen.findByLabelText("Organismo o programa"), "body:anep_basic")
    await userEvent.type(screen.getByLabelText("Nombre"), "Semestre 3")
    await userEvent.type(screen.getByLabelText("Desde"), "2026-11-30")
    await userEvent.click(screen.getByRole("button", { name: "Agregar período" }))

    expect(create).toHaveBeenCalledWith(7, { calendar_body: "anep_basic", name: "Semestre 3", starts_on: "2026-11-30" })
  })

  it("undoes the closing after confirming", async () => {
    vi.spyOn(schoolYearApi, "fetchSchoolYear").mockResolvedValue(closedYear())
    const reopen = vi
      .spyOn(schoolYearApi, "reopenYear")
      .mockResolvedValue(openYear({ can_close: true, reopened_at: "2026-12-02T12:30:00+00:00", reopened_by_name: "Laura" }))
    renderPage()

    await userEvent.click(await screen.findByRole("button", { name: "Deshacer el cierre" }))
    expect(reopen).not.toHaveBeenCalled()
    expect(screen.getByText(/queda registrado quién lo hizo y cuándo/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole("button", { name: "Sí, deshacer el cierre" }))

    expect(reopen).toHaveBeenCalledWith(7)
    expect(await screen.findByText("Año 2026 abierto")).toBeInTheDocument()
    expect(screen.getByText("Se deshizo el cierre el 02/12/2026 (lo hizo Laura).")).toBeInTheDocument()
  })

  it("does not offer undoing the closing once it cannot be undone", async () => {
    vi.spyOn(schoolYearApi, "fetchSchoolYear").mockResolvedValue(closedYear({ can_reopen: false }))
    renderPage()

    expect(await screen.findByRole("button", { name: "Abrir el año 2027" })).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Deshacer el cierre" })).not.toBeInTheDocument()
  })

  it("is closed to every role but direction", () => {
    const fetch = vi.spyOn(schoolYearApi, "fetchSchoolYear")
    renderPage("psychopedagogue")

    expect(screen.getByText("Esta pantalla no está disponible para tu cuenta")).toBeInTheDocument()
    expect(fetch).not.toHaveBeenCalled()
  })
})
