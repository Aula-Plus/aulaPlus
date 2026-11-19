import { render, screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { NewClassPage } from "./NewClassPage"
import * as classesApi from "./classesApi"
import * as annualPlansApi from "@/features/annual-plans/annualPlansApi"
import type { AnnualPlanDetail } from "@/features/annual-plans/annualPlansApi"
import type { ClassProposal } from "./classesApi"
import type { Role } from "@/types"

let roles: Role[] = ["teacher"]
vi.mock("@/features/auth/AuthContext", () => ({
  useAuth: () => ({ user: { id: 3, name: "Mariana", email: "m@e.com", roles } }),
}))

const plan: AnnualPlanDetail = {
  id: 7,
  year: 2026,
  group: { id: 1, name: "2°EMS-A" },
  subject: { id: 2, name: "Matemática" },
  teacher: { id: 3, name: "Mariana" },
  framework: { id: 4, name: "ANEP" },
  units_count: 1,
  description: "",
  can_edit: true,
  units: [
    {
      id: 11,
      name: "Fracciones",
      position: 3,
      start_date: "2026-08-01",
      end_date: "2026-09-30",
      status: "unplanned",
      objectives_count: 2,
      planned_count: 0,
      given_count: 0,
      started_count: 0,
      objectives: [
        { id: 21, description: "Que comparen fracciones con material concreto", position: 1, planned: false, given: false, status: "unplanned", class_to_close: null },
        { id: 22, description: "Que ordenen fracciones", position: 2, planned: false, given: false, status: "unplanned", class_to_close: null },
      ],
    },
  ],
  coverage: { units_total: 1, units_given: 0, objectives_total: 2, objectives_planned: 0, objectives_given: 0, objectives_unplanned: 2 },
  marks: [],
}

const created = { id: 99, status: "pending" } as ClassProposal

function renderAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/nueva-clase" element={<NewClassPage />} />
        <Route path="/propuesta-de-clase/:id" element={<p>Pantalla de propuesta</p>} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  roles = ["teacher"]
  vi.spyOn(classesApi, "fetchMyClasses").mockResolvedValue({ drafts: [], classes: [] })
  vi.spyOn(classesApi, "newJourneyId").mockReturnValue("00000000-0000-4000-8000-000000000000")
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe("NewClassPage", () => {
  it("from a unit shows group, subject and unit fixed and sends only the unit", async () => {
    vi.spyOn(annualPlansApi, "fetchAnnualPlan").mockResolvedValue(plan)
    const request = vi.spyOn(classesApi, "requestClassProposal").mockResolvedValue(created)
    const user = userEvent.setup()

    renderAt("/nueva-clase?programa=7&unidad=11&objetivo=21")

    expect(await screen.findByText("2°EMS-A")).toBeInTheDocument()
    expect(screen.getByText("Matemática")).toBeInTheDocument()
    expect(screen.getByText("Unidad 3 — Fracciones")).toBeInTheDocument()
    // Nothing to change: no group or subject selector.
    expect(screen.queryByLabelText("Grupo")).not.toBeInTheDocument()
    expect(screen.queryByLabelText("Materia")).not.toBeInTheDocument()
    // The objective of the «Nueva clase» button comes ticked.
    expect(screen.getByRole("checkbox", { name: "Que comparen fracciones con material concreto" })).toBeChecked()

    await user.type(screen.getByLabelText("Tema"), "Fracciones")
    await user.type(screen.getByLabelText("Qué querés que logren (opcional)"), "Que comparen fracciones con material concreto")
    await user.click(screen.getByRole("button", { name: /Generar con la IA/ }))

    await screen.findByText("Pantalla de propuesta")
    const [groupId, params] = request.mock.calls[0]
    expect(groupId).toBe(1)
    expect(params).toMatchObject({ unit_id: 11, unit_objective_ids: [21], focus: "Fracciones" })
    expect(params).not.toHaveProperty("subject_id")
  })

  it("entering directly requires group and subject and offers only the subjects taught in that group", async () => {
    vi.spyOn(annualPlansApi, "fetchAnnualPlanOptions").mockResolvedValue({
      assignments: [
        { group_id: 1, group_name: "2°EMS-A", subject_id: 2, subject_name: "Matemática" },
        { group_id: 1, group_name: "2°EMS-A", subject_id: 5, subject_name: "Física" },
        { group_id: 8, group_name: "3°EMS-B", subject_id: 2, subject_name: "Matemática" },
      ],
      frameworks: [],
    })
    vi.spyOn(annualPlansApi, "fetchAnnualPlans").mockResolvedValue([])
    const request = vi.spyOn(classesApi, "requestClassProposal").mockResolvedValue(created)
    const user = userEvent.setup()

    renderAt("/nueva-clase")

    const groupSelect = await screen.findByLabelText("Grupo")
    await user.type(screen.getByLabelText("Tema"), "Fracciones")
    const generate = screen.getByRole("button", { name: /Generar con la IA/ })
    expect(generate).toBeDisabled()

    await user.selectOptions(groupSelect, "1")
    const subjects = within(screen.getByLabelText("Materia")).getAllByRole("option").map((o) => o.textContent)
    expect(subjects).toEqual(["Elegí…", "Matemática", "Física"])
    expect(generate).toBeDisabled()

    await user.selectOptions(screen.getByLabelText("Materia"), "2")
    expect(screen.getByText(/clase libre/i, { selector: "p" })).toBeInTheDocument()
    await user.click(generate)

    await screen.findByText("Pantalla de propuesta")
    expect(request.mock.calls[0][1]).toMatchObject({ subject_id: 2, focus: "Fracciones" })
    expect(request.mock.calls[0][1]).not.toHaveProperty("unit_id")
  })

  it("tells Psicopedagogía and Dirección that classes are generated by the teacher", () => {
    roles = ["psychopedagogue"]
    renderAt("/nueva-clase")

    expect(screen.getByText("Las clases las genera el docente")).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /Generar con la IA/ })).not.toBeInTheDocument()
  })

  it("lists «Tus clases» with drafts and confirmed classes", async () => {
    vi.spyOn(annualPlansApi, "fetchAnnualPlanOptions").mockResolvedValue({ assignments: [], frameworks: [] })
    vi.mocked(classesApi.fetchMyClasses).mockResolvedValue({
      drafts: [{ id: 5, title: "Comparar fracciones", group: { id: 1, name: "2°EMS-A" }, date: "2026-11-20", status: "completed" }],
      classes: [
        {
          id: 6,
          title: "Distancias en el plano",
          group: { id: 1, name: "2°EMS-A" },
          subject: { id: 2, name: "Matemática" },
          date: "2026-07-15",
          status: "delivered",
          closing_result: "full",
        },
      ],
    })

    renderAt("/nueva-clase")

    expect(await screen.findByRole("link", { name: "Comparar fracciones" })).toHaveAttribute("href", "/propuesta-de-clase/5")
    expect(screen.getByText("Borrador")).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "Distancias en el plano" })).toHaveAttribute("href", "/clase/6")
    expect(screen.getByText("Cerrada · Todo")).toBeInTheDocument()
  })

  it("shows a clear message when the server rejects the request", async () => {
    vi.spyOn(annualPlansApi, "fetchAnnualPlan").mockResolvedValue(plan)
    const error = Object.assign(new Error("x"), { isAxiosError: true, response: { status: 503 } })
    vi.spyOn(classesApi, "requestClassProposal").mockRejectedValue(error)
    const user = userEvent.setup()

    renderAt("/nueva-clase?programa=7&unidad=11")
    await user.type(await screen.findByLabelText("Tema"), "Fracciones")
    await user.click(screen.getByRole("button", { name: /Generar con la IA/ }))

    await waitFor(() => expect(screen.getByText("La IA no está disponible en este momento.")).toBeInTheDocument())
  })
})
