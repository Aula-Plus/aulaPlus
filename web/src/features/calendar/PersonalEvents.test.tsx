import { render, screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import type { AppNotification, CalendarItem, User } from "@/types"
import { NoticeText } from "@/features/notifications/NoticeText"
import * as calendarApi from "./calendarApi"
import * as personalEventsApi from "./personalEventsApi"
import { CalendarPage } from "./CalendarPage"

let currentUser: User = { id: 4, name: "Mariana López", email: "m@e.com", roles: ["teacher"] }

vi.mock("@/features/auth/AuthContext", () => ({
  useAuth: () => ({ user: currentUser }),
}))

vi.mock("@/features/preferences/preferencesApi", () => ({
  fetchUserPreferences: vi.fn().mockResolvedValue({ show_today_assessments_total: true }),
  updateUserPreferences: vi.fn(),
}))

function shared(overrides: Partial<CalendarItem> = {}, info: Partial<NonNullable<CalendarItem["shared"]>> = {}): CalendarItem {
  return {
    key: "event-9",
    type: "meeting",
    id: 9,
    date: "2026-12-17",
    time: "10:30",
    end_time: null,
    title: "Coordinar salida",
    detail: "Traer autorizaciones",
    group: null,
    subject: null,
    place: "Sala de profesores",
    assessment_type: null,
    can_edit: false,
    shared: {
      kind: "personal",
      owner: { id: 7, name: "Laura Ferrando" },
      is_owner: false,
      can_leave: true,
      participants: [
        { id: 7, name: "Laura Ferrando" },
        { id: 4, name: "Mariana López" },
      ],
      student: { id: 31, name: "Lucía Pereyra", can_view: false },
      ...info,
    },
    ...overrides,
  }
}

const schoolAssessment: CalendarItem = {
  key: "assessment-7",
  type: "assessment",
  id: 7,
  date: "2026-12-10",
  time: null,
  end_time: null,
  title: "Escrito de integración",
  detail: null,
  group: { id: 3, name: "2°EMS-A" },
  subject: null,
  place: null,
  assessment_type: "written",
  can_edit: false,
}

function renderPage(path = "/calendario?vista=lista&desde=2026-12-14&hasta=2026-12-20") {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <CalendarPage />
    </MemoryRouter>,
  )
}

