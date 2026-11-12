import { render, screen, within } from "@testing-library/react"
import { MemoryRouter } from "react-router-dom"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import type { CalendarItem, User } from "@/types"
import * as calendarApi from "./calendarApi"
import { TodayInCalendar } from "./TodayInCalendar"

const teacher: User = { id: 4, name: "Mariana López", email: "m@e.com", roles: ["teacher"] }
const director: User = { id: 1, name: "Adriana Ruiz", email: "a@e.com", roles: ["director"] }

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
      "",
    ])
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

  it("gives Direction and Psychopedagogy the total of today's assessments, opening the filtered calendar", async () => {
    vi.spyOn(calendarApi, "fetchCalendar").mockImplementation(async (_from, _to, types) =>
      types?.includes("assessment")
        ? [1, 2, 3].map((id) =>
            item({ key: `assessment-${id}`, type: "assessment", id, title: `Escrito ${id}`, can_edit: false }),
          )
        : [],
    )

    renderBlock(director)

    const link = await screen.findByRole("link", { name: "3 evaluaciones hoy" })
    expect(link).toHaveAttribute(
      "href",
      "/calendario?vista=lista&desde=2026-12-15&hasta=2026-12-15&tipos=assessment",
    )
    // Only the total on the Inicio: no titles, groups or grades.
    expect(screen.queryByText("Escrito 1")).not.toBeInTheDocument()
  })

  it("never adds the assessments total for a teacher", async () => {
    const fetch = vi.spyOn(calendarApi, "fetchCalendar").mockResolvedValue([])

    renderBlock(teacher)

    await screen.findByText("Hoy no hay nada en tu calendario.")
    expect(fetch).toHaveBeenCalledTimes(1)
    expect(screen.queryByText(/evaluaciones? hoy/)).not.toBeInTheDocument()
  })
})
