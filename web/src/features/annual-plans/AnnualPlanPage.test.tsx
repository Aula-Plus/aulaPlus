import { render, screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { beforeEach, describe, expect, it, vi } from "vitest"

import { AnnualPlanPage } from "./AnnualPlanPage"
import * as annualPlansApi from "./annualPlansApi"
import type { AnnualPlanDetail } from "./annualPlansApi"
import { givenLabel, overlappingUnits, unitSummary } from "./timeline"
import { createPlanError } from "./planErrors"

vi.mock("./annualPlansApi", async (importOriginal) => {
  const actual = await importOriginal<typeof import("./annualPlansApi")>()
  return {
    ...actual,
    fetchAnnualPlan: vi.fn(),
    createUnit: vi.fn(),
    createObjective: vi.fn(),
    deleteObjective: vi.fn(),
    updateAnnualPlan: vi.fn(),
    updateClassObjectiveCoverage: vi.fn(),
  }
})

function makePlan(overrides: Partial<AnnualPlanDetail> = {}): AnnualPlanDetail {
  return {
    id: 7,
    year: 2026,
    group: { id: 1, name: "2°EMS-A" },
    subject: { id: 2, name: "Matemática" },
    teacher: { id: 3, name: "Mariana López" },
    framework: { id: 4, name: "ANEP Bachillerato" },
    units_count: 2,
    description: "Resolver problemas de varios pasos.",
    can_edit: true,
    units: [
      {
        id: 11,
        name: "Funciones",
        position: 1,
        start_date: "2026-03-02",
        end_date: "2026-04-24",
        status: "given",
        objectives_count: 2,
        planned_count: 2,
        given_count: 2,
        started_count: 0,
        objectives: [
          {
            id: 1,
            description: "Representar una función",
            position: 1,
            planned: true,
            given: true,
            status: "given",
            class_to_close: null,
          },
          {
            id: 2,
            description: "Leer un gráfico",
            position: 2,
            planned: true,
            given: true,
            status: "given",
            class_to_close: null,
          },
        ],
      },
      {
        id: 12,
        name: "Ecuaciones",
        position: 2,
        start_date: "2026-04-20",
        end_date: "2026-06-26",
        status: "unplanned",
        objectives_count: 4,
        planned_count: 3,
        given_count: 2,
        started_count: 1,
        objectives: [
          {
            id: 3,
            description: "Resolver ecuaciones",
            position: 1,
            planned: true,
            given: false,
            status: "started",
            class_to_close: { id: 40, date: "2026-05-10", title: "Ecuaciones I" },
          },
          {
            id: 4,
            description: "Despejar una incógnita",
            position: 2,
            planned: true,
            given: true,
            status: "given",
            class_to_close: null,
          },
          {
            id: 5,
            description: "Verificar una solución",
            position: 3,
            planned: true,
            given: true,
            status: "given",
            class_to_close: null,
          },
          {
            id: 6,
            description: "Plantear un problema",
            position: 4,
            planned: false,
            given: false,
            status: "unplanned",
            class_to_close: null,
          },
        ],
      },
      {
        id: 13,
        name: "Estadística",
        position: 3,
        start_date: "2026-08-03",
        end_date: "2026-10-30",
        status: null,
        objectives_count: 0,
        planned_count: 0,
        given_count: 0,
        started_count: 0,
        objectives: [],
      },
    ],
    coverage: {
      units_total: 2,
      units_given: 1,
      objectives_total: 3,
      objectives_planned: 2,
      objectives_given: 2,
      objectives_unplanned: 1,
    },
    marks: [
      { kind: "class", id: 5, date: "2026-03-10", title: "Tablas y gráficos", unit_id: 11 },
      { kind: "assessment", id: 9, date: "2026-04-20", title: "Escrito 1", unit_id: null },
    ],
    ...overrides,
  }
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={["/programa-anual/7"]}>
      <Routes>
        <Route path="/programa-anual/:id" element={<AnnualPlanPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

describe("AnnualPlanPage", () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(annualPlansApi.fetchAnnualPlan).mockResolvedValue(makePlan())
  })

  it("opens on Cronograma with the plan data and warns about overlapping units", async () => {
    renderPage()
    expect(await screen.findByRole("heading", { name: "Matemática · 2°EMS-A" })).toBeInTheDocument()
    expect(screen.getByRole("tab", { name: "Cronograma" })).toHaveAttribute("aria-selected", "true")
    expect(screen.getByText(/Se superponen: Funciones y Ecuaciones/)).toBeInTheDocument()
    expect(screen.getAllByText(/Tablas y gráficos/).length).toBeGreaterThan(0)
  })

  it("draws given and started on the unit bar, with a summary tooltip", async () => {
    renderPage()
    await screen.findByRole("heading", { name: "Matemática · 2°EMS-A" })
    const bar = screen.getByTestId("unit-bar-12")
    expect(bar).toHaveAttribute("title", "4 objetivos · 2 dados · 1 empezado · 1 sin planificar")
    expect(screen.getByTestId("unit-bar-started-12")).toHaveStyle({ width: "25%" })
  })

  it("places class and assessment marks on the timeline, the assessment opening its page", async () => {
    renderPage()
    await screen.findByRole("heading", { name: "Matemática · 2°EMS-A" })
    const timeline = screen.getByRole("list", { name: "Línea de tiempo" })
    expect(within(timeline).getByRole("img", { name: /Clase · .* — Tablas y gráficos/ })).toBeInTheDocument()
    expect(within(timeline).getByRole("link", { name: /Evaluación · .* — Escrito 1/ })).toHaveAttribute(
      "href",
      "/clases/1/evaluaciones",
    )
  })

  it("opens a unit from the Cronograma", async () => {
    renderPage()
    await userEvent.click(await screen.findByRole("button", { name: "2 · Ecuaciones" }))
    expect(screen.getByRole("tab", { name: "Unidades" })).toHaveAttribute("aria-selected", "true")
    expect(screen.getByText(/Resolver ecuaciones/)).toBeInTheDocument()
  })

  it("shows the derived status of each unit, never a status picker", async () => {
    renderPage()
    await screen.findByRole("heading", { name: "Matemática · 2°EMS-A" })
    await userEvent.click(screen.getByRole("tab", { name: "Unidades" }))
    expect(screen.getByText("Dada")).toBeInTheDocument()
    expect(screen.getByText("Sin planificar")).toBeInTheDocument()
    expect(screen.getByText("2 de 4 · 1 empezado")).toBeInTheDocument()
    expect(screen.queryByRole("combobox")).not.toBeInTheDocument()
  })

  it("shows no state for a unit without objectives and opens it ready to write one", async () => {
    renderPage()
    await screen.findByRole("heading", { name: "Matemática · 2°EMS-A" })
    await userEvent.click(screen.getByRole("tab", { name: "Unidades" }))
    expect(screen.getByLabelText("Sin estado")).toHaveTextContent("—")
    await userEvent.click(screen.getByRole("button", { name: "Escribí los objetivos y el estado sale solo" }))
    expect(screen.getByLabelText("Escribir un objetivo")).toHaveFocus()
  })

  it("«Lo terminé» changes the class record from «una parte» to «todo»", async () => {
    vi.mocked(annualPlansApi.updateClassObjectiveCoverage).mockResolvedValue()
    renderPage()
    await screen.findByRole("heading", { name: "Matemática · 2°EMS-A" })
    await userEvent.click(screen.getByRole("tab", { name: "Unidades" }))
    await userEvent.click(screen.getAllByRole("button", { name: "Objetivos" })[1])
    expect(screen.getByText("Empezado")).toBeInTheDocument()
    expect(screen.queryByRole("checkbox")).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole("button", { name: "Lo terminé" }))
    const dialog = screen.getByRole("dialog")
    expect(within(dialog).getByText(/Ecuaciones I/)).toBeInTheDocument()
    await userEvent.click(within(dialog).getByRole("button", { name: "Cambiar a «todo»" }))
    await waitFor(() =>
      expect(annualPlansApi.updateClassObjectiveCoverage).toHaveBeenCalledWith(40, 3, "full"),
    )
    expect(annualPlansApi.fetchAnnualPlan).toHaveBeenCalledTimes(2)
  })

  it("asks before removing an objective", async () => {
    vi.mocked(annualPlansApi.deleteObjective).mockResolvedValue()
    renderPage()
    await screen.findByRole("heading", { name: "Matemática · 2°EMS-A" })
    await userEvent.click(screen.getByRole("tab", { name: "Unidades" }))
    await userEvent.click(screen.getAllByRole("button", { name: "Objetivos" })[0])
    await userEvent.click(screen.getAllByRole("button", { name: "Quitar" })[0])
    expect(annualPlansApi.deleteObjective).not.toHaveBeenCalled()
    const dialog = screen.getByRole("dialog")
    await userEvent.click(within(dialog).getByRole("button", { name: "Quitar" }))
    await waitFor(() => expect(annualPlansApi.deleteObjective).toHaveBeenCalledWith(1))
  })

  it("does not submit an objective twice while saving", async () => {
    let resolve: () => void = () => {}
    vi.mocked(annualPlansApi.createObjective).mockImplementation(
      () => new Promise<void>((r) => (resolve = r)),
    )
    renderPage()
    await screen.findByRole("heading", { name: "Matemática · 2°EMS-A" })
    await userEvent.click(screen.getByRole("tab", { name: "Unidades" }))
    await userEvent.click(screen.getAllByRole("button", { name: "Objetivos" })[0])
    await userEvent.type(screen.getByLabelText("Escribir un objetivo"), "Nuevo{Enter}{Enter}")
    expect(screen.getByRole("button", { name: "Guardando…" })).toBeDisabled()
    expect(annualPlansApi.createObjective).toHaveBeenCalledTimes(1)
    resolve()
    await waitFor(() => expect(screen.getByRole("button", { name: "Agregar objetivo" })).toBeInTheDocument())
  })

  it("shows coverage counters without an expected pace", async () => {
    renderPage()
    await screen.findByRole("heading", { name: "Matemática · 2°EMS-A" })
    await userEvent.click(screen.getByRole("tab", { name: "Cobertura" }))
    expect(screen.getByText("Sin planificar todavía")).toBeInTheDocument()
    expect(screen.getByText(/no hay un «ritmo esperado»/)).toBeInTheDocument()
  })

  it("lets the owning teacher add a unit", async () => {
    vi.mocked(annualPlansApi.createUnit).mockResolvedValue()
    renderPage()
    await userEvent.click(await screen.findByRole("button", { name: /Nueva unidad/ }))
    await userEvent.type(screen.getByLabelText("Título"), "Estadística")
    await userEvent.type(screen.getByLabelText("Desde"), "2026-08-03")
    await userEvent.type(screen.getByLabelText("Hasta"), "2026-10-30")
    await userEvent.click(screen.getByRole("button", { name: "Agregar unidad" }))
    await waitFor(() =>
      expect(annualPlansApi.createUnit).toHaveBeenCalledWith(7, {
        name: "Estadística",
        start_date: "2026-08-03",
        end_date: "2026-10-30",
      }),
    )
  })

  it("is read-only for Psicopedagogía and Dirección", async () => {
    vi.mocked(annualPlansApi.fetchAnnualPlan).mockResolvedValue(makePlan({ can_edit: false }))
    renderPage()
    expect(await screen.findByText(/solo lectura/)).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /Nueva unidad/ })).not.toBeInTheDocument()
    expect(screen.queryByLabelText("Propósito del año")).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole("tab", { name: "Unidades" }))
    await userEvent.click(screen.getAllByRole("button", { name: "Objetivos" })[0])
    expect(screen.getByText(/Representar una función/)).toBeInTheDocument()
    expect(screen.queryByLabelText("Escribir un objetivo")).not.toBeInTheDocument()
  })
})

