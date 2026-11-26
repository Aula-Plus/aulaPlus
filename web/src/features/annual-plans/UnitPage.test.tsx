import { render, screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { beforeEach, describe, expect, it, vi } from "vitest"

import { UnitPage } from "./UnitPage"
import * as unitApi from "./unitApi"
import type { UnitDetail } from "./unitApi"
import * as resourcesApi from "@/features/resources/resourcesApi"
import type { GroupResources } from "@/features/resources/resourcesApi"

vi.mock("./unitApi", async (importOriginal) => {
  const actual = await importOriginal<typeof import("./unitApi")>()
  return {
    ...actual,
    fetchUnit: vi.fn(),
    fetchUnitAccommodations: vi.fn(),
    fetchUnitCurricularItems: vi.fn(),
    saveUnitPlanner: vi.fn(),
    saveObjectiveCurricularItems: vi.fn(),
    createObjectiveFromCatalog: vi.fn(),
  }
})

vi.mock("@/features/resources/resourcesApi", () => ({
  fetchGroupResources: vi.fn(),
  setSchoolResourceAvailable: vi.fn(),
  createGroupResource: vi.fn(),
  deleteGroupResource: vi.fn(),
}))

function makeGroupResources(overrides: Partial<GroupResources> = {}): GroupResources {
  return {
    can_manage: true,
    can_add_own: true,
    school: [{ id: 4, name: "Huerta escolar", available: true }],
    group: [
      { id: 11, name: "Tele en el salón", visibility: "group", created_by_name: "Mariana López", can_remove: true },
    ],
    ...overrides,
  }
}

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
      group: { id: 1, name: "2°EMS-A", school_year: 2026, year_closed: false, read_only: false, writable_until: null },
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
        {
          id: 3,
          source: "catalog",
          family: "common",
          key: "resources",
          official_name: "Recursos disponibles",
          school_name: null,
          description: null,
          type: "automatic",
          options: null,
          required_by_program: false,
          value: ["group:12"],
        },
      ],
      resources: [
        { key: "school:4", name: "Huerta escolar", source: "school" },
        { key: "group:11", name: "Tele en el salón", source: "group" },
        { key: "group:12", name: "Calculadoras", source: "own" },
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
    vi.mocked(resourcesApi.fetchGroupResources).mockResolvedValue(makeGroupResources())
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

  it("shows the group resources checked except the ones unchecked for this unit, and saves the change", async () => {
    const user = userEvent.setup()
    renderPage()

    const garden = await screen.findByRole("checkbox", { name: /Huerta escolar/ })
    expect(garden).toBeChecked()
    expect(screen.getByRole("checkbox", { name: /Calculadoras/ })).not.toBeChecked()
    expect(screen.queryByText("Aula+ lo completa con los datos del grupo.")).not.toBeInTheDocument()

    await user.click(garden)
    await user.click(screen.getByRole("checkbox", { name: /Calculadoras/ }))
    await user.click(screen.getByRole("button", { name: "Guardar planificación" }))

    await waitFor(() => expect(unitApi.saveUnitPlanner).toHaveBeenCalledWith(5, { resources: ["school:4"] }))
  })

  it("saves null, not an empty list, when every resource is checked again (the form is not left dirty)", async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole("checkbox", { name: /Calculadoras/ }))
    await user.click(screen.getByRole("button", { name: "Guardar planificación" }))

    await waitFor(() => expect(unitApi.saveUnitPlanner).toHaveBeenCalledWith(5, { resources: null }))
  })

  it("lets the group's teacher change the group's resources from «A mano», reloading the unit", async () => {
    vi.mocked(resourcesApi.setSchoolResourceAvailable).mockResolvedValue(
      makeGroupResources({ school: [{ id: 4, name: "Huerta escolar", available: false }] }),
    )
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole("button", { name: "A mano" }))
    const panel = screen.getByRole("dialog", { name: "A mano" })
    expect(resourcesApi.fetchGroupResources).toHaveBeenCalledWith(1)
    expect(within(panel).getByText("No se usan en esta unidad: Calculadoras. Lo cambiás en la planificación.")).toBeInTheDocument()
    expect(within(panel).getByText("· lo sumó Mariana López")).toBeInTheDocument()
    expect(within(panel).getByRole("textbox", { name: "Agregar un recurso del grupo" })).toBeInTheDocument()

    const callsBefore = vi.mocked(unitApi.fetchUnit).mock.calls.length
    await user.click(await within(panel).findByRole("checkbox", { name: "Huerta escolar" }))

    await waitFor(() => expect(resourcesApi.setSchoolResourceAvailable).toHaveBeenCalledWith(1, 4, false))
    await waitFor(() => expect(vi.mocked(unitApi.fetchUnit).mock.calls.length).toBeGreaterThan(callsBefore))
  })

  it("shows «A mano»'s resources read-only for Psicopedagogía and Dirección, marking the ones this unit does not use", async () => {
    vi.mocked(unitApi.fetchUnit).mockResolvedValue(makeUnit({ can_edit: false }))
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole("button", { name: "A mano" }))
    const panel = screen.getByRole("dialog", { name: "A mano" })
    expect(within(panel).getByText("Huerta escolar")).toBeInTheDocument()
    expect(within(panel).getByText("Calculadoras")).toHaveClass("line-through")
    expect(within(panel).getByText("No se usa en esta unidad")).toBeInTheDocument()
    expect(within(panel).queryByRole("checkbox")).not.toBeInTheDocument()
    expect(within(panel).queryByRole("textbox", { name: "Agregar un recurso del grupo" })).not.toBeInTheDocument()
    expect(resourcesApi.fetchGroupResources).not.toHaveBeenCalled()
  })

  it("starts a new class from the unit and from each objective", async () => {
    const user = userEvent.setup()
    renderPage()

    expect(await screen.findByRole("link", { name: "Nueva clase desde esta unidad" })).toHaveAttribute(
      "href",
      "/nueva-clase?programa=7&unidad=5",
    )
    await user.click(screen.getByRole("tab", { name: "Objetivos" }))
    expect(screen.getByRole("link", { name: "Nueva clase" })).toHaveAttribute(
      "href",
      "/nueva-clase?programa=7&unidad=5&objetivo=9",
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
    expect(screen.queryByRole("link", { name: "Nueva clase desde esta unidad" })).not.toBeInTheDocument()
    expect(screen.queryByRole("checkbox", { name: /Huerta escolar/ })).not.toBeInTheDocument()
    expect(screen.getByText("Huerta escolar · Tele en el salón")).toBeInTheDocument()
    await user.click(screen.getByRole("tab", { name: "Objetivos" }))
    expect(screen.queryByRole("button", { name: "Elementos curriculares" })).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Escribir un objetivo" })).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Elegir del catálogo" })).not.toBeInTheDocument()
  })

  function closedYear(writableUntil: string | null): UnitDetail {
    const unit = makeUnit()
    return {
      ...unit,
      plan: {
        ...unit.plan,
        group: { ...unit.plan.group, year_closed: true, read_only: writableUntil === null, writable_until: writableUntil },
      },
    }
  }

  it("is read-only, with the group's notice, in a group of a closed year", async () => {
    vi.mocked(unitApi.fetchUnit).mockResolvedValue(closedYear(null))
    const user = userEvent.setup()
    renderPage()

    expect(await screen.findByText("El año 2026 está cerrado: este grupo es solo para leer.")).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Guardar datos" })).not.toBeInTheDocument()
    expect(screen.queryByLabelText("Título")).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Guardar planificación" })).not.toBeInTheDocument()
    expect(screen.queryByLabelText("Pregunta problematizadora")).not.toBeInTheDocument()
    await user.click(screen.getByRole("tab", { name: "Objetivos" }))
    expect(screen.queryByRole("button", { name: "Elementos curriculares" })).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Elegir del catálogo" })).not.toBeInTheDocument()
  })

  it("is edited as usual while direction enabled loading in the group", async () => {
    vi.mocked(unitApi.fetchUnit).mockResolvedValue(closedYear("2027-02-28"))
    renderPage()

    expect(await screen.findByText(/Dirección habilitó la carga en este grupo hasta el/)).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Guardar datos" })).toBeInTheDocument()
    expect(screen.getByLabelText("Pregunta problematizadora")).toBeInTheDocument()
  })

  it("adds an objective picked from the catalog, without writing it", async () => {
    const user = userEvent.setup()
    vi.mocked(unitApi.fetchUnitCurricularItems).mockResolvedValue([
      {
        id: 40,
        code: "1.1",
        name: "Knowledge and understanding",
        type: "content",
        description: null,
        parent_name: "Group 5: Mathematics",
        catalog: "IB DP (muestra)",
        framework: "IB DP",
        source: "Mathematics guide",
        page: "20",
      },
    ])
    vi.mocked(unitApi.createObjectiveFromCatalog).mockResolvedValue()
    renderPage()

    await user.click(await screen.findByRole("tab", { name: "Objetivos" }))
    await user.click(screen.getByRole("button", { name: "Elegir del catálogo" }))
    await user.click(screen.getByRole("button", { name: "Buscar en el catálogo" }))
    const dialog = await screen.findByRole("dialog", { name: "Elegir un objetivo del catálogo" })
    expect(await within(dialog).findByText("IB DP · IB DP (muestra) — Mathematics guide, p. 20")).toBeInTheDocument()
    await user.click(within(dialog).getByRole("button", { name: "Elegir «Knowledge and understanding»" }))

    await waitFor(() => expect(unitApi.createObjectiveFromCatalog).toHaveBeenCalledWith(5, 40))
    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument())
  })

  it("marks the catalog elements that already are objectives of the unit and does not offer them again", async () => {
    const user = userEvent.setup()
    const linked = {
      id: 40,
      code: "1.1",
      name: "Knowledge and understanding",
      type: "content",
      description: null,
      parent_name: "Group 5: Mathematics",
      catalog: "IB DP (muestra)",
      framework: "IB DP",
      source: "Mathematics guide",
      page: "20",
    }
    const unit = makeUnit()
    unit.objectives[0].curricular_items = [linked]
    vi.mocked(unitApi.fetchUnit).mockResolvedValue(unit)
    vi.mocked(unitApi.fetchUnitCurricularItems).mockResolvedValue([
      linked,
      { ...linked, id: 41, code: "1.2", name: "Problem solving" },
    ])
    vi.mocked(unitApi.createObjectiveFromCatalog).mockResolvedValue()
    renderPage()

    await user.click(await screen.findByRole("tab", { name: "Objetivos" }))
    await user.click(screen.getByRole("button", { name: "Elegir del catálogo" }))
    await user.click(screen.getByRole("button", { name: "Buscar en el catálogo" }))
    const dialog = await screen.findByRole("dialog", { name: "Elegir un objetivo del catálogo" })

    const taken = await within(dialog).findByRole("button", { name: "Elegir «Knowledge and understanding»" })
    expect(taken).toBeDisabled()
    expect(within(dialog).getAllByText("Ya es objetivo de esta unidad.")).toHaveLength(1)
    await user.click(taken)
    expect(unitApi.createObjectiveFromCatalog).not.toHaveBeenCalled()

    await user.click(within(dialog).getByRole("button", { name: "Elegir «Problem solving»" }))
    await waitFor(() => expect(unitApi.createObjectiveFromCatalog).toHaveBeenCalledWith(5, 41))
  })

  it("says the catalog failed to load instead of flagging linked items as gone", async () => {
    const user = userEvent.setup()
    const unit = makeUnit()
    unit.objectives[0].curricular_items = [
      {
        id: 31,
        code: "CG-1",
        name: "Comunicación",
        type: "general_competency",
        description: null,
        parent_name: null,
        catalog: "MCN 2022",
        framework: "ANEP Bachillerato",
        source: null,
        page: null,
      },
    ]
    vi.mocked(unitApi.fetchUnit).mockResolvedValue(unit)
    vi.mocked(unitApi.fetchUnitCurricularItems).mockRejectedValue(new Error("network"))
    renderPage()

    await user.click(await screen.findByRole("tab", { name: "Objetivos" }))
    await user.click(screen.getByRole("button", { name: "Elementos curriculares" }))

    expect(await screen.findByText(/No pudimos cargar el catálogo del programa/)).toBeInTheDocument()
    expect(screen.queryByText("Ya no está en el catálogo del programa.")).not.toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Guardar elementos" })).toBeDisabled()
  })
})
