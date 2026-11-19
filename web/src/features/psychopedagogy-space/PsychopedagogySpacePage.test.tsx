import { render, screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import type { MeetingSheet, PsychopedagogyOverview, Role, TherapeuticCompanion } from "@/types"
import * as spaceApi from "./psychopedagogySpaceApi"
import { PsychopedagogySpacePage } from "./PsychopedagogySpacePage"
import { SheetDetail } from "./SheetsTab"

let currentRole: Role = "psychopedagogue"

vi.mock("@/features/auth/AuthContext", () => ({
  useAuth: () => ({
    user: { id: 1, name: "Laura Ferrando", email: "laura@e.com", roles: [currentRole] },
  }),
}))

const overview: PsychopedagogyOverview = {
  week_start: "2026-11-16",
  week_end: "2026-11-22",
  meetings_this_week: 7,
  active_companions: 2,
  workload: [
    { key: "individual_session", meeting_sheet_type_id: null, name: null, count: 2 },
    { key: "type-1", meeting_sheet_type_id: 1, name: "Reunión de profesores", count: 1 },
  ],
}

const companionForDirection: TherapeuticCompanion = {
  id: 3,
  name: "Romina Silva",
  student: { id: 5, name: "Lucía Pereyra" },
  group: { id: 9, name: "2°EMS-A" },
  weekly_hours: 6,
  days: ["tuesday", "thursday"],
}

function renderPage(path = "/espacio-psicopedagogia") {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <PsychopedagogySpacePage />
    </MemoryRouter>,
  )
}

describe("PsychopedagogySpacePage", () => {
  beforeEach(() => {
    vi.spyOn(spaceApi, "fetchOverview").mockResolvedValue(overview)
  })
  afterEach(() => {
    vi.restoreAllMocks()
    currentRole = "psychopedagogue"
  })

  it("keeps a teacher out without asking the server for anything", () => {
    currentRole = "teacher"
    renderPage()

    expect(screen.getByRole("heading", { name: "Este espacio no es tuyo" })).toBeInTheDocument()
    expect(screen.queryByRole("tab")).not.toBeInTheDocument()
    expect(spaceApi.fetchOverview).not.toHaveBeenCalled()
  })

  it("shows Dirección the week's workload by type, never the agenda rows", async () => {
    currentRole = "director"
    const meetings = vi.spyOn(spaceApi, "fetchMeetings")
    renderPage()

    expect(await screen.findByText("7 encuentros esta semana")).toBeInTheDocument()
    expect(screen.getByText("2 acompañantes activos")).toBeInTheDocument()
    expect(screen.getByText("Carga de la semana")).toBeInTheDocument()
    expect(screen.getByText("Sesiones individuales")).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /Agendar encuentro/ })).not.toBeInTheDocument()
    expect(meetings).not.toHaveBeenCalled()
  })

  it("lists the companions for Dirección with days and hours and no reason column", async () => {
    currentRole = "director"
    vi.spyOn(spaceApi, "fetchCompanions").mockResolvedValue([companionForDirection])
    renderPage("/espacio-psicopedagogia?solapa=acompanantes")

    const row = (await screen.findByText("Romina Silva")).closest("tr") as HTMLElement
    expect(within(row).getByText("Martes y jueves")).toBeInTheDocument()
    expect(within(row).getByText("6 h")).toBeInTheDocument()
    expect(screen.queryByText("Motivo")).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /Asignar acompañante/ })).not.toBeInTheDocument()
  })

  it("shows Psicopedagogía the reason and lets it assign a companion", async () => {
    vi.spyOn(spaceApi, "fetchCompanions").mockResolvedValue([{ ...companionForDirection, reason: "Apoyo en evaluaciones" }])
    renderPage("/espacio-psicopedagogia?solapa=acompanantes")

    expect(await screen.findByText("Apoyo en evaluaciones")).toBeInTheDocument()
    expect(screen.getByRole("columnheader", { name: "Motivo" })).toBeInTheDocument()
    expect(screen.getByRole("button", { name: /Asignar acompañante/ })).toBeInTheDocument()
  })

  it("lists only date, type and scope of each sheet for Dirección", async () => {
    currentRole = "director"
    vi.spyOn(spaceApi, "fetchSheets").mockResolvedValue({
      rows: [
        { id: 1, held_on: "2026-11-18", sheet_type: { id: 1, name: "Reunión de profesores" }, group: { id: 9, name: "2°EMS-A" } },
        { id: 2, held_on: "2026-11-17", sheet_type: { id: 2, name: "Entrevista con familia" }, group: null },
      ],
      meta: { current_page: 1, last_page: 1, total: 2 },
    })
    renderPage("/espacio-psicopedagogia?solapa=planillas")

    expect(await screen.findByText("Planillas registradas")).toBeInTheDocument()
    expect(screen.getByText("Individual")).toBeInTheDocument()
    expect(screen.getByText("2°EMS-A")).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Abrir" })).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /Nueva planilla/ })).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Ver más" })).not.toBeInTheDocument()
  })

  it("loads older sheets with «Ver más»", async () => {
    currentRole = "director"
    const fetchSheets = vi.spyOn(spaceApi, "fetchSheets").mockImplementation(async (page = 1) =>
      page === 1
        ? {
            rows: [{ id: 1, held_on: "2026-11-18", sheet_type: { id: 1, name: "Reunión de profesores" }, group: null }],
            meta: { current_page: 1, last_page: 2, total: 2 },
          }
        : {
            rows: [{ id: 2, held_on: "2025-03-10", sheet_type: { id: 2, name: "Entrevista con familia" }, group: null }],
            meta: { current_page: 2, last_page: 2, total: 2 },
          },
    )
    renderPage("/espacio-psicopedagogia?solapa=planillas")

    await userEvent.click(await screen.findByRole("button", { name: "Ver más" }))

    expect(await screen.findByText("Entrevista con familia")).toBeInTheDocument()
    expect(screen.getByText("Reunión de profesores")).toBeInTheDocument()
    expect(fetchSheets).toHaveBeenLastCalledWith(2)
    expect(screen.queryByRole("button", { name: "Ver más" })).not.toBeInTheDocument()
  })
})

