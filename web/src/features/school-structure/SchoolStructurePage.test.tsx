import { render, screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { AuthContext } from "@/features/auth/AuthContext"
import * as onboardingApi from "@/features/onboarding/onboardingApi"
import * as psychopedagogySpaceApi from "@/features/psychopedagogy-space/psychopedagogySpaceApi"
import type { OnboardingRecord } from "@/features/onboarding/onboardingApi"
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

const onboarding: OnboardingRecord = {
  import_completed_on: null,
  regime_configured_on: null,
  opened_on: null,
  offering_confirmed_on: null,
  contact: null,
  regimes: [
    { level_key: "initial", level_label: "Inicial", regime: "authorized", fixed: false, note: "Programa propio" },
    { level_key: "primary", level_label: "Primaria", regime: null, fixed: false, note: null },
    { level_key: "lower_secondary", level_label: "Ciclo Básico", regime: "enabled", fixed: true, note: null },
  ],
  sources: [],
  log: [],
  support_channels: { whatsapp: null, email: null },
}

beforeEach(() => {
  vi.spyOn(onboardingApi, "fetchOnboarding").mockResolvedValue(onboarding)
})
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
    expect(onboardingApi.fetchOnboarding).not.toHaveBeenCalled()
  })

  it("shows the ANEP regime of each level read-only in «Institución», with the way to ask for a correction", async () => {
    vi.spyOn(structureApi, "fetchCurricularOfferings").mockResolvedValue([])
    vi.spyOn(psychopedagogySpaceApi, "fetchMeetingSheetTypes").mockResolvedValue([])
    renderPage("director")

    // It opens on «Oferta curricular»: the regime lives in «Institución».
    expect(screen.queryByRole("heading", { name: "Régimen ANEP por nivel" })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole("tab", { name: "Institución" }))

    expect(await screen.findByRole("heading", { name: "Régimen ANEP por nivel" })).toBeInTheDocument()
    expect(await screen.findByRole("row", { name: /Inicial Autorizado Programa propio/ })).toBeInTheDocument()
    expect(screen.getByRole("row", { name: /Primaria Sin cargar por Aula\+/ })).toBeInTheDocument()
    expect(
      screen.getByRole("row", { name: /Ciclo Básico Habilitado Fijo: todos los colegios privados lo están/ }),
    ).toBeInTheDocument()
    const regimeTable = screen.getByRole("row", { name: /Inicial Autorizado/ }).closest("table") as HTMLElement
    expect(within(regimeTable).queryByRole("textbox")).not.toBeInTheDocument()
    expect(screen.getByRole("link", { name: "¿Algo no coincide con tu colegio?" })).toHaveAttribute(
      "href",
      "/administracion/puesta-en-marcha?solapa=regimen",
    )
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

  it("keeps the offer of a closed year's group for reading only (screen 26)", async () => {
    vi.spyOn(structureApi, "fetchCurricularOfferings").mockResolvedValue([
      { ...row, group: { ...row.group, school_year: 2025, read_only: true } },
    ])
    renderPage("director")

    expect(await screen.findByText("Año cerrado: solo lectura")).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Editar programas" })).not.toBeInTheDocument()
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
    const table = within(screen.getByRole("tabpanel")).getByRole("table")
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
