import { render, screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import type { CalendarItem, User } from "@/types"
import * as calendarApi from "./calendarApi"
import { CalendarPage } from "./CalendarPage"

let currentUser: User = { id: 4, name: "Mariana López", email: "m@e.com", roles: ["teacher"] }

vi.mock("@/features/auth/AuthContext", () => ({
  useAuth: () => ({ user: currentUser }),
}))

function item(overrides: Partial<CalendarItem>): CalendarItem {
  return {
    key: "class-1",
    type: "class",
    id: 1,
    date: "2026-12-17",
    time: null,
    end_time: null,
    title: "Matemática",
    detail: "Repaso de ecuaciones",
    group: { id: 3, name: "2°EMS-A" },
    subject: { id: 2, name: "Matemática" },
    place: null,
    assessment_type: null,
    can_edit: true,
    ...overrides,
  }
}

const assessment = item({
  key: "assessment-7",
  type: "assessment",
  id: 7,
  date: "2026-12-10",
  title: "Escrito de integración",
  detail: "Matemática",
  assessment_type: "written",
  can_edit: false,
})

function renderPage(path = "/calendario") {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <CalendarPage />
    </MemoryRouter>,
  )
}

describe("CalendarPage", () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["Date"] })
    vi.setSystemTime(new Date(2026, 11, 15, 9, 0))
    currentUser = { id: 4, name: "Mariana López", email: "m@e.com", roles: ["teacher"] }
  })
  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

  it("shows the month with the dates in their days and the filter with all seven types", async () => {
    const fetch = vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([item({ time: "08:30" }), assessment])

    renderPage()

    expect(await screen.findByRole("heading", { level: 2, name: "Diciembre 2026" })).toBeInTheDocument()
    expect(fetch).toHaveBeenCalledWith("2026-11-30", "2027-01-03")
    const filter = screen.getByRole("group", { name: "Filtrar por tipo" })
    expect(within(filter).getAllByRole("button").map((b) => b.textContent)).toEqual([
      "Evaluación",
      "Clase",
      "Reunión",
      "Tarea",
      "Cierre de período",
      "Etapa de proyecto",
      "Institucional",
    ])
    expect(within(screen.getByTestId("day-2026-12-17")).getByText("Matemática")).toBeInTheDocument()
    expect(within(screen.getByTestId("day-2026-12-10")).getByText("Escrito de integración")).toBeInTheDocument()
  })

  it("hides a type from the filter", async () => {
    vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([item({}), assessment])
    const user = userEvent.setup()
    renderPage()
    await screen.findByText("Escrito de integración")

    await user.click(screen.getByRole("button", { name: "Evaluación" }))

    expect(screen.getByRole("button", { name: "Evaluación" })).toHaveAttribute("aria-pressed", "false")
    expect(screen.queryByText("Escrito de integración")).not.toBeInTheDocument()
    expect(within(screen.getByTestId("day-2026-12-17")).getByText("Matemática")).toBeInTheDocument()
  })

  it("switches to the week and the list views, moving with Anterior/Siguiente", async () => {
    const fetch = vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([item({ time: "08:30", place: "Aula 4" })])
    const user = userEvent.setup()
    renderPage()
    await screen.findByText("Diciembre 2026")

    await user.click(screen.getByRole("button", { name: "Semana" }))
    expect(await screen.findByRole("heading", { level: 2, name: "Semana del 14/12 al 20/12" })).toBeInTheDocument()
    expect(fetch).toHaveBeenLastCalledWith("2026-12-14", "2026-12-20")

    await user.click(screen.getByRole("button", { name: "Siguiente" }))
    expect(fetch).toHaveBeenLastCalledWith("2026-12-21", "2026-12-27")

    await user.click(screen.getByRole("button", { name: "Lista" }))
    const table = await screen.findByRole("table")
    expect(within(table).getByText("Jueves 17/12 · 08:30")).toBeInTheDocument()
    expect(within(table).getByText("Matemática · 2°EMS-A · Aula 4")).toBeInTheDocument()
    expect(within(table).getByText("Clases")).toBeInTheDocument()
  })

  it("lets the teacher set a class's time and room, pre-filled from their timetable", async () => {
    vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([item({})])
    const suggest = vi
      .spyOn(calendarApi, "fetchSuggestedSlot")
      .mockResolvedValue({ start_time: "08:30", room: "Aula 4" })
    const save = vi.spyOn(calendarApi, "updateClassSchedule").mockResolvedValue({ start_time: "08:45", room: "Aula 4" })
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole("button", { name: /^Clase · Matemática · 2°EMS-A/ }))

    expect(suggest).toHaveBeenCalledWith({ group_id: 3, subject_id: 2, date: "2026-12-17", class_session_id: 1 })
    const time = await screen.findByLabelText("Hora (opcional)")
    await waitFor(() => expect(time).toHaveValue("08:30"))
    expect(screen.getByLabelText("Salón (opcional)")).toHaveValue("Aula 4")
    expect(screen.getByText("Lo completamos desde tus horarios. Podés cambiarlo.")).toBeInTheDocument()

    await user.clear(time)
    await user.type(time, "08:45")
    await user.click(screen.getByRole("button", { name: "Guardar" }))

    await waitFor(() => expect(save).toHaveBeenCalledWith(1, { start_time: "08:45", room: "Aula 4" }))
  })

  it("shows the «Mis horarios» tab only to teachers", async () => {
    vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([])
    renderPage()
    expect(screen.getByRole("link", { name: "Mis horarios" })).toHaveAttribute("href", "/calendario/mis-horarios")

    currentUser = { id: 1, name: "Adriana Ruiz", email: "a@e.com", roles: ["director"] }
    renderPage("/calendario/mis-horarios")
    // Direction never gets the timetable, not even by URL: it sees the calendar.
    expect(screen.getAllByRole("link", { name: "Mis horarios" })).toHaveLength(1)
    expect(await screen.findAllByRole("group", { name: "Filtrar por tipo" })).toHaveLength(2)
  })

  it("lets Direction create an institutional event and remove it from the list", async () => {
    currentUser = { id: 1, name: "Adriana Ruiz", email: "a@e.com", roles: ["director"] }
    const event = item({
      key: "institutional-9",
      type: "institutional",
      id: 9,
      date: "2026-12-18",
      time: null,
      title: "Cierre del tercer período",
      detail: null,
      group: null,
      subject: null,
      can_edit: true,
    })
    vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([event])
    const create = vi.spyOn(calendarApi, "createInstitutionalEvent").mockResolvedValue({
      id: 10,
      type: "institutional",
      title: "Acto",
      date: "2026-12-15",
      time: "10:00",
      location: "Patio",
      description: null,
    })
    const remove = vi.spyOn(calendarApi, "deleteInstitutionalEvent").mockResolvedValue()
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole("button", { name: "Evento institucional" }))
    await user.type(screen.getByLabelText("Qué"), "Acto")
    await user.type(screen.getByLabelText("Hora (opcional)"), "10:00")
    await user.type(screen.getByLabelText("Dónde (opcional)"), "Patio")
    await user.click(screen.getByRole("button", { name: "Guardar" }))
    await waitFor(() =>
      expect(create).toHaveBeenCalledWith({
        title: "Acto",
        date: "2026-12-15",
        time: "10:00",
        location: "Patio",
        description: null,
      }),
    )

    await user.click(screen.getByRole("button", { name: "Lista" }))
    await user.click(await screen.findByRole("button", { name: "Quitar" }))
    const dialog = await screen.findByRole("dialog")
    await user.click(within(dialog).getByRole("button", { name: "Quitar" }))
    await waitFor(() => expect(remove).toHaveBeenCalledWith(9))
  })

  it("never offers teachers the institutional event button", async () => {
    vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([])
    renderPage()
    await screen.findByText("Diciembre 2026")
    expect(screen.queryByRole("button", { name: "Evento institucional" })).not.toBeInTheDocument()
  })

  it("starts the list on the current week and moves forward and back keeping the filter", async () => {
    const fetch = vi.spyOn(calendarApi, "fetchCalendar").mockImplementation(async (from) =>
      from <= "2026-11-27"
        ? [item({ key: "institutional-1", type: "institutional", id: 1, date: "2026-11-27", title: "Acto", group: null, can_edit: false })]
        : [
            item({}),
            assessment,
            item({ key: "institutional-2", type: "institutional", id: 2, date: "2026-12-30", title: "Fin de cursos", group: null, can_edit: false }),
          ],
    )
    const user = userEvent.setup()
    renderPage()
    await screen.findByText("Diciembre 2026")

    await user.click(screen.getByRole("button", { name: "Lista" }))
    expect(await screen.findByRole("heading", { level: 2, name: "Del 14/12 al 27/12" })).toBeInTheDocument()
    expect(fetch).toHaveBeenLastCalledWith("2026-12-14", "2026-12-27")

    // Hide classes: the filter stays while moving.
    await user.click(screen.getByRole("button", { name: "Clase" }))

    await user.click(screen.getByRole("button", { name: "Ver más" }))
    expect(await screen.findByRole("heading", { level: 2, name: "Del 14/12 al 10/01" })).toBeInTheDocument()
    expect(fetch).toHaveBeenLastCalledWith("2026-12-14", "2027-01-10")
    expect(await screen.findByText("Fin de cursos")).toBeInTheDocument()

    await user.click(screen.getByRole("button", { name: "Ver la semana anterior" }))
    await user.click(screen.getByRole("button", { name: "Ver la semana anterior" }))
    await user.click(screen.getByRole("button", { name: "Ver la semana anterior" }))
    expect(await screen.findByRole("heading", { level: 2, name: "Del 23/11 al 10/01" })).toBeInTheDocument()
    expect(await screen.findByText("Acto")).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Clase" })).toHaveAttribute("aria-pressed", "false")
    expect(screen.queryByText("Repaso de ecuaciones")).not.toBeInTheDocument()
  })

  it("opens today's assessments from the Inicio link, filtered", async () => {
    currentUser = { id: 1, name: "Adriana Ruiz", email: "a@e.com", roles: ["director"] }
    const fetch = vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([
      { ...assessment, key: "assessment-8", id: 8, date: "2026-12-15" },
      item({ key: "institutional-3", type: "institutional", id: 3, date: "2026-12-15", title: "Acto", can_edit: false }),
    ])

    renderPage("/calendario?vista=lista&desde=2026-12-15&hasta=2026-12-15&tipos=assessment")

    expect(await screen.findByRole("heading", { level: 2, name: "Del 15/12 al 15/12" })).toBeInTheDocument()
    expect(fetch).toHaveBeenCalledWith("2026-12-15", "2026-12-15")
    expect(await screen.findByText(/Escrito de integración/)).toBeInTheDocument()
    expect(screen.queryByText(/Acto/)).not.toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Institucional" })).toHaveAttribute("aria-pressed", "false")
    // From there, one click to the group's assessments and to the group.
    expect(screen.getByRole("link", { name: "Abrir" })).toHaveAttribute("href", "/clases/3/evaluaciones")
    expect(screen.getByRole("link", { name: "Grupo" })).toHaveAttribute("href", "/clases/3/seguimiento")
  })

  it("never lets an older response overwrite the range being shown", async () => {
    let resolveOld: (items: CalendarItem[]) => void = () => {}
    vi.spyOn(calendarApi, "fetchCalendar").mockImplementation((from) =>
      from === "2026-11-30"
        ? new Promise((resolve) => {
            resolveOld = resolve
          })
        : Promise.resolve([item({ key: "class-9", id: 9, date: "2027-01-14", title: "Geografía" })]),
    )
    const user = userEvent.setup()
    renderPage()

    await user.click(screen.getByRole("button", { name: "Siguiente" }))
    expect(await screen.findByText("Geografía")).toBeInTheDocument()

    resolveOld([item({ key: "class-8", id: 8, date: "2026-12-17", title: "Historia" })])
    await waitFor(() => expect(screen.queryByText("Historia")).not.toBeInTheDocument())
    expect(screen.getByText("Geografía")).toBeInTheDocument()
  })
})
