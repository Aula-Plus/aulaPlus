import { render, screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"

import { ClassPage } from "./ClassPage"
import * as classesApi from "./classesApi"
import type { ClassDetail } from "./classesApi"

function makeClass(overrides: Partial<ClassDetail> = {}): ClassDetail {
  return {
    id: 77,
    title: "Comparar fracciones con tiras",
    date: "2026-11-18",
    start_time: "08:00",
    room: "Aula 4",
    duration_minutes: 45,
    status: "planned",
    group: { id: 1, name: "2°EMS-A" },
    subject: { id: 2, name: "Matemática" },
    unit: { id: 11, name: "Fracciones", position: 3, annual_plan_id: 7 },
    objective: "Que comparen fracciones con material concreto",
    description: null,
    sections: [
      { key: "opening", content: "Doblar tiras de papel.", duration_minutes: 10 },
      { key: "closing", content: "Cada pareja cuenta qué fracción es mayor.", duration_minutes: 10, edited: true },
    ],
    objectives: [
      { id: 21, description: "Que comparen fracciones", coverage: null },
      { id: 22, description: "Que ordenen fracciones", coverage: null },
    ],
    closing_result: null,
    closed_at: null,
    can_close: true,
    closable_now: true,
    proposal: { id: 40, rationale: "Propuse material concreto.", data_used: [], planner: { read: false, reason: "planner_not_available" } },
    ...overrides,
  }
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={["/clase/77"]}>
      <Routes>
        <Route path="/clase/:id" element={<ClassPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

afterEach(() => {
  vi.restoreAllMocks()
})

describe("ClassPage", () => {
  it("shows the confirmed sections and closes the class in one tap with «Todo»", async () => {
    vi.spyOn(classesApi, "fetchClass").mockResolvedValue(makeClass())
    const close = vi
      .spyOn(classesApi, "closeClass")
      .mockResolvedValue(makeClass({ closing_result: "full", status: "delivered", closed_at: "2026-11-18T15:00:00Z" }))
    const user = userEvent.setup()
    renderPage()

    expect(await screen.findByText("Doblar tiras de papel.")).toBeInTheDocument()
    expect(screen.getByText("Editada por el docente")).toBeInTheDocument()
    expect(screen.getByText("¿Se dio lo que planificaste?")).toBeInTheDocument()

    await user.click(screen.getByRole("button", { name: "Todo" }))

    expect(close).toHaveBeenCalledWith(77, "full", { given: [], started: [] })
    expect(await screen.findByText(/Cerrada con «Todo»/)).toBeInTheDocument()
  })

  it("is still one tap: per-objective options only appear with «Una parte»", async () => {
    vi.spyOn(classesApi, "fetchClass").mockResolvedValue(makeClass())
    renderPage()

    expect(await screen.findByRole("button", { name: "Todo" })).toBeInTheDocument()
    expect(screen.queryByRole("radio")).not.toBeInTheDocument()
    expect(screen.queryByText("¿Qué pasó con cada objetivo?")).not.toBeInTheDocument()
  })

  it("with «Una parte» records each objective «Se dio» or «Se empezó»; the untouched one is sent nowhere", async () => {
    vi.spyOn(classesApi, "fetchClass").mockResolvedValue(
      makeClass({
        objectives: [
          { id: 21, description: "Comparar fracciones", coverage: null },
          { id: 22, description: "Ordenar decimales", coverage: null },
          { id: 23, description: "Problemas con dinero", coverage: null },
        ],
      }),
    )
    const close = vi.spyOn(classesApi, "closeClass").mockResolvedValue(makeClass({ closing_result: "partial" }))
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole("button", { name: "Una parte" }))
    expect(screen.getByText("¿Qué pasó con cada objetivo?")).toBeInTheDocument()

    const compare = screen.getByRole("radiogroup", { name: "Comparar fracciones" })
    const order = screen.getByRole("radiogroup", { name: "Ordenar decimales" })
    const money = screen.getByRole("radiogroup", { name: "Problemas con dinero" })
    // Nothing marked yet: every objective starts as «No se tocó».
    expect(within(money).getByRole("radio", { name: "No se tocó" })).toBeChecked()

    await user.click(within(compare).getByRole("radio", { name: "Se dio" }))
    await user.click(within(order).getByRole("radio", { name: "Se empezó" }))
    await user.click(screen.getByRole("button", { name: "Guardar «Una parte»" }))

    await waitFor(() => expect(close).toHaveBeenCalledWith(77, "partial", { given: [21], started: [22] }))
  })

  it("correcting an earlier «Una parte» starts from what it recorded", async () => {
    vi.spyOn(classesApi, "fetchClass").mockResolvedValue(
      makeClass({
        closing_result: "partial",
        closed_at: "2026-11-18T15:00:00Z",
        status: "delivered",
        objectives: [
          { id: 21, description: "Que comparen fracciones", coverage: "full" },
          { id: 22, description: "Que ordenen fracciones", coverage: null },
        ],
      }),
    )
    const close = vi.spyOn(classesApi, "closeClass").mockResolvedValue(makeClass({ closing_result: "none" }))
    const user = userEvent.setup()
    renderPage()

    expect(await screen.findByText("Se dio")).toBeInTheDocument()
    await user.click(screen.getByRole("button", { name: "Cambiar el cierre" }))
    await user.click(screen.getByRole("button", { name: "Una parte" }))
    expect(within(screen.getByRole("radiogroup", { name: "Que comparen fracciones" })).getByRole("radio", { name: "Se dio" })).toBeChecked()
    await user.click(screen.getByRole("button", { name: "Cancelar" }))

    await user.click(screen.getByRole("button", { name: "Nada" }))
    await waitFor(() => expect(close).toHaveBeenCalledWith(77, "none", { given: [], started: [] }))
  })

  it("is not closable before the class day, nor by someone who only reads it", async () => {
    vi.spyOn(classesApi, "fetchClass").mockResolvedValueOnce(makeClass({ closable_now: false }))
    const { unmount } = renderPage()
    expect(await screen.findByText("Se habilita el día de la clase.")).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Todo" })).not.toBeInTheDocument()
    unmount()

    vi.spyOn(classesApi, "fetchClass").mockResolvedValueOnce(makeClass({ can_close: false }))
    renderPage()
    expect(await screen.findByText(/La cierra el docente de la materia/)).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Todo" })).not.toBeInTheDocument()
  })
})
