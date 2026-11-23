import { render, screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import type { CalendarItem, User } from "@/types"
import * as calendarApi from "./calendarApi"
import * as preferencesApi from "@/features/preferences/preferencesApi"
import { TodayInCalendar } from "./TodayInCalendar"

const teacher: User = { id: 4, name: "Mariana López", email: "m@e.com", roles: ["teacher"] }
const director: User = { id: 1, name: "Adriana Ruiz", email: "a@e.com", roles: ["director"] }
const psycho: User = { id: 2, name: "Laura Ferrando", email: "l@e.com", roles: ["psychopedagogue"] }

function item(overrides: Partial<CalendarItem>): CalendarItem {
  return {
    key: "class-1",
    type: "class",
    id: 1,
    date: "2026-12-15",
    time: null,
    end_time: null,
    title: "Matemática",
    detail: null,
    group: { id: 3, name: "5°A" },
    subject: { id: 2, name: "Matemática" },
    place: null,
    assessment_type: null,
    can_edit: true,
    ...overrides,
  }
}

function renderBlock(user: User) {
  return render(
    <MemoryRouter>
      <TodayInCalendar user={user} />
    </MemoryRouter>,
  )
}

describe("TodayInCalendar", () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["Date"] })
    vi.setSystemTime(new Date(2026, 11, 15, 7, 30))
  })
  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

  it("shows a class's topic next to its subject, small, muted and truncated", async () => {
    vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([
      item({ key: "class-1", id: 1, time: "08:30", place: "Aula 12", detail: "Comparar fracciones con tiras" }),
    ])

    renderBlock(teacher)

    const topic = await screen.findByTestId("class-topic")
    expect(topic).toHaveTextContent("Comparar fracciones con tiras")
    expect(topic).toHaveClass("truncate", "text-xs", "text-muted-foreground")
    const row = screen.getAllByRole("row")[1]
    expect(within(row).getAllByRole("cell")[1]).toHaveTextContent("MatemáticaComparar fracciones con tiras")
  })

  it("shows the teacher's classes of the day with time, subject, group and room, read from the calendar", async () => {
    const fetch = vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([
      item({ key: "class-3", id: 3, time: null, place: null, title: "Lengua" }),
      item({ key: "class-1", id: 1, time: "08:30", place: "Aula 12" }),
      item({ key: "class-2", id: 2, time: "10:15", place: "Aula 4", group: { id: 5, name: "5°B" } }),
    ])

    renderBlock(teacher)

    const rows = await screen.findAllByRole("row")
    expect(screen.getByRole("heading", { level: 2, name: "Hoy en tu calendario" })).toBeInTheDocument()
    expect(within(rows[0]).getAllByRole("columnheader").map((h) => h.textContent)).toEqual([
      "Hora",
      "Qué",
      "Grupo",
      "Salón",
      "Abrir",
    ])
    expect(within(rows[2]).getAllByRole("cell").map((c) => c.textContent)).toEqual([
      "08:30",
      "Matemática",
      "5°A",
      "Aula 12",
      "Abrir",
    ])
    // A class opens its own screen (15, ClickUp 86e3jujh0).
    expect(within(rows[2]).getByRole("link", { name: "Abrir" })).toHaveAttribute("href", "/clase/1")
    // A class without a time is listed without one.
    expect(within(rows[1]).getAllByRole("cell")[0]).toHaveTextContent("—")
    expect(rows).toHaveLength(4)

    // Today only, and never the tasks/follow-ups (they keep their own card).
    const [from, to, types] = fetch.mock.calls[0]
    expect(from).toBe("2026-12-15")
    expect(to).toBe("2026-12-15")
    expect(types).not.toContain("task")
    expect(types).toContain("class")
  })

  it("shows Direction its institutional events with where they happen", async () => {
    const fetch = vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([
      item({
        key: "institutional-9",
        type: "institutional",
        id: 9,
        time: "08:00",
        title: "Jornada de planificación institucional",
        group: null,
        subject: null,
        place: "Salón de actos",
        can_edit: true,
      }),
    ])

    renderBlock(director)

    const rows = await screen.findAllByRole("row")
    expect(within(rows[0]).getAllByRole("columnheader").map((h) => h.textContent)).toEqual([
      "Hora",
      "Qué",
      "Dónde",
      "Abrir",
    ])
    expect(within(rows[1]).getAllByRole("cell")[2]).toHaveTextContent("Salón de actos")
    expect(fetch.mock.calls[0][2]).toEqual(["meeting", "period_closing", "institutional"])
  })

  it("says so when there is nothing today", async () => {
    vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([])

    renderBlock(teacher)

    expect(await screen.findByText("Hoy no hay nada en tu calendario.")).toBeInTheDocument()
  })

  function threeAssessmentsToday() {
    return vi.spyOn(calendarApi, "fetchCalendar").mockImplementation(async (_from, _to, types) =>
      types?.includes("assessment")
        ? [1, 2, 3].map((id) =>
            item({ key: `assessment-${id}`, type: "assessment", id, title: `Escrito ${id}`, can_edit: false }),
          )
        : [],
    )
  }

  it("gives Psychopedagogy the total of today's assessments, opening the filtered calendar", async () => {
    threeAssessmentsToday()
    vi.spyOn(preferencesApi, "fetchUserPreferences").mockResolvedValue({ show_today_assessments_total: true })

    renderBlock(psycho)

    const link = await screen.findByRole("link", { name: "3 evaluaciones hoy" })
    expect(link).toHaveAttribute(
      "href",
      "/calendario?vista=lista&desde=2026-12-15&hasta=2026-12-15&tipos=assessment",
    )
    // Only the total on the Inicio: no titles, groups or grades.
    expect(screen.queryByText("Escrito 1")).not.toBeInTheDocument()
    // The rest of the day is empty: the empty text doesn't contradict the total.
    expect(screen.getByText("Fuera de eso, hoy no tenés nada más en tu calendario.")).toBeInTheDocument()
  })

  it("lets Psychopedagogy hide the total and show it again, saving the preference", async () => {
    vi.useRealTimers()
    const user = userEvent.setup()
    threeAssessmentsToday()
    vi.spyOn(preferencesApi, "fetchUserPreferences").mockResolvedValue({ show_today_assessments_total: true })
    const update = vi
      .spyOn(preferencesApi, "updateUserPreferences")
      .mockImplementation(async (input) => input)

    renderBlock(psycho)

    await user.click(await screen.findByRole("button", { name: "Ocultar" }))
    expect(update).toHaveBeenCalledWith({ show_today_assessments_total: false })
    expect(screen.queryByRole("link", { name: /evaluaciones hoy/ })).not.toBeInTheDocument()
    expect(screen.getByText("Hoy no hay nada en tu calendario.")).toBeInTheDocument()

    await user.click(screen.getByRole("button", { name: "Mostrar las evaluaciones de hoy" }))
    expect(update).toHaveBeenLastCalledWith({ show_today_assessments_total: true })
    expect(await screen.findByRole("link", { name: "3 evaluaciones hoy" })).toBeInTheDocument()
  })

  it("tells Psychopedagogy when hiding the total could not be saved", async () => {
    vi.useRealTimers()
    const user = userEvent.setup()
    threeAssessmentsToday()
    vi.spyOn(preferencesApi, "fetchUserPreferences").mockResolvedValue({ show_today_assessments_total: true })
    vi.spyOn(preferencesApi, "updateUserPreferences").mockRejectedValue(new Error("offline"))

    renderBlock(psycho)

    await user.click(await screen.findByRole("button", { name: "Ocultar" }))
    expect(await screen.findByRole("alert")).toHaveTextContent("No pudimos guardar el cambio.")
    expect(screen.getByRole("link", { name: "3 evaluaciones hoy" })).toBeInTheDocument()
  })

  it("starts with the total hidden when the person turned it off", async () => {
    threeAssessmentsToday()
    vi.spyOn(preferencesApi, "fetchUserPreferences").mockResolvedValue({ show_today_assessments_total: false })

    renderBlock(psycho)

    expect(await screen.findByRole("button", { name: "Mostrar las evaluaciones de hoy" })).toBeInTheDocument()
    expect(screen.queryByRole("link", { name: /evaluaciones hoy/ })).not.toBeInTheDocument()
  })

  it("no longer gives Direction the total (ClickUp 1dfd3mc4zpk)", async () => {
    const fetch = threeAssessmentsToday()
    const preferences = vi.spyOn(preferencesApi, "fetchUserPreferences")

    renderBlock(director)

    expect(await screen.findByText("Hoy no hay nada en tu calendario.")).toBeInTheDocument()
    expect(screen.queryByText(/evaluaciones? hoy/)).not.toBeInTheDocument()
    expect(fetch).toHaveBeenCalledTimes(1)
    expect(preferences).not.toHaveBeenCalled()
  })

  it("never adds the assessments total for a teacher", async () => {
    const fetch = vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([])

    renderBlock(teacher)

    await screen.findByText("Hoy no hay nada en tu calendario.")
    expect(fetch).toHaveBeenCalledTimes(1)
    expect(screen.queryByText(/evaluaciones? hoy/)).not.toBeInTheDocument()
  })
})