describe("unit labels", () => {
  it("only mentions started objectives when there are any", () => {
    const [given, started, empty] = makePlan().units
    expect(givenLabel(given)).toBe("2 de 2")
    expect(givenLabel(started)).toBe("2 de 4 · 1 empezado")
    expect(unitSummary(empty)).toBe("Sin objetivos escritos todavía")
  })
})

describe("createPlanError", () => {
  const axiosError = (errors: Record<string, string[]>) =>
    Object.assign(new Error("422"), { isAxiosError: true, response: { status: 422, data: { errors } } })

  it("maps the failing field to a Spanish message", () => {
    expect(createPlanError(axiosError({ subject_id: ["x"] }))).toMatch(/Ya tenés un programa/)
    expect(createPlanError(axiosError({ curricular_framework_id: ["x"] }))).toMatch(/marco curricular ya no/)
    expect(createPlanError(new Error("network"))).toBe("No pudimos crear el programa. Probá de nuevo.")
  })
})

describe("overlappingUnits", () => {
  it("only pairs units whose periods cross", () => {
    const [a, b] = makePlan().units
    const c = { ...b, id: 13, start_date: "2026-08-01", end_date: "2026-09-01" }
    expect(overlappingUnits([a, b, c]).map(([x, y]) => [x.id, y.id])).toEqual([[11, 12]])
  })
})
