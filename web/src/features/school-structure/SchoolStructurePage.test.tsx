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

const frameworks = [
  { id: 1, name: "ANEP EBI", planner_family: "anep" as const },
  { id: 2, name: "Cambridge Lower Secondary", planner_family: "cambridge" as const },
]

const row = {
  group: { id: 10, name: "5.°A", school_year: 2026 },
  subject: { id: 20, name: "Matemática" },
  frameworks: [] as typeof frameworks,
}

function plannerSettings(): PlannerFamilySettings[] {
  const field = (id: number, key: string, name: string, locked: boolean) => ({
    id,
    source: "catalog" as const,
    family: locked ? ("ib_myp" as const) : ("common" as const),
    key,
    official_name: name,
    school_name: null,
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
  ]
}

afterEach(() => vi.restoreAllMocks())

describe("SchoolStructurePage", () => {
  it("is not available to teachers or psychopedagogy", () => {
    const fetch = vi.spyOn(structureApi, "fetchCurricularOfferings")
    renderPage("teacher")

    expect(screen.getByText("Esta pantalla no está disponible para tu cuenta.")).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "Volver al inicio" })).toHaveAttribute("href", "/")
    expect(fetch).not.toHaveBeenCalled()
  })

  it("lets direction give a group-subject ANEP plus another program", async () => {
    vi.spyOn(structureApi, "fetchCurricularOfferings").mockResolvedValue({ rows: [row], frameworks })
    const update = vi
      .spyOn(structureApi, "updateCurricularOffering")
      .mockResolvedValue({ ...row, frameworks })

    renderPage("director")
    await userEvent.click(await screen.findByRole("button", { name: "Editar programas" }))
    await userEvent.click(screen.getByLabelText("ANEP EBI"))
    await userEvent.click(screen.getByLabelText("Cambridge Lower Secondary"))
    await userEvent.click(screen.getByRole("button", { name: "Guardar" }))

    expect(update).toHaveBeenCalledWith(10, 20, [1, 2])
    expect(await screen.findByText("ANEP EBI + Cambridge Lower Secondary")).toBeInTheDocument()
  })

  it("does not save an offering without ANEP", async () => {
    vi.spyOn(structureApi, "fetchCurricularOfferings").mockResolvedValue({ rows: [row], frameworks })
    const update = vi.spyOn(structureApi, "updateCurricularOffering")

    renderPage("director")
    await userEvent.click(await screen.findByRole("button", { name: "Editar programas" }))
    await userEvent.click(screen.getByLabelText("Cambridge Lower Secondary"))
    await userEvent.click(screen.getByRole("button", { name: "Guardar" }))

    expect(screen.getByRole("alert")).toHaveTextContent(/programa de ANEP/)
    expect(update).not.toHaveBeenCalled()
  })

  it("renames a common field and never lets a program field be hidden", async () => {
    vi.spyOn(structureApi, "fetchCurricularOfferings").mockResolvedValue({ rows: [], frameworks })
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
    vi.spyOn(structureApi, "fetchCurricularOfferings").mockResolvedValue({ rows: [], frameworks })
    vi.spyOn(structureApi, "fetchPlannerSettings").mockResolvedValue(plannerSettings())
    const create = vi.spyOn(structureApi, "createCustomField").mockResolvedValue(plannerSettings())

    renderPage("director")
    await userEvent.click(screen.getByRole("tab", { name: "Planificadores" }))
    await userEvent.click(await screen.findByRole("button", { name: "IB MYP" }))
    await userEvent.type(screen.getByLabelText("Nombre del campo"), "Salida didáctica")
    await userEvent.selectOptions(screen.getByLabelText("Tipo"), "list")
    await userEvent.type(screen.getByLabelText("Opciones, separadas por coma"), "Sí, No")
    await userEvent.click(screen.getByRole("button", { name: "Agregar" }))

    expect(create).toHaveBeenCalledWith({
      name: "Salida didáctica",
      type: "list",
      family: "ib_myp",
      options: ["Sí", "No"],
      required_by_school: false,
    })
  })
})
