import { render, screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import type { CalendarItem, EventAudienceGroupOption, User } from "@/types"
import * as calendarApi from "./calendarApi"
import * as preferencesApi from "@/features/preferences/preferencesApi"
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

const audienceOptions = {
  levels: [
    { id: 1, key: "initial", label: "Inicial", grades: [{ id: 1, key: "initial_3", label: "Inicial 3 años" }] },
    {
      id: 2,
      key: "primary",
      label: "Primaria",
      grades: [
        { id: 6, key: "primary_3", label: "3° de Primaria" },
        { id: 9, key: "primary_6", label: "6° de Primaria" },
      ],
    },
    { id: 3, key: "lower_secondary", label: "Ciclo Básico", grades: [{ id: 11, key: "grade_8", label: "8°" }] },
    { id: 4, key: "upper_secondary", label: "Bachillerato", grades: [] },
  ],
  frameworks: [
    { id: 20, name: "Cambridge Primary", education_levels: ["primary"] },
    { id: 21, name: "IB MYP", education_levels: ["lower_secondary"] },
  ],
  groups: [] as EventAudienceGroupOption[],
}

const groupOptions: EventAudienceGroupOption[] = [
  { id: 31, name: "3° A", school_year: 2026, level_id: 2, grade_id: 6, framework_ids: [20] },
  { id: 32, name: "3° B", school_year: 2026, level_id: 2, grade_id: 6, framework_ids: [] },
  { id: 33, name: "6° Azul", school_year: 2026, level_id: 2, grade_id: 9, framework_ids: [] },
  // Another school year: never offered for a 2026 event.
  { id: 34, name: "3° C", school_year: 2025, level_id: 2, grade_id: 6, framework_ids: [] },
]

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
    expect(within(screen.getByTestId("day-2026-12-17")).getByText("Matemática · 2°EMS-A")).toBeInTheDocument()
    expect(within(screen.getByTestId("day-2026-12-10")).getByText("Escrito de integración")).toBeInTheDocument()
  })

  it("shows a class as «Matemática · 2°EMS-A» with its topic next to it, discreet, in the month and the list", async () => {
    const longTopic = "Comparar fracciones con tiras de papel y ubicarlas en la recta numérica entre cero y uno"
    vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([item({ time: "08:30", place: "Aula 4", detail: longTopic })])
    const user = userEvent.setup()
    renderPage()

    const day = await screen.findByTestId("day-2026-12-17")
    expect(within(day).getByText("Matemática · 2°EMS-A")).toBeInTheDocument()
    const chipTopic = within(day).getByTestId("class-topic")
    expect(chipTopic).toHaveTextContent(longTopic)
    expect(chipTopic).toHaveClass("font-normal")
    // The whole chip is cut with an ellipsis; its tooltip keeps the topic.
    expect(within(day).getByRole("button", { name: /Comparar fracciones/ })).toHaveClass("truncate")

    await user.click(screen.getByRole("button", { name: "Lista" }))
    const table = await screen.findByRole("table")
    expect(within(table).getByText("Matemática · 2°EMS-A · Aula 4")).toBeInTheDocument()
    const listTopic = within(table).getByTestId("class-topic")
    expect(listTopic).toHaveTextContent(longTopic)
    expect(listTopic).toHaveAttribute("title", longTopic)
    expect(listTopic).toHaveClass("truncate", "text-xs", "text-muted-foreground")
  })

  it("does not repeat the title as a topic for a class without subject, nor show a topic for other dates", async () => {
    vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([
      item({ title: "Repaso de ecuaciones", subject: null }),
      assessment,
    ])
    renderPage()

    await screen.findByText("Escrito de integración")
    expect(screen.queryByTestId("class-topic")).not.toBeInTheDocument()
  })

  it("hides a type from the filter", async () => {
    vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([item({}), assessment])
    const user = userEvent.setup()
    renderPage()
    await screen.findByText("Escrito de integración")

    await user.click(screen.getByRole("button", { name: "Evaluación" }))

    expect(screen.getByRole("button", { name: "Evaluación" })).toHaveAttribute("aria-pressed", "false")
    expect(screen.queryByText("Escrito de integración")).not.toBeInTheDocument()
    expect(within(screen.getByTestId("day-2026-12-17")).getByText("Matemática · 2°EMS-A")).toBeInTheDocument()
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
      audience: [],
    })
    vi.spyOn(calendarApi, "fetchAudienceOptions").mockResolvedValue(audienceOptions)
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
        audience: [],
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
    expect(await screen.findByText("Geografía · 2°EMS-A")).toBeInTheDocument()

    resolveOld([item({ key: "class-8", id: 8, date: "2026-12-17", title: "Historia" })])
    await waitFor(() => expect(screen.queryByText("Historia · 2°EMS-A")).not.toBeInTheDocument())
    expect(screen.getByText("Geografía · 2°EMS-A")).toBeInTheDocument()
  })

  it("lets Psychopedagogy switch the Inicio's assessments total from the filters (ClickUp 1dfd3mc4zpk)", async () => {
    vi.useRealTimers()
    const user = userEvent.setup()
    currentUser = { id: 2, name: "Laura Ferrando", email: "l@e.com", roles: ["psychopedagogue"] }
    vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([])
    vi.spyOn(preferencesApi, "fetchUserPreferences").mockResolvedValue({ show_today_assessments_total: true })
    const update = vi
      .spyOn(preferencesApi, "updateUserPreferences")
      .mockImplementation(async (input) => input)

    renderPage()

    const box = await screen.findByRole("checkbox", { name: "Mostrar el total de evaluaciones de hoy en el Inicio" })
    await waitFor(() => expect(box).toBeChecked())
    await user.click(box)

    expect(update).toHaveBeenCalledWith({ show_today_assessments_total: false })
    await waitFor(() => expect(box).not.toBeChecked())
  })

  it("tells Direction what it can do, without the switch (ClickUp 1dfd3mc4zpk)", async () => {
    currentUser = { id: 1, name: "Adriana Ruiz", email: "a@e.com", roles: ["director"] }
    vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([])
    const preferences = vi.spyOn(preferencesApi, "fetchUserPreferences")

    const user = userEvent.setup()
    renderPage()

    // «Mi calendario» (its default) has no assessment load: the texts say so.
    expect(await screen.findByText("Tu agenda, los eventos que compartís y los eventos del colegio.")).toBeInTheDocument()
    expect(
      screen.getByText(
        "Ves tus eventos, los que te compartieron y los eventos del colegio. La carga de evaluaciones de todo el colegio está en «Calendario institucional».",
      ),
    ).toBeInTheDocument()

    await user.click(screen.getByRole("button", { name: "Calendario institucional" }))
    expect(
      screen.getByText("Los eventos institucionales y la carga de evaluaciones de todo el colegio."),
    ).toBeInTheDocument()
    expect(
      screen.getByText(
        "Ves la carga de evaluaciones de todo el colegio, para anticipar semanas cargadas. Un evento institucional tiene fecha, hora opcional, qué es y a quién le corresponde; lo ven quienes tienen esos grupos, está en el calendario completo del colegio y se quita desde la vista Lista.",
      ),
    ).toBeInTheDocument()
    expect(screen.queryByText(/nunca para mirar un caso/)).not.toBeInTheDocument()
    expect(screen.queryByRole("checkbox", { name: /total de evaluaciones/ })).not.toBeInTheDocument()
    expect(preferences).not.toHaveBeenCalled()
  })
  it("lets Direction choose who an institutional event concerns (ClickUp 1dfd3mc55zp)", async () => {
    currentUser = { id: 1, name: "Adriana Ruiz", email: "a@e.com", roles: ["director"] }
    vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([])
    vi.spyOn(calendarApi, "fetchAudienceOptions").mockResolvedValue(audienceOptions)
    const create = vi.spyOn(calendarApi, "createInstitutionalEvent").mockResolvedValue({
      id: 10,
      type: "institutional",
      title: "Feria",
      date: "2026-12-15",
      time: null,
      location: null,
      description: null,
      audience: [],
    })
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole("button", { name: "Evento institucional" }))
    await user.type(screen.getByLabelText("Qué"), "Feria")
    const whole = screen.getByRole("checkbox", { name: "Todo el colegio" })
    expect(whole).toBeChecked()
    await user.click(whole)

    // Nothing chosen: the form asks for it.
    await user.click(await screen.findByRole("checkbox", { name: "Secundaria (Ciclo Básico y Bachillerato)" }))
    expect(screen.getByRole("checkbox", { name: "Ciclo Básico" })).toBeChecked()
    expect(screen.getByRole("checkbox", { name: "Bachillerato" })).toBeChecked()
    await user.click(screen.getByRole("checkbox", { name: "Secundaria (Ciclo Básico y Bachillerato)" }))
    await user.click(screen.getByRole("button", { name: "Guardar" }))
    expect(await screen.findByText("Elegí a quién le corresponde, o marcá «Todo el colegio».")).toBeInTheDocument()
    expect(create).not.toHaveBeenCalled()

    await user.click(screen.getByRole("checkbox", { name: "Primaria" }))
    // A generation, and the Cambridge groups of another one (only the programs of its level).
    await user.selectOptions(screen.getByRole("combobox", { name: "Generación" }), "6° de Primaria")
    await user.click(screen.getByRole("button", { name: "Agregar generación" }))
    await user.selectOptions(screen.getByRole("combobox", { name: "Generación" }), "3° de Primaria")
    const groups = screen.getByRole("combobox", { name: "Grupos de la generación" })
    expect(within(groups).getAllByRole("option").map((option) => option.textContent)).toEqual([
      "Todos sus grupos",
      "Solo los grupos de Cambridge Primary",
    ])
    await user.selectOptions(groups, "Solo los grupos de Cambridge Primary")
    await user.click(screen.getByRole("button", { name: "Agregar generación" }))
    expect(screen.getByText("Cambridge Primary en 3° de Primaria")).toBeInTheDocument()

    // Removing one keeps the rest.
    await user.click(screen.getByRole("button", { name: "Quitar 6° de Primaria" }))
    await user.click(screen.getByRole("button", { name: "Guardar" }))

    await waitFor(() =>
      expect(create).toHaveBeenCalledWith(
        expect.objectContaining({ title: "Feria", audience: [{ level_id: 2 }, { grade_id: 6, framework_id: 20 }] }),
      ),
    )
  })

  it("lets a teacher see the whole school calendar, with who each event concerns", async () => {
    const mine = item({
      key: "institutional-1",
      type: "institutional",
      id: 1,
      date: "2026-12-16",
      title: "Acto",
      group: null,
      subject: null,
      can_edit: false,
      audience: [],
      for_me: true,
    })
    const other = item({
      key: "institutional-2",
      type: "institutional",
      id: 2,
      date: "2026-12-17",
      title: "Salida de Bachillerato",
      group: null,
      subject: null,
      can_edit: false,
      audience: [
        {
          level: { id: 3, key: "lower_secondary", label: "Ciclo Básico" },
          grade: null,
          framework: null,
          group: null,
          groups: [],
        },
        {
          level: { id: 4, key: "upper_secondary", label: "Bachillerato" },
          grade: null,
          framework: null,
          group: null,
          groups: [],
        },
        {
          level: null,
          grade: { id: 6, key: "primary_3", label: "3° de Primaria" },
          framework: { id: 20, name: "Cambridge Primary" },
          group: null,
          groups: [{ id: 31, name: "3° A" }],
        },
        { level: null, grade: null, framework: null, group: { id: 33, name: "6° Azul" }, groups: [{ id: 33, name: "6° Azul" }] },
      ],
      for_me: false,
    })
    const fetch = vi
      .spyOn(calendarApi, "fetchCalendar")
      .mockImplementation(async (_from, _to, _types, scope) => (scope === "school" ? [mine, other] : [mine]))
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole("button", { name: "Lista" }))
    expect(await screen.findByText("Para: Todo el colegio")).toBeInTheDocument()
    expect(screen.queryByText("Salida de Bachillerato")).not.toBeInTheDocument()

    await user.click(screen.getByRole("checkbox", { name: "Ver todo el calendario del colegio" }))

    expect(await screen.findByText("Salida de Bachillerato")).toBeInTheDocument()
    // Groups by the name the school gave them (Eitán, ClickUp 1dfd3mc55zp).
    expect(screen.getByText("Para: Secundaria · Cambridge Primary en 3° de Primaria: 3° A · 6° Azul")).toBeInTheDocument()
    expect(fetch).toHaveBeenLastCalledWith("2026-12-14", "2026-12-27", undefined, "school")
  })

  it("lists the groups by their own name when choosing who an event concerns (ClickUp 1dfd3mc55zp)", async () => {
    currentUser = { id: 1, name: "Adriana Ruiz", email: "a@e.com", roles: ["director"] }
    vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([])
    vi.spyOn(calendarApi, "fetchAudienceOptions").mockResolvedValue({ ...audienceOptions, groups: groupOptions })
    const create = vi.spyOn(calendarApi, "createInstitutionalEvent").mockResolvedValue({
      id: 10,
      type: "institutional",
      title: "Salida",
      date: "2026-12-15",
      time: null,
      location: null,
      description: null,
      audience: [],
    })
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole("button", { name: "Evento institucional" }))
    await user.type(screen.getByLabelText("Qué"), "Salida")
    await user.click(screen.getByRole("checkbox", { name: "Todo el colegio" }))

    // The groups of the event's year, under their generation.
    const group = await screen.findByRole("combobox", { name: "Grupo" })
    expect(within(group).getAllByRole("option").map((option) => option.textContent)).toEqual([
      "Elegí un grupo",
      "3° A",
      "3° B",
      "6° Azul",
    ])
    expect(within(group).getByRole("group", { name: "3° de Primaria" })).toBeInTheDocument()
    await user.selectOptions(group, "3° B")
    await user.click(screen.getByRole("button", { name: "Agregar grupo" }))
    expect(within(screen.getByRole("list", { name: "Grupos elegidos" })).getByText("3° B")).toBeInTheDocument()

    // A generation and a program say which groups they reach.
    await user.selectOptions(screen.getByRole("combobox", { name: "Generación" }), "3° de Primaria")
    const ofGeneration = screen.getByRole("combobox", { name: "Grupos de la generación" })
    expect(within(ofGeneration).getAllByRole("option").map((option) => option.textContent)).toEqual([
      "Todos sus grupos (3° A, 3° B)",
      "Solo los grupos de Cambridge Primary (3° A)",
    ])
    await user.selectOptions(ofGeneration, "Solo los grupos de Cambridge Primary (3° A)")
    await user.click(screen.getByRole("button", { name: "Agregar generación" }))
    expect(screen.getByText("Cambridge Primary en 3° de Primaria: 3° A")).toBeInTheDocument()

    await user.click(screen.getByRole("button", { name: "Guardar" }))
    await waitFor(() =>
      expect(create).toHaveBeenCalledWith(
        expect.objectContaining({ audience: [{ group_id: 32 }, { grade_id: 6, framework_id: 20 }] }),
      ),
    )
  })

  it("keeps the stored names on edit when a program or group is no longer offered", async () => {
    currentUser = { id: 1, name: "Adriana Ruiz", email: "a@e.com", roles: ["director"] }
    const event = item({
      key: "institutional-5",
      type: "institutional",
      id: 5,
      date: "2026-12-16",
      title: "Feria",
      group: null,
      subject: null,
      can_edit: true,
      audience: [
        {
          level: null,
          grade: { id: 6, key: "primary_3", label: "3° de Primaria" },
          framework: { id: 9, name: "Cambridge Primary" },
          group: null,
          groups: [],
        },
        { level: null, grade: null, framework: null, group: { id: 77, name: "3° Rojo" }, groups: [] },
      ],
    })
    vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([event])
    vi.spyOn(calendarApi, "fetchAudienceOptions").mockResolvedValue({ ...audienceOptions, frameworks: [] })
    const update = vi.spyOn(calendarApi, "updateInstitutionalEvent").mockResolvedValue({
      id: 5,
      type: "institutional",
      title: "Feria",
      date: "2026-12-16",
      time: null,
      location: null,
      description: null,
      audience: [],
    })
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole("button", { name: "Lista" }))
    await user.click(await screen.findByRole("button", { name: "Editar" }))

    expect(await screen.findByText("Cambridge Primary en 3° de Primaria")).toBeInTheDocument()
    expect(within(screen.getByRole("list", { name: "Grupos elegidos" })).getByText("3° Rojo")).toBeInTheDocument()
    await user.click(screen.getByRole("button", { name: "Guardar" }))
    await waitFor(() =>
      expect(update).toHaveBeenCalledWith(
        5,
        expect.objectContaining({ audience: [{ grade_id: 6, framework_id: 9 }, { group_id: 77 }] }),
      ),
    )
  })

  it("does not offer the whole-school switch to Psychopedagogy and Direction, who already see it all", async () => {
    vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([])
    vi.spyOn(preferencesApi, "fetchUserPreferences").mockResolvedValue({ show_today_assessments_total: true })
    for (const roles of [["director"], ["psychopedagogue"]] as const) {
      currentUser = { id: 1, name: "Adriana Ruiz", email: "a@e.com", roles: [...roles] }
      const { unmount } = renderPage()
      await screen.findByText("Diciembre 2026")
      expect(screen.queryByRole("checkbox", { name: "Ver todo el calendario del colegio" })).not.toBeInTheDocument()
      unmount()
    }
  })
})
