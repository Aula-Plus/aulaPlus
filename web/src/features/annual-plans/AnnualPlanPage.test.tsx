import { render, screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { beforeEach, describe, expect, it, vi } from "vitest"

import { AnnualPlanPage } from "./AnnualPlanPage"
import * as annualPlansApi from "./annualPlansApi"
import type { AnnualPlanDetail } from "./annualPlansApi"
import { overlappingUnits } from "./timeline"

vi.mock("./annualPlansApi", async (importOriginal) => {
  const actual = await importOriginal<typeof import("./annualPlansApi")>()
  return {
    ...actual,
    fetchAnnualPlan: vi.fn(),
    createUnit: vi.fn(),
    createObjective: vi.fn(),
    updateAnnualPlan: vi.fn(),
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
        objectives: [
          { id: 1, description: "Representar una función", position: 1, planned: true, given: true },
          { id: 2, description: "Leer un gráfico", position: 2, planned: true, given: true },
        ],
      },
      {
        id: 12,
        name: "Ecuaciones",
        position: 2,
        start_date: "2026-04-20",
        end_date: "2026-06-26",
        status: "unplanned",
        objectives_count: 1,
        planned_count: 0,
        given_count: 0,
        objectives: [
          { id: 3, description: "Resolver ecuaciones", position: 1, planned: false, given: false },
        ],
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
    marks: [{ kind: "class", id: 5, date: "2026-03-10", title: "Tablas y gráficos", unit_id: 11 }],
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
    expect(screen.getByText(/Tablas y gráficos/)).toBeInTheDocument()
  })

  it("shows the derived status of each unit, never a status picker", async () => {
    renderPage()
    await screen.findByRole("heading", { name: "Matemática · 2°EMS-A" })
    await userEvent.click(screen.getByRole("tab", { name: "Unidades" }))
    expect(screen.getByText("Dada")).toBeInTheDocument()
    expect(screen.getByText("Sin planificar")).toBeInTheDocument()
    expect(screen.queryByRole("combobox")).not.toBeInTheDocument()
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

describe("overlappingUnits", () => {
  it("only pairs units whose periods cross", () => {
    const [a, b] = makePlan().units
    const c = { ...b, id: 13, start_date: "2026-08-01", end_date: "2026-09-01" }
    expect(overlappingUnits([a, b, c]).map(([x, y]) => [x.id, y.id])).toEqual([[11, 12]])
  })
})