const draft: MeetingSheet = {
  id: 7,
  held_on: "2026-11-18",
  sheet_type: { id: 1, name: "Reunión de profesores" },
  psychopedagogy_meeting_id: null,
  group: { id: 9, name: "2°EMS-A" },
  attendees: [
    { id: 1, name: "Laura Ferrando" },
    { id: 4, name: "Mariana López" },
  ],
  external_attendees: null,
  students: [
    { id: 5, name: "Lucía Pereyra" },
    { id: 6, name: "Nicolás Sosa" },
  ],
  content: "Se repasó el tiempo extendido de Lucía y el objetivo ampliado de Nicolás.",
  fragments: [
    { student_id: 5, content: "El tiempo extendido viene funcionando.", scope: "everyone" },
    { student_id: 6, content: "Objetivo ampliado en ecuaciones.", scope: "psychopedagogue" },
  ],
  actions: [{ id: 1, description: "Confirmar con Lucía", owner: { id: 4, name: "Mariana López" }, due_on: "2026-11-27" }],
  status: "draft",
  confirmed_at: null,
  confirmed_by_name: null,
}

describe("SheetDetail", () => {
  afterEach(() => vi.restoreAllMocks())

  it("shows the proposed split per student with its scope and confirms it", async () => {
    const confirmed: MeetingSheet = { ...draft, status: "confirmed", confirmed_by_name: "Laura Ferrando", confirmed_at: "2026-11-18T15:00:00Z" }
    const confirm = vi.spyOn(spaceApi, "confirmSheet").mockResolvedValue(confirmed)
    const onChanged = vi.fn()
    render(<SheetDetail sheet={draft} options={null} onChanged={onChanged} />)

    const split = screen.getByTestId("sheet-split")
    expect(within(split).getByText("Ficha de Lucía Pereyra · Todos los que ven al alumno")).toBeInTheDocument()
    expect(within(split).getByText("Ficha de Nicolás Sosa · Solo Psicopedagogía")).toBeInTheDocument()
    expect(within(split).getByText(/Nada baja a las fichas sin tu confirmación/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole("button", { name: "Confirmar el reparto" }))

    expect(confirm).toHaveBeenCalledWith(7)
    expect(onChanged).toHaveBeenCalledWith(confirmed)
  })

  it("cannot be confirmed without fragments, and a confirmed one says who confirmed it", () => {
    const { rerender } = render(<SheetDetail sheet={{ ...draft, fragments: [] }} options={null} onChanged={vi.fn()} />)
    expect(screen.getByRole("button", { name: "Confirmar el reparto" })).toBeDisabled()

    rerender(
      <SheetDetail
        sheet={{ ...draft, status: "confirmed", confirmed_by_name: "Laura Ferrando", confirmed_at: "2026-11-18T15:00:00Z" }}
        options={null}
        onChanged={vi.fn()}
      />,
    )
    expect(screen.queryByRole("button", { name: "Confirmar el reparto" })).not.toBeInTheDocument()
    expect(screen.getByText(/Laura Ferrando confirmó el reparto/)).toBeInTheDocument()
  })
})
