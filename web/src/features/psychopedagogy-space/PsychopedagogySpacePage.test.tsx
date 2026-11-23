import { render, screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import type { MeetingSheet, PsychopedagogyOverview, Role, TherapeuticCompanion } from "@/types"
import * as spaceApi from "./psychopedagogySpaceApi"
import { PsychopedagogySpacePage } from "./PsychopedagogySpacePage"
import { SheetDetail } from "./SheetsTab"
import * as groupsApi from "@/features/groups/groupsApi"
import * as studentsApi from "@/features/students/studentsApi"
import { AxiosError, AxiosHeaders } from "axios"

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
  role: "Apoyo en el aula",
  ended_on: null,
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

  it("lists the companions for Dirección with role, days and hours, read-only", async () => {
    currentRole = "director"
    vi.spyOn(spaceApi, "fetchCompanions").mockResolvedValue([companionForDirection])
    renderPage("/espacio-psicopedagogia?solapa=acompanantes")

    const row = (await screen.findByText("Romina Silva")).closest("tr") as HTMLElement
    expect(within(row).getByText("Martes y jueves")).toBeInTheDocument()
    expect(within(row).getByText("6 h")).toBeInTheDocument()
    expect(within(row).getByText("Apoyo en el aula")).toBeInTheDocument()
    expect(screen.getByRole("columnheader", { name: "Rol del acompañante" })).toBeInTheDocument()
    expect(screen.queryByText("Motivo")).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /Asignar acompañante/ })).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Dejó de acompañar" })).not.toBeInTheDocument()
  })

  it("shows Psicopedagogía the current companions, the history apart, and lets it assign one", async () => {
    vi.spyOn(spaceApi, "fetchCompanions").mockResolvedValue([
      companionForDirection,
      { ...companionForDirection, id: 4, name: "Diego Núñez", role: "Acompañante en recreos", ended_on: "2026-10-30" },
    ])
    renderPage("/espacio-psicopedagogia?solapa=acompanantes")

    const current = (await screen.findByText("Romina Silva")).closest("tr") as HTMLElement
    expect(within(current).getByRole("button", { name: "Dejó de acompañar" })).toBeInTheDocument()
    const history = screen.getByTestId("companions-history")
    const ended = within(history).getByText("Diego Núñez").closest("tr") as HTMLElement
    expect(within(ended).getByText("30 oct 2026")).toBeInTheDocument()
    expect(within(ended).queryByRole("button")).not.toBeInTheDocument()
    expect(within(history).queryByText("Romina Silva")).not.toBeInTheDocument()
    expect(screen.queryByText("Motivo")).not.toBeInTheDocument()
    expect(screen.getByRole("button", { name: /Asignar acompañante/ })).toBeInTheDocument()
  })

  it("ends a companion with a date and refreshes the list and the counts", async () => {
    const fetchCompanions = vi
      .spyOn(spaceApi, "fetchCompanions")
      .mockResolvedValueOnce([companionForDirection])
      .mockResolvedValue([{ ...companionForDirection, ended_on: "2026-11-20" }])
    const end = vi.spyOn(spaceApi, "endCompanion").mockResolvedValue({ ...companionForDirection, ended_on: "2026-11-20" })
    renderPage("/espacio-psicopedagogia?solapa=acompanantes")

    await userEvent.click(await screen.findByRole("button", { name: "Dejó de acompañar" }))
    const date = screen.getByLabelText("Fecha de fin")
    await userEvent.clear(date)
    await userEvent.type(date, "2026-11-20")
    await userEvent.click(screen.getByRole("button", { name: "Guardar fecha de fin" }))

    expect(end).toHaveBeenCalledWith(3, "2026-11-20")
    expect(await screen.findByText("No hay acompañantes vigentes.")).toBeInTheDocument()
    expect(within(screen.getByTestId("companions-history")).getByText("20 nov 2026")).toBeInTheDocument()
    expect(fetchCompanions).toHaveBeenCalledTimes(2)
    expect(spaceApi.fetchOverview).toHaveBeenCalledTimes(2)
  })

  it("refreshes «N encuentros esta semana» right after scheduling", async () => {
    vi.spyOn(spaceApi, "fetchOverview")
      .mockResolvedValueOnce(overview)
      .mockResolvedValue({ ...overview, meetings_this_week: 8 })
    vi.spyOn(spaceApi, "fetchMeetings").mockResolvedValue([])
    vi.spyOn(spaceApi, "fetchMeetingSheetTypes").mockResolvedValue([])
    vi.spyOn(spaceApi, "fetchStaff").mockResolvedValue([])
    const create = vi.spyOn(spaceApi, "createMeeting").mockResolvedValue({} as never)
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])
    vi.spyOn(studentsApi, "fetchStudents").mockResolvedValue([
      { id: 5, full_name: "Lucía Pereyra" } as never,
    ])
    renderPage()

    expect(await screen.findByText("7 encuentros esta semana")).toBeInTheDocument()
    await userEvent.click(screen.getByRole("button", { name: /Agendar encuentro/ }))
    await userEvent.selectOptions(await screen.findByLabelText("Alumno"), "5")
    await userEvent.click(screen.getByRole("button", { name: "Agendar" }))

    expect(create).toHaveBeenCalled()
    expect(await screen.findByText("8 encuentros esta semana")).toBeInTheDocument()
  })

  it("marks a team meeting done and shows it «Realizada» without reloading", async () => {
    const scheduled = {
      id: 11,
      kind: "meeting",
      sheet_type: { id: 1, name: "Reunión de profesores" },
      scheduled_on: "2026-11-18",
      start_time: "14:00",
      student: null,
      group: { id: 9, name: "2°EMS-A" },
      attendees: [],
      external_attendees: null,
      status: "scheduled",
      sheet: null,
    } as never as Awaited<ReturnType<typeof spaceApi.fetchMeetings>>[number]
    vi.spyOn(spaceApi, "fetchMeetings")
      .mockResolvedValueOnce([scheduled])
      .mockResolvedValue([{ ...scheduled, status: "done" }])
    vi.spyOn(spaceApi, "fetchMeetingSheetTypes").mockResolvedValue([])
    vi.spyOn(spaceApi, "fetchStaff").mockResolvedValue([])
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])
    vi.spyOn(studentsApi, "fetchStudents").mockResolvedValue([])
    const complete = vi.spyOn(spaceApi, "completeMeeting").mockResolvedValue({ ...scheduled, status: "done" })
    renderPage()

    await userEvent.click(await screen.findByRole("button", { name: "Marcar realizada" }))

    expect(complete).toHaveBeenCalledWith(11)
    expect(await screen.findByText("Realizada")).toBeInTheDocument()
    expect(spaceApi.fetchOverview).toHaveBeenCalledTimes(2)
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

  it("lists Psicopedagogía's sheets without a students column and updates the row on confirm", async () => {
    vi.spyOn(spaceApi, "fetchSheets").mockResolvedValue({
      rows: [
        {
          id: 7,
          held_on: "2026-11-18",
          sheet_type: { id: 1, name: "Reunión de profesores" },
          group: { id: 9, name: "2°EMS-A" },
          status: "draft",
          students: [{ id: 5, name: "Lucía Pereyra" }],
          attendees: [],
          actions_count: 1,
        },
      ],
      meta: { current_page: 1, last_page: 1, total: 1 },
    })
    vi.spyOn(spaceApi, "fetchMeetingSheetTypes").mockResolvedValue([])
    vi.spyOn(spaceApi, "fetchStaff").mockResolvedValue([])
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])
    vi.spyOn(studentsApi, "fetchStudents").mockResolvedValue([])
    vi.spyOn(spaceApi, "fetchSheet").mockResolvedValue(draft)
    vi.spyOn(spaceApi, "confirmSheet").mockResolvedValue({ ...draft, status: "confirmed" })
    renderPage("/espacio-psicopedagogia?solapa=planillas")

    const row = (await screen.findByText("Sin confirmar")).closest("tr") as HTMLElement
    const headers = screen.getAllByRole("columnheader").map((header) => header.textContent)
    expect(headers).toEqual(["Fecha", "Tipo", "Alcance", "Estado", "Abrir"])
    expect(within(row).queryByText("Lucía Pereyra")).not.toBeInTheDocument()

    await userEvent.click(within(row).getByRole("button", { name: "Abrir" }))
    await userEvent.click(await screen.findByRole("button", { name: "Confirmar el reparto" }))

    expect(await within(row).findByText("Repartida")).toBeInTheDocument()
    expect(within(row).queryByText("Sin confirmar")).not.toBeInTheDocument()
    expect(spaceApi.fetchSheets).toHaveBeenCalledTimes(1)
  })

  it("clears the «Ver más» error once a retry works", async () => {
    currentRole = "director"
    const first = {
      rows: [{ id: 1, held_on: "2026-11-18", sheet_type: { id: 1, name: "Reunión de profesores" }, group: null }],
      meta: { current_page: 1, last_page: 2, total: 2 },
    }
    vi.spyOn(spaceApi, "fetchSheets")
      .mockResolvedValueOnce(first)
      .mockRejectedValueOnce(new Error("offline"))
      .mockResolvedValue({
        rows: [{ id: 2, held_on: "2025-03-10", sheet_type: { id: 2, name: "Entrevista con familia" }, group: null }],
        meta: { current_page: 2, last_page: 2, total: 2 },
      })
    renderPage("/espacio-psicopedagogia?solapa=planillas")

    await userEvent.click(await screen.findByRole("button", { name: "Ver más" }))
    expect(await screen.findByText("No pudimos cargar más planillas.")).toBeInTheDocument()
    await userEvent.click(screen.getByRole("button", { name: "Ver más" }))

    expect(await screen.findByText("Entrevista con familia")).toBeInTheDocument()
    expect(screen.queryByText("No pudimos cargar más planillas.")).not.toBeInTheDocument()
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

  it("says when someone else already confirmed it and shows it as it is now", async () => {
    const confirmed: MeetingSheet = { ...draft, status: "confirmed", confirmed_by_name: "Ana Pérez", confirmed_at: "2026-11-18T15:00:00Z" }
    vi.spyOn(spaceApi, "confirmSheet").mockRejectedValue(rejection("already_confirmed", "Esta planilla ya se confirmó."))
    vi.spyOn(spaceApi, "fetchSheet").mockResolvedValue(confirmed)
    const onChanged = vi.fn()
    render(<SheetDetail sheet={draft} options={null} onChanged={onChanged} />)

    await userEvent.click(screen.getByRole("button", { name: "Confirmar el reparto" }))

    expect(await screen.findByText("Esta planilla ya se confirmó.")).toBeInTheDocument()
    expect(onChanged).toHaveBeenCalledWith(confirmed)
  })

  it("tells to use «Editar antes» when a discussed student is no longer enrolled", async () => {
    const message = "Uno de los alumnos ya no está inscripto. Usá «Editar antes» para sacarlo del reparto."
    vi.spyOn(spaceApi, "confirmSheet").mockRejectedValue(rejection("student_removed", message))
    const fetchSheet = vi.spyOn(spaceApi, "fetchSheet")
    render(<SheetDetail sheet={draft} options={null} onChanged={vi.fn()} />)

    await userEvent.click(screen.getByRole("button", { name: "Confirmar el reparto" }))

    expect(await screen.findByText(message)).toBeInTheDocument()
    expect(fetchSheet).not.toHaveBeenCalled()
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

function rejection(reason: string, message: string): AxiosError {
  return new AxiosError("422", "ERR_BAD_REQUEST", undefined, undefined, {
    status: 422,
    statusText: "Unprocessable",
    headers: {},
    config: { headers: new AxiosHeaders() },
    data: { reason, message },
  })
}