describe("Personal and shared events (ClickUp 1dfd3mc56kp)", () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["Date"] })
    vi.setSystemTime(new Date(2026, 11, 15, 9, 0))
    currentUser = { id: 4, name: "Mariana López", email: "m@e.com", roles: ["teacher"] }
  })
  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

  it("shows an invitation with the whole event and the student's name, without the file link it cannot open", async () => {
    vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([shared()])
    const leave = vi.spyOn(personalEventsApi, "leaveCalendarEvent").mockResolvedValue()
    const user = userEvent.setup()
    renderPage()

    const table = await screen.findByRole("table")
    expect(within(table).getByText("Evento personal")).toBeInTheDocument()
    expect(within(table).getByText("Invitación de Laura Ferrando")).toBeInTheDocument()

    await user.click(within(table).getByRole("button", { name: "Ver" }))
    const dialog = await screen.findByRole("dialog")
    expect(within(dialog).getByText("Sala de profesores")).toBeInTheDocument()
    expect(within(dialog).getByText("Traer autorizaciones")).toBeInTheDocument()
    expect(within(dialog).getByText(/Laura Ferrando/)).toHaveTextContent("Laura Ferrando (lo creó)")
    expect(within(dialog).getByText("Mariana López")).toBeInTheDocument()
    expect(within(dialog).getByText("Lucía Pereyra")).toBeInTheDocument()
    expect(within(dialog).queryByRole("link", { name: "Ver ficha" })).not.toBeInTheDocument()
    expect(within(dialog).queryByRole("button", { name: "Editar" })).not.toBeInTheDocument()

    await user.click(within(dialog).getByRole("button", { name: "Quitar de mi calendario" }))
    await user.click(await screen.findByRole("button", { name: "Quitar" }))
    await waitFor(() => expect(leave).toHaveBeenCalledWith(9))
  })

  it("lets the creator open the student's file, edit and delete", async () => {
    vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([
      shared(
        { can_edit: true },
        {
          owner: { id: 4, name: "Mariana López" },
          is_owner: true,
          can_leave: false,
          student: { id: 31, name: "Lucía Pereyra", can_view: true },
        },
      ),
    ])
    vi.spyOn(personalEventsApi, "fetchPersonalEventOptions").mockResolvedValue({ users: [], students: [] })
    const remove = vi.spyOn(personalEventsApi, "deletePersonalEvent").mockResolvedValue()
    const user = userEvent.setup()
    renderPage()

    const table = await screen.findByRole("table")
    expect(within(table).getByText("Mi calendario")).toBeInTheDocument()
    await user.click(within(table).getByRole("button", { name: "Ver" }))
    const dialog = await screen.findByRole("dialog")
    expect(within(dialog).getByRole("link", { name: "Ver ficha" })).toHaveAttribute("href", "/alumnos/31/seguimiento")
    expect(within(dialog).queryByRole("button", { name: "Quitar de mi calendario" })).not.toBeInTheDocument()

    await user.click(within(dialog).getByRole("button", { name: "Eliminar" }))
    const confirm = await screen.findAllByRole("button", { name: "Eliminar" })
    await user.click(confirm[confirm.length - 1])
    await waitFor(() => expect(remove).toHaveBeenCalledWith(9))
  })

  it("creates an event inviting a colleague", async () => {
    vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([])
    vi.spyOn(personalEventsApi, "fetchPersonalEventOptions").mockResolvedValue({
      users: [{ id: 7, name: "Laura Ferrando", role: "psychopedagogue" }],
      students: [{ id: 31, name: "Lucía Pereyra" }],
    })
    const create = vi.spyOn(personalEventsApi, "createPersonalEvent").mockResolvedValue(shared())
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole("button", { name: "Evento personal" }))
    const dialog = await screen.findByRole("dialog")
    await user.type(within(dialog).getByLabelText("Qué"), "Coordinar salida")
    await user.clear(within(dialog).getByLabelText("Fecha"))
    await user.type(within(dialog).getByLabelText("Fecha"), "2026-12-17")
    await user.click(within(dialog).getByLabelText("Invitar (opcional)"))
    await user.click(await screen.findByRole("button", { name: "Laura Ferrando · Psicopedagogía" }))
    await user.click(within(dialog).getByRole("button", { name: "Guardar" }))

    await waitFor(() =>
      expect(create).toHaveBeenCalledWith({
        title: "Coordinar salida",
        date: "2026-12-17",
        time: null,
        location: null,
        description: null,
        invitee_ids: [7],
        student_id: null,
      }),
    )
  })

  it("lets Direction alternate between its own calendar and the institutional one", async () => {
    currentUser = { id: 1, name: "Adriana Ruiz", email: "a@e.com", roles: ["director"] }
    vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([shared(), schoolAssessment])
    const user = userEvent.setup()
    renderPage("/calendario?vista=lista&desde=2026-12-07&hasta=2026-12-20")

    const switcher = await screen.findByRole("group", { name: "Calendario" })
    expect(within(switcher).getByRole("button", { name: "Mi calendario" })).toHaveAttribute("aria-pressed", "true")
    expect(await screen.findByText(/Coordinar salida/)).toBeInTheDocument()
    expect(screen.queryByText(/Escrito de integración/)).not.toBeInTheDocument()

    await user.click(within(switcher).getByRole("button", { name: "Calendario institucional" }))
    expect(screen.getByText(/Escrito de integración/)).toBeInTheDocument()
    expect(screen.queryByText(/Coordinar salida/)).not.toBeInTheDocument()
  })

  it("offers Direction only the type chips the current view can show", async () => {
    currentUser = { id: 1, name: "Adriana Ruiz", email: "a@e.com", roles: ["director"] }
    vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([])
    const user = userEvent.setup()
    renderPage()

    const chips = await screen.findByRole("group", { name: "Filtrar por tipo" })
    const names = () => within(chips).getAllByRole("button").map((chip) => chip.textContent)
    expect(names()).toEqual(["Clase", "Reunión", "Tarea", "Cierre de período", "Etapa de proyecto", "Institucional"])

    await user.click(screen.getByRole("button", { name: "Calendario institucional" }))
    expect(names()).toEqual(["Evaluación", "Cierre de período", "Etapa de proyecto", "Institucional"])
  })

  it("keeps an invitee deactivated since visible, and in the list sent, when the creator edits", async () => {
    vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([
      shared(
        { can_edit: true },
        {
          owner: { id: 4, name: "Mariana López" },
          is_owner: true,
          can_leave: false,
          student: null,
          participants: [
            { id: 4, name: "Mariana López" },
            { id: 7, name: "Laura Ferrando" },
            { id: 8, name: "Pablo Suárez" },
          ],
        },
      ),
    ])
    // Pablo was deactivated: he is no longer among the invitable colleagues.
    vi.spyOn(personalEventsApi, "fetchPersonalEventOptions").mockResolvedValue({
      users: [{ id: 7, name: "Laura Ferrando", role: "psychopedagogue" }],
      students: [],
    })
    const update = vi.spyOn(personalEventsApi, "updatePersonalEvent").mockResolvedValue(shared())
    const user = userEvent.setup()
    renderPage()

    await user.click(within(await screen.findByRole("table")).getByRole("button", { name: "Ver" }))
    await user.click(within(await screen.findByRole("dialog")).getByRole("button", { name: "Editar" }))
    const dialog = await screen.findByRole("dialog")
    expect(await within(dialog).findByText("Pablo Suárez · ya no está activa en el colegio")).toBeInTheDocument()

    // Saving as is keeps him (the server accepts people already in it).
    await user.click(within(dialog).getByRole("button", { name: "Guardar" }))
    await waitFor(() => expect(update).toHaveBeenCalledWith(9, expect.objectContaining({ invitee_ids: [7, 8] })))
  })

  it("does not show the switch to other roles", async () => {
    vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([])
    renderPage()
    await screen.findByText("No hay fechas en este período.")
    expect(screen.queryByRole("group", { name: "Calendario" })).not.toBeInTheDocument()
  })

  it("words the invitation notice and links to that day of the calendar", () => {
    const notice: AppNotification = {
      id: "n1",
      created_at: null,
      data: {
        type: "calendar_event_invited",
        calendar_event_id: 9,
        event_type: "personal",
        title: "Coordinar salida",
        date: "2026-12-17",
        time: "10:30",
        invited_by_id: 7,
        invited_by_name: "Laura Ferrando",
      },
    }
    render(
      <MemoryRouter>
        <NoticeText notice={notice} />
      </MemoryRouter>,
    )
    expect(screen.getByText(/Laura Ferrando te sumó a «Coordinar salida»/)).toHaveTextContent("a las 10:30")
    expect(screen.getByRole("link", { name: "Ver en el calendario" })).toHaveAttribute(
      "href",
      "/calendario?vista=lista&desde=2026-12-17&hasta=2026-12-17",
    )
  })
})
