import { render, screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import { AuthContext } from "@/features/auth/AuthContext"
import type { Role, User } from "@/types"
import { SchoolStructurePage } from "./SchoolStructurePage"
import * as structureApi from "./schoolStructureApi"
import type { PlannerFamilySettings } from "./schoolStructureApi"

function renderPage(role: Role) {
  const user = { id: 1, name: "Dir", email: "d@x.uy", school_id: 1, roles: [role] } as unknown as User
  return render(
    <AuthContext.Provider value={{ user, loading: false, login: vi.fn(), logout: vi.fn() } as never}>
      <MemoryRouter>
        <SchoolStructurePage />
      </MemoryRouter>
    </AuthContext.Provider>,
  )
}

const anep = { id: 1, name: "ANEP EBI", planner_family: "anep" as const, locked: true }
const cambridge = { id: 2, name: "Cambridge Primary", planner_family: "cambridge" as const, locked: false }

const row = {
  group: { id: 10, name: "5.°A", school_year: 2026, level: "Primaria" },
  subject: { id: 20, name: "Matemática" },
  frameworks: [anep],
  available_frameworks: [anep, cambridge],
}

function plannerSettings(): PlannerFamilySettings[] {
  const field = (id: number, key: string, name: string, locked: boolean) => ({
    id,
    source: "catalog" as const,
    family: locked ? ("ib_myp" as const) : ("common" as const),
    key,
    official_name: name,
    school_name: null,
    description: null,
    purpose: null,
    type: "short_text" as const,
    options: null,
    required_by_program: locked,
    required_by_school: false,
    shown: true,
  })
  return [
    { family: "common", fields: [field(1, "problem_question", "Pregunta problematizadora", false)], custom_fields: [] },
    { family: "anep", fields: [], custom_fields: [] },
    { family: "ib_myp", fields: [field(2, "key_concept", "Concepto clave", true)], custom_fields: [] },
    { family: "ib_pyp", fields: [], custom_fields: [] },
    { family: "cambridge", fields: [], custom_fields: [] },
    { family: "igcse", fields: [], custom_fields: [] },
    { family: "ib_dp", fields: [], custom_fields: [] },
  ]
}

afterEach(() => vi.restoreAllMocks())

describe("SchoolStructurePage", () => {
  it("is not available to teachers or psychopedagogy", () => {
    const fetch = vi.spyOn(structureApi, "fetchCurricularOfferings")
    renderPage("teacher")

    expect(
      screen.getByRole("heading", { level: 1, name: "Esta pantalla no está disponible para tu cuenta" }),
    ).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "Volver al inicio" })).toHaveAttribute("href", "/")
    expect(fetch).not.toHaveBeenCalled()
  })

  it("shows the row with its locked program and lets direction add another one of the group's level", async () => {
    vi.spyOn(structureApi, "fetchCurricularOfferings").mockResolvedValue([row])
    const update = vi
      .spyOn(structureApi, "updateCurricularOffering")
      .mockResolvedValue({ ...row, frameworks: [anep, cambridge] })

    renderPage("director")
    expect(await screen.findByText("ANEP EBI")).toBeInTheDocument()
    expect(screen.queryByText("Sin programa asignado")).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole("button", { name: "Editar programas" }))
    // Only the programs offered for the group's level, the locked one checked and fixed.
    expect(screen.getAllByRole("checkbox")).toHaveLength(2)
    expect(screen.getByLabelText("ANEP EBI")).toBeChecked()
    expect(screen.getByLabelText("ANEP EBI")).toBeDisabled()
    await userEvent.click(screen.getByLabelText("Cambridge Primary"))
    await userEvent.click(screen.getByRole("button", { name: "Guardar" }))

    expect(update).toHaveBeenCalledWith(10, 20, [1, 2])
    expect(await screen.findByText("ANEP EBI + Cambridge Primary")).toBeInTheDocument()
  })

  it("never explains on screen why a program is fixed", async () => {
    vi.spyOn(structureApi, "fetchCurricularOfferings").mockResolvedValue([row])
    renderPage("director")
    await screen.findByText("ANEP EBI")
    expect(screen.queryByText(/ANEP está siempre|no se puede sacar|Uruguay/)).not.toBeInTheDocument()
  })

  it("renames a common field and never lets a program field be hidden", async () => {
    vi.spyOn(structureApi, "fetchCurricularOfferings").mockResolvedValue([])
    vi.spyOn(structureApi, "fetchPlannerSettings").mockResolvedValue(plannerSettings())
    const update = vi.spyOn(structureApi, "updatePlannerField").mockResolvedValue(plannerSettings())

    renderPage("director")
    await userEvent.click(screen.getByRole("tab", { name: "Planificadores" }))
    await userEvent.type(await screen.findByLabelText("Pregunta problematizadora"), "Pregunta guía")
    await userEvent.click(screen.getByRole("button", { name: "Guardar" }))
    expect(update).toHaveBeenCalledWith(1, { school_name: "Pregunta guía", shown: true })

    await userEvent.click(screen.getByRole("button", { name: "IB MYP" }))
    const table = screen.getByRole("table")
    expect(within(table).getByLabelText("Mostrar Concepto clave")).toBeDisabled()
    expect(within(table).getByText("El programa")).toBeInTheDocument()
  })

  it("adds a list field of the school for one framework", async () => {
    vi.spyOn(structureApi, "fetchCurricularOfferings").mockResolvedValue([])
    vi.spyOn(structureApi, "fetchPlannerSettings").mockResolvedValue(plannerSettings())
    const create = vi.spyOn(structureApi, "createCustomField").mockResolvedValue(plannerSettings())

    renderPage("director")
    await userEvent.click(screen.getByRole("tab", { name: "Planificadores" }))
    await userEvent.click(await screen.findByRole("button", { name: "IB MYP" }))
    await userEvent.type(screen.getByLabelText("Nombre del campo"), "Salida didáctica")
    await userEvent.type(screen.getByLabelText("Qué significa para el colegio"), "Si la unidad incluye una salida.")
    await userEvent.click(screen.getByLabelText("Solo para registro"))
    await userEvent.selectOptions(screen.getByLabelText("Tipo"), "list")
    await userEvent.type(screen.getByLabelText("Opciones, separadas por coma"), "Sí, No")
    await userEvent.click(screen.getByRole("button", { name: "Agregar" }))

    expect(create).toHaveBeenCalledWith({
      name: "Salida didáctica",
      description: "Si la unidad incluye una salida.",
      purpose: "record",
      type: "list",
      family: "ib_myp",
      options: ["Sí", "No"],
      required_by_school: false,
    })
  })

  it("lets the school add its own fields to IGCSE and IB DP, which start with only the common base", async () => {
    vi.spyOn(structureApi, "fetchCurricularOfferings").mockResolvedValue([])
    vi.spyOn(structureApi, "fetchPlannerSettings").mockResolvedValue(plannerSettings())
    const create = vi.spyOn(structureApi, "createCustomField").mockResolvedValue(plannerSettings())

    renderPage("director")
    await userEvent.click(screen.getByRole("tab", { name: "Planificadores" }))
    await userEvent.click(await screen.findByRole("button", { name: "IB DP" }))
    expect(screen.getByText("Va solo en las materias que siguen IB DP.")).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText("Nombre del campo"), "Theory of Knowledge")
    await userEvent.click(screen.getByRole("button", { name: "Agregar" }))

    expect(create).toHaveBeenCalledWith(
      expect.objectContaining({ name: "Theory of Knowledge", family: "ib_dp", purpose: "ai", description: null }),
    )
    expect(screen.getByRole("button", { name: "IGCSE" })).toBeInTheDocument()
  })
})
