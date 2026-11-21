import { render, screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"

import { ClassProposalPage } from "./ClassProposalPage"
import * as classesApi from "./classesApi"
import type { ClassProposal } from "./classesApi"

function makeProposal(overrides: Partial<ClassProposal> = {}): ClassProposal {
  return {
    id: 40,
    group_id: 1,
    journey_id: "11111111-1111-4111-8111-111111111111",
    type: "class_session",
    status: "completed",
    input_parameters: {
      unit_id: 11,
      unit_objective_ids: [21],
      focus: "Fracciones",
      objectives: "Que comparen fracciones con material concreto",
      date: "2026-11-20",
      duration_minutes: 45,
    },
    raw_response: {
      title: "Comparar fracciones con tiras",
      duration_minutes: 45,
      sections: {
        opening: { content: "Doblar tiras de papel.", duration_minutes: 10 },
        central_activity: { content: "En parejas comparan fracciones con las tiras.", duration_minutes: 25 },
        closing: { content: "Puesta en común.", duration_minutes: 10 },
        materials: { content: "Tiras de papel, tijeras." },
        assessment: { content: "Pregunta de salida." },
      },
      rationale: "Propuse material concreto porque lo pidió el docente. <img src=x onerror=alert(1)>",
      data_used: [{ data: "Promedio 5,8", source: "Escrito del 15/06 · Unidad 2", reason: "Le costó comparar" }],
      planner: { read: false, reason: "planner_not_available" },
      regenerated_sections: [],
    },
    error_message: null,
    applied_to_id: null,
    class_request: {
      group: { id: 1, name: "2°EMS-A" },
      subject: { id: 2, name: "Matemática" },
      unit: { id: 11, name: "Fracciones", position: 3, annual_plan_id: 7 },
      unit_objectives: [{ id: 21, description: "Que comparen fracciones con material concreto" }],
    },
    can_decide: true,
    ...overrides,
  }
}

function renderPage(id = 40) {
  return render(
    <MemoryRouter initialEntries={[`/propuesta-de-clase/${id}`]}>
      <Routes>
        <Route path="/propuesta-de-clase/:id" element={<ClassProposalPage />} />
        <Route path="/clase/:id" element={<p>Pantalla de la clase</p>} />
      </Routes>
    </MemoryRouter>,
  )
}

afterEach(() => {
  vi.restoreAllMocks()
  vi.useRealTimers()
})

describe("ClassProposalPage", () => {
  it("shows the class by sections and the «¿por qué?» with the data used and the planner note, as plain text", async () => {
    vi.spyOn(classesApi, "fetchProposal").mockResolvedValue(makeProposal())

    const { container } = renderPage()

    expect(await screen.findByRole("heading", { name: "Comparar fracciones con tiras" })).toBeInTheDocument()
    expect(screen.getByText(/Matemática · 2°EMS-A · Unidad 3 — Fracciones/)).toBeInTheDocument()
    expect(screen.getByText("¿Por qué la IA generó esta propuesta?")).toBeInTheDocument()
    expect(screen.getByText("Promedio 5,8")).toBeInTheDocument()
    expect(screen.getByText(/Escrito del 15\/06/)).toBeInTheDocument()
    expect(screen.getByText(/no leyó el planificador de la unidad/)).toBeInTheDocument()
    for (const label of ["Apertura", "Actividad central", "Cierre", "Materiales", "Evaluación de la clase"]) {
      expect(screen.getByText(label)).toBeInTheDocument()
    }
    // AI text is never rendered as HTML.
    expect(container.querySelector("img")).toBeNull()
    expect(screen.getByText(/onerror=alert/)).toBeInTheDocument()
  })

  it("confirms only once every section is decided, sending approved, edited and rejected sections", async () => {
    vi.spyOn(classesApi, "fetchProposal").mockResolvedValue(makeProposal())
    const confirm = vi
      .spyOn(classesApi, "confirmClassProposal")
      .mockResolvedValue(makeProposal({ status: "applied", applied_to_id: 77 }))
    const user = userEvent.setup()
    renderPage()

    const confirmButton = await screen.findByRole("button", { name: /Confirmar clase/ })
    expect(confirmButton).toBeDisabled()

    await user.click(screen.getByRole("button", { name: "Aprobar Apertura" }))
    await user.click(screen.getByRole("button", { name: "Aprobar Actividad central" }))
    await user.click(screen.getByRole("button", { name: "Editar Cierre" }))
    const editor = screen.getByLabelText("Tu versión de «Cierre»")
    await user.clear(editor)
    await user.type(editor, "Cada pareja cuenta qué fracción es mayor.")
    await user.click(screen.getByRole("button", { name: "Guardar mi versión" }))
    await user.click(screen.getByRole("button", { name: "Rechazar Materiales" }))
    expect(confirmButton).toBeDisabled()
    await user.click(screen.getByRole("button", { name: "Aprobar Evaluación de la clase" }))

    expect(confirmButton).toBeEnabled()
    await user.click(confirmButton)

    await screen.findByText("Pantalla de la clase")
    expect(confirm).toHaveBeenCalledWith(40, {
      opening: { decision: "approved" },
      central_activity: { decision: "approved" },
      closing: { decision: "edited", content: "Cada pareja cuenta qué fracción es mayor.", duration_minutes: 10 },
      materials: { decision: "rejected" },
      assessment: { decision: "approved" },
    })
  })

  it("regenerates the marked sections in one single call, pointing at this draft", async () => {
    vi.spyOn(classesApi, "fetchProposal").mockResolvedValue(makeProposal())
    const request = vi.spyOn(classesApi, "requestClassProposal").mockResolvedValue(makeProposal({ id: 41, status: "pending" }))
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole("button", { name: "Aprobar Apertura" }))
    await user.click(screen.getByRole("button", { name: "Regenerar Cierre" }))
    await user.type(screen.getByLabelText("¿Qué querés que cambie? (opcional)"), "Más corto")
    await user.click(screen.getByRole("button", { name: "Regenerar la sección" }))

    await waitFor(() => expect(request).toHaveBeenCalledTimes(1))
    expect(request).toHaveBeenCalledWith(
      1,
      expect.objectContaining({
        unit_id: 11,
        focus: "Fracciones",
        reference_proposal_id: 40,
        regenerate_sections: ["closing"],
        change_requests: { closing: "Más corto" },
      }),
      "11111111-1111-4111-8111-111111111111",
    )
  })

  it("lets the teacher decide on the regenerated draft (no leftover busy state)", async () => {
    vi.spyOn(classesApi, "fetchProposal").mockImplementation(async (id: number) =>
      id === 40 ? makeProposal() : makeProposal({ id: 41 }),
    )
    vi.spyOn(classesApi, "requestClassProposal").mockResolvedValue(makeProposal({ id: 41, status: "pending" }))
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole("button", { name: "Regenerar Cierre" }))
    await user.click(screen.getByRole("button", { name: "Regenerar la sección" }))

    await waitFor(() => expect(classesApi.fetchProposal).toHaveBeenCalledWith(41))
    await waitFor(() => expect(screen.getByRole("button", { name: "Aprobar Cierre" })).toBeEnabled())
  })

  it("keeps polling after a failed poll instead of showing an error", async () => {
    vi.spyOn(classesApi, "fetchProposal")
      .mockResolvedValueOnce(makeProposal({ status: "pending", raw_response: null }))
      .mockRejectedValueOnce(new Error("502"))
      .mockResolvedValue(makeProposal())
    renderPage()

    expect(
      await screen.findByRole("heading", { name: "Comparar fracciones con tiras" }, { timeout: 7000 }),
    ).toBeInTheDocument()
    expect(screen.queryByText("No pudimos cargar la propuesta.")).not.toBeInTheDocument()
  }, 10000)

  it("is read-only for Psicopedagogía and Dirección", async () => {
    vi.spyOn(classesApi, "fetchProposal").mockResolvedValue(makeProposal({ can_decide: false }))
    renderPage()

    expect(await screen.findByText(/la confirma el docente/)).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /Aprobar/ })).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /Confirmar clase/ })).not.toBeInTheDocument()
  })

  it("polls while the proposal is being generated", async () => {
    const fetch = vi
      .spyOn(classesApi, "fetchProposal")
      .mockResolvedValueOnce(makeProposal({ status: "pending", raw_response: null }))
      .mockResolvedValue(makeProposal())
    renderPage()

    expect(await screen.findByText(/Generando la propuesta/)).toBeInTheDocument()
    expect(await screen.findByRole("heading", { name: "Comparar fracciones con tiras" }, { timeout: 4000 })).toBeInTheDocument()
    expect(fetch).toHaveBeenCalledTimes(2)
  })
})
