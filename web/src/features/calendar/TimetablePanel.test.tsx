import { render, screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { AxiosError } from "axios"
import { afterEach, describe, expect, it, vi } from "vitest"
import * as calendarApi from "./calendarApi"
import { TimetablePanel } from "./TimetablePanel"

const assignments = [
  { group: { id: 3, name: "2°EMS-A" }, subject: { id: 2, name: "Matemática" } },
  { group: { id: 5, name: "9°CB-A" }, subject: { id: 2, name: "Matemática" } },
]

describe("TimetablePanel", () => {
  afterEach(() => vi.restoreAllMocks())

  it("lists the teacher's slots and adds one for a group-subject they teach", async () => {
    const fetch = vi.spyOn(calendarApi, "fetchTimetable").mockResolvedValue({
      slots: [
        { id: 1, group: { id: 3, name: "2°EMS-A" }, subject: { id: 2, name: "Matemática" }, weekday: 1, start_time: "10:15", room: "Aula 4" },
      ],
      assignments,
    })
    const create = vi.spyOn(calendarApi, "createTimetableSlot").mockResolvedValue({
      id: 2,
      group: { id: 5, name: "9°CB-A" },
      subject: { id: 2, name: "Matemática" },
      weekday: 2,
      start_time: "08:30",
      room: "Aula 12",
    })
    const user = userEvent.setup()
    render(<TimetablePanel />)

    expect(await screen.findByText("Matemática · 2°EMS-A", { selector: "td" })).toBeInTheDocument()
    expect(screen.getByText("Lunes", { selector: "td" })).toBeInTheDocument()

    await user.selectOptions(screen.getByLabelText("Grupo y materia"), "5:2")
    await user.selectOptions(screen.getByLabelText("Día"), "2")
    await user.type(screen.getByLabelText("Hora"), "08:30")
    await user.type(screen.getByLabelText("Salón (opcional)"), "Aula 12")
    await user.click(screen.getByRole("button", { name: "Agregar" }))

    await waitFor(() =>
      expect(create).toHaveBeenCalledWith({ group_id: 5, subject_id: 2, weekday: 2, start_time: "08:30", room: "Aula 12" }),
    )
    expect(fetch).toHaveBeenCalledTimes(2)
  })

  it("removes a slot", async () => {
    vi.spyOn(calendarApi, "fetchTimetable").mockResolvedValue({
      slots: [
        { id: 1, group: { id: 3, name: "2°EMS-A" }, subject: { id: 2, name: "Matemática" }, weekday: 4, start_time: "08:30", room: null },
      ],
      assignments,
    })
    const remove = vi.spyOn(calendarApi, "deleteTimetableSlot").mockResolvedValue()
    const user = userEvent.setup()
    render(<TimetablePanel />)

    await user.click(await screen.findByRole("button", { name: "Quitar Jueves 08:30 Matemática 2°EMS-A" }))

    expect(remove).toHaveBeenCalledWith(1)
  })

  it("asks for the time before saving", async () => {
    vi.spyOn(calendarApi, "fetchTimetable").mockResolvedValue({ slots: [], assignments })
    const create = vi.spyOn(calendarApi, "createTimetableSlot")
    const user = userEvent.setup()
    render(<TimetablePanel />)

    await user.click(await screen.findByRole("button", { name: "Agregar" }))

    expect(screen.getByText("Elegí la hora.")).toBeInTheDocument()
    expect(create).not.toHaveBeenCalled()
    expect(screen.getByText("Todavía no cargaste horarios.")).toBeInTheDocument()
  })

  it.each([
    ["subject_id", "Ya no tenés asignado ese grupo y materia."],
    ["start_time", "Ya tenés ese horario cargado, o la hora no es válida."],
  ])("says why a slot was rejected (%s)", async (field, message) => {
    vi.spyOn(calendarApi, "fetchTimetable").mockResolvedValue({ slots: [], assignments })
    vi.spyOn(calendarApi, "createTimetableSlot").mockRejectedValue(
      new AxiosError("Unprocessable", "ERR_BAD_REQUEST", undefined, undefined, {
        status: 422,
        data: { errors: { [field]: ["x"] } },
      } as never),
    )
    const user = userEvent.setup()
    render(<TimetablePanel />)

    await user.selectOptions(await screen.findByLabelText("Grupo y materia"), "5:2")
    await user.type(screen.getByLabelText("Hora"), "08:30")
    await user.click(screen.getByRole("button", { name: "Agregar" }))

    expect(await screen.findByText(message)).toBeInTheDocument()
  })
})
