import { render, screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import { ScreeningTestDesignPage } from "./ScreeningTestDesignPage"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import * as screeningApi from "./screeningTestsApi"
import type { Role, ScreeningBand, ScreeningTestDesign, ScreeningTestType } from "@/types"

const bandsNow: ScreeningBand[] = [
  { name: "Descendido", color: "red", max: 34, meaning: "Conviene revisarlo con el equipo." },
  { name: "En proceso", color: "yellow", max: 64, meaning: "Se vuelve a mirar en la próxima toma." },
  { name: "Adecuado", color: "green", max: 100, meaning: "No requiere acción." },
]

const bandsBefore: ScreeningBand[] = [
  { name: "Descendido", color: "red", max: 34, meaning: "Conviene revisarlo con el equipo." },
  { name: "En proceso", color: "yellow", max: 59, meaning: "Se vuelve a mirar en la próxima toma." },
  { name: "Adecuado", color: "green", max: 100, meaning: "No requiere acción." },
]

function design(id: number, bands: ScreeningBand[], overrides: Partial<ScreeningTestDesign> = {}): ScreeningTestDesign {
  return {
    id,
    screening_test_type_id: 1,
    bands,
    reason: null,
    approved: true,
    created_by_id: 2,
    created_by_name: "Laura Ferrando",
    reverted_at: null,
    reverted_by_id: null,
    created_at: "2026-08-20T10:00:00Z",
    ...overrides,
  }
}

function type(overrides: Partial<ScreeningTestType> = {}): ScreeningTestType {
  return {
    id: 1,
    name: "Prueba de atención",
    area: "Atención",
    levels: ["lower_secondary", "upper_secondary"],
    frequency: "twice_yearly",
    frequency_years: null,
    teacher_visible: false,
    active: true,
    created_by_id: 2,
    current_design: null,
    previous_design: null,
    ...overrides,
  }
}

function renderPage(role: Role = "psychopedagogue", path = "/pruebas-de-sondeo/tipos") {
  const value: AuthContextValue = {
    user: { id: 1, name: "Ana", email: "ana@escuela.test", roles: [role] },
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
  }
  return render(
    <AuthContext value={value}>
      <MemoryRouter initialEntries={[path]}>
        <ScreeningTestDesignPage />
      </MemoryRouter>
    </AuthContext>,
  )
}

describe("ScreeningTestDesignPage", () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("lists the school's screenings with area, population, frequency and who sees results", async () => {
    vi.spyOn(screeningApi, "fetchScreeningTestTypes").mockResolvedValue([
      type({ current_design: design(10, bandsNow) }),
    ])

    renderPage()

    expect(await screen.findByText("Prueba de atención")).toBeInTheDocument()
    expect(screen.getByText("Dos veces por año")).toBeInTheDocument()
    expect(screen.getByText("Ciclo Básico, Bachillerato")).toBeInTheDocument()
    expect(screen.getByText("Vigente")).toBeInTheDocument()
    expect(screen.getByText("Psicopedagogía y Dirección")).toBeInTheDocument()
  })

  it("lets psychopedagogy create a screening with the new naming", async () => {
    vi.spyOn(screeningApi, "fetchScreeningTestTypes").mockResolvedValue([])
    const create = vi.spyOn(screeningApi, "createScreeningTestType").mockResolvedValue(type())

    renderPage()

    await userEvent.type(await screen.findByLabelText("Nombre del screening"), "Cálculo mental")
    await userEvent.click(screen.getByRole("button", { name: "Crear screening" }))

    await waitFor(() => expect(create).toHaveBeenCalledWith({ name: "Cálculo mental", area: null }))
  })

  it("does not offer creating a screening to Dirección, but lets it open and edit one", async () => {
    vi.spyOn(screeningApi, "fetchScreeningTestTypes").mockResolvedValue([type()])

    renderPage("director")

    await userEvent.click(await screen.findByRole("button", { name: "Abrir y editar" }))
    expect(screen.queryByRole("button", { name: "Crear screening" })).not.toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Guardar datos generales" })).toBeInTheDocument()
  })

  it("saves the general data: population, frequency and teacher access", async () => {
    vi.spyOn(screeningApi, "fetchScreeningTestTypes").mockResolvedValue([type()])
    const update = vi.spyOn(screeningApi, "updateScreeningTestType").mockResolvedValue(type())

    renderPage("psychopedagogue", "/pruebas-de-sondeo/tipos?abrir=1")

    await userEvent.click(await screen.findByLabelText("Primaria"))
    await userEvent.selectOptions(screen.getByLabelText("Cada cuánto se toma"), "every_few_years")
    await userEvent.type(screen.getByLabelText("Cada cuántos años"), "3")
    await userEvent.click(screen.getByLabelText("Abrirlo al docente"))
    await userEvent.click(screen.getByRole("button", { name: "Guardar datos generales" }))

    await waitFor(() =>
      expect(update).toHaveBeenCalledWith(1, {
        name: "Prueba de atención",
        area: "Atención",
        levels: ["primary", "lower_secondary", "upper_secondary"],
        frequency: "every_few_years",
        frequency_years: 3,
        teacher_visible: true,
      }),
    )
  })

  it("requires the meaning of every band before saving", async () => {
    vi.spyOn(screeningApi, "fetchScreeningTestTypes").mockResolvedValue([type()])
    const create = vi.spyOn(screeningApi, "createScreeningTestDesign")

    renderPage("psychopedagogue", "/pruebas-de-sondeo/tipos?abrir=1")

    await userEvent.type(await screen.findByLabelText("Nombre", { selector: "#band-1-0-name" }), "Bajo")
    await userEvent.click(screen.getByRole("button", { name: "Guardar" }))

    expect((await screen.findAllByText("Qué significa es obligatorio")).length).toBeGreaterThan(0)
    expect(create).not.toHaveBeenCalled()
  })

  it("previews the impact and saves bands that rule right away", async () => {
    vi.spyOn(screeningApi, "fetchScreeningTestTypes").mockResolvedValue([
      type({ current_design: design(10, bandsBefore) }),
    ])
    const preview = vi.spyOn(screeningApi, "previewScreeningTestImpact").mockResolvedValue({
      students: 94,
      current: [
        { name: "Descendido", color: "red", max: 34, count: 1 },
        { name: "En proceso", color: "yellow", max: 59, count: 35 },
        { name: "Adecuado", color: "green", max: 100, count: 58 },
      ],
      proposed: [
        { name: "Descendido", color: "red", max: 34, count: 1 },
        { name: "En proceso", color: "yellow", max: 64, count: 50 },
        { name: "Adecuado", color: "green", max: 100, count: 43 },
      ],
    })
    const create = vi.spyOn(screeningApi, "createScreeningTestDesign").mockResolvedValue(design(11, bandsNow))

    renderPage("psychopedagogue", "/pruebas-de-sondeo/tipos?abrir=1")

    const max = await screen.findByLabelText("Hasta", { selector: "#band-1-1-max" })
    await userEvent.clear(max)
    await userEvent.type(max, "64")
    await userEvent.click(screen.getByRole("button", { name: "Ver cómo queda el grupo con este corte" }))

    await waitFor(() => expect(preview).toHaveBeenCalledWith(1, bandsNow))
    const proposed = (await screen.findByText("Con el corte nuevo")).closest("div")!
    expect(within(proposed).getByText("50")).toBeInTheDocument()
    expect(screen.getByText(/Alumnos con resultado: 94/)).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText("Motivo del cambio (opcional)"), "Subir el corte")
    await userEvent.click(screen.getByRole("button", { name: "Guardar" }))

    await waitFor(() =>
      expect(create).toHaveBeenCalledWith(1, { bands: bandsNow, reason: "Subir el corte" }),
    )
  })

  it("shows the change that already rules and lets only Dirección go back", async () => {
    vi.spyOn(screeningApi, "fetchScreeningTestTypes").mockResolvedValue([
      type({
        current_design: design(11, bandsNow, { reason: "Subir el corte de Adecuado" }),
        previous_design: design(10, bandsBefore),
      }),
    ])
    vi.spyOn(screeningApi, "previewScreeningTestImpact").mockResolvedValue({
      students: 94,
      current: [
        { name: "Descendido", color: "red", max: 34, count: 1 },
        { name: "En proceso", color: "yellow", max: 64, count: 50 },
        { name: "Adecuado", color: "green", max: 100, count: 43 },
      ],
      proposed: [
        { name: "Descendido", color: "red", max: 34, count: 1 },
        { name: "En proceso", color: "yellow", max: 59, count: 35 },
        { name: "Adecuado", color: "green", max: 100, count: 58 },
      ],
    })
    const revert = vi.spyOn(screeningApi, "revertScreeningTestDesign").mockResolvedValue(design(11, bandsNow))

    const { unmount } = renderPage("psychopedagogue", "/pruebas-de-sondeo/tipos?abrir=1")
    expect(await screen.findByText("Prueba de atención · cambio que ya rige")).toBeInTheDocument()
    expect(await screen.findByText(/En proceso · más de 34 hasta 59 · 35/)).toBeInTheDocument()
    expect(screen.getByText(/En proceso · más de 34 hasta 64 · 50/)).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Volver atrás" })).not.toBeInTheDocument()
    unmount()

    renderPage("director", "/pruebas-de-sondeo/tipos?abrir=1")
    await userEvent.click(await screen.findByRole("button", { name: "Volver atrás" }))
    const dialog = await screen.findByRole("dialog")
    await userEvent.click(within(dialog).getByRole("button", { name: "Volver atrás" }))

    await waitFor(() => expect(revert).toHaveBeenCalledWith(11))
  })
})
