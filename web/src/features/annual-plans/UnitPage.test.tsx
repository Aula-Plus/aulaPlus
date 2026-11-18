import { render, screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { beforeEach, describe, expect, it, vi } from "vitest"

import { UnitPage } from "./UnitPage"
import * as unitApi from "./unitApi"
import type { UnitDetail } from "./unitApi"

vi.mock("./unitApi", async (importOriginal) => {
  const actual = await importOriginal<typeof import("./unitApi")>()
  return {
    ...actual,
    fetchUnit: vi.fn(),
    fetchUnitAccommodations: vi.fn(),
    fetchUnitCurricularItems: vi.fn(),
    saveUnitPlanner: vi.fn(),
    saveObjectiveCurricularItems: vi.fn(),
  }
})

function makeUnit(overrides: Partial<UnitDetail> = {}): UnitDetail {
  return {
    id: 5,
    name: "Ecuaciones de primer grado",
    position: 3,
    start_date: "2026-05-25",
    end_date: "2026-06-26",
    status: "planned",
    objectives_count: 1,
    planned_count: 1,
    given_count: 0,
    started_count: 0,
    objectives: [
      {
        id: 9,
        description: "Traducir un enunciado a lenguaje algebraico",
        position: 1,
        planned: true,
        given: false,
        status: "planned",
        class_to_close: null,
        curricular_items: [],
      },
    ],
    plan: {
      id: 7,
      year: 2026,
      group: { id: 1, name: "2°EMS-A" },
      subject: { id: 2, name: "Matemática" },
      teacher: { id: 3, name: "Mariana López" },
      framework: { id: 4, name: "ANEP Bachillerato" },
    },
    planner: {
      frameworks: [{ id: 4, name: "ANEP Bachillerato", planner_family: "anep" }],
      fields: [
        {
          id: 1,
          source: "catalog",
          family: "common",
          key: "problem_question",
          official_name: "Pregunta problematizadora",
          school_name: null,
          description: null,
          type: "short_text",
          options: null,
          required_by_program: false,
          value: null,
        },
        {
          id: 2,
          source: "catalog",
          family: "common",
          key: "differentiation",
          official_name: "Diferenciación",
          school_name: null,
          description: null,
          type: "automatic",
          options: null,
          required_by_program: false,
          value: null,
        },
      ],
    },
    can_edit: true,
    ...overrides,
  }
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={["/unidades/5"]}>
      <Routes>
        <Route path="/unidades/:id" element={<UnitPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

describe("UnitPage", () => {
  beforeEach(() => {
    vi.mocked(unitApi.fetchUnit).mockResolvedValue(makeUnit())
    vi.mocked(unitApi.fetchUnitAccommodations).mockResolvedValue([
      {
        type: "Tiempo extendido",
        category: "access",
        student_count: 2,
        students: [
          { id: 20, full_name: "Ana Pérez" },
          { id: 21, full_name: "Beto Ruiz" },
        ],
      },
    ])
    vi.mocked(unitApi.saveUnitPlanner).mockResolvedValue(makeUnit().planner)
  })

  it("shows the header and the differentiation aggregated by type, without names", async () => {
    renderPage()

    expect(await screen.findByText("Unidad 3 — Ecuaciones de primer grado")).toBeInTheDocument()
    expect(screen.getByText("Ningún campo es obligatorio para generar una clase.")).toBeInTheDocument()
    expect(await screen.findByText("Tiempo extendido (2 alumnos)")).toBeInTheDocument()
    expect(screen.queryByText("Ana Pérez")).not.toBeInTheDocument()
  })

  it("saves only the planner fields that changed", async () => {
    const user = userEvent.setup()
    renderPage()

    await user.type(await screen.findByLabelText("Pregunta problematizadora"), "¿Cómo lo verifico?")
    await user.click(screen.getByRole("button", { name: "Guardar planificación" }))

    await waitFor(() =>
      expect(unitApi.saveUnitPlanner).toHaveBeenCalledWith(5, { problem_question: "¿Cómo lo verifico?" }),
    )
  })

  it("opens «A mano» closed by default and unfolds the detail per student there", async () => {
    const user = userEvent.setup()
    renderPage()

    await screen.findByText("Unidad 3 — Ecuaciones de primer grado")
    expect(screen.queryByRole("dialog", { name: "A mano" })).not.toBeInTheDocument()

    await user.click(screen.getByRole("button", { name: "A mano" }))
    const panel = screen.getByRole("dialog", { name: "A mano" })
    expect(within(panel).queryByText("Ana Pérez")).not.toBeInTheDocument()

    await user.click(within(panel).getByRole("button", { name: "Ver el detalle por alumno" }))
    expect(within(panel).getByText("Ana Pérez")).toBeInTheDocument()

    await user.click(within(panel).getByRole("button", { name: "Cerrar «A mano»" }))
    expect(screen.queryByRole("dialog", { name: "A mano" })).not.toBeInTheDocument()
  })

  it("says so when the program has no catalog loaded", async () => {
    const user = userEvent.setup()
    vi.mocked(unitApi.fetchUnitCurricularItems).mockResolvedValue([])
    renderPage()

    await user.click(await screen.findByRole("tab", { name: "Objetivos" }))
    await user.click(screen.getByRole("button", { name: "Elementos curriculares" }))

    expect(
      await screen.findByText("Todavía no hay elementos del programa cargados para esta materia."),
    ).toBeInTheDocument()
  })

  it("links an objective to catalog items with their citation", async () => {
    const user = userEvent.setup()
    vi.mocked(unitApi.fetchUnitCurricularItems).mockResolvedValue([
      {
        id: 31,
        code: "CG-1",
        name: "Comunicación",
        type: "general_competency",
        description: null,
        parent_name: null,
        catalog: "MCN 2022",
        framework: "ANEP Bachillerato",
        source: "Progresiones de Aprendizaje 2022",
        page: "14",
      },
    ])
    vi.mocked(unitApi.saveObjectiveCurricularItems).mockResolvedValue()
    renderPage()

    await user.click(await screen.findByRole("tab", { name: "Objetivos" }))
    await user.click(screen.getByRole("button", { name: "Elementos curriculares" }))
    expect(
      await screen.findByText("ANEP Bachillerato · MCN 2022 — Progresiones de Aprendizaje 2022, p. 14"),
    ).toBeInTheDocument()
    await user.click(screen.getByRole("checkbox"))
    await user.click(screen.getByRole("button", { name: "Guardar elementos" }))

    await waitFor(() => expect(unitApi.saveObjectiveCurricularItems).toHaveBeenCalledWith(9, [31]))
  })

  it("is read-only for Psicopedagogía and Dirección", async () => {
    vi.mocked(unitApi.fetchUnit).mockResolvedValue(makeUnit({ can_edit: false }))
    const user = userEvent.setup()
    renderPage()

    await screen.findByText("Unidad 3 — Ecuaciones de primer grado")
    expect(screen.queryByRole("button", { name: "Guardar planificación" })).not.toBeInTheDocument()
    await user.click(screen.getByRole("tab", { name: "Objetivos" }))
    expect(screen.queryByRole("button", { name: "Elementos curriculares" })).not.toBeInTheDocument()
    expect(screen.queryByLabelText("Escribir un objetivo")).not.toBeInTheDocument()
  })
})
