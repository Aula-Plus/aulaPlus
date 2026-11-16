import { render, screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import type { Role } from "@/types"
import { parseTemplateCsv } from "./csvTemplate"
import * as api from "./indicatorsApi"
import { IndicatorsPage } from "./IndicatorsPage"
import { previewText } from "./indicatorText"

const catalog: api.IndicatorDataCatalog = {
  school_year: 2026,
  roster_count: 6,
  groups: [
    { id: 1, name: "5.°A" },
    { id: 2, name: "5.°B" },
  ],
  sources: [
    {
      type: "screening",
      id: 9,
      name: "Comprensión lectora",
      scale: { min: 0, max: 100 },
      coverage: 6,
      number_coverage: 6,
      level_coverage: 0,
      latest_year: 2026,
      bands: [
        { name: "Inicial", color: "red", max: 39 },
        { name: "Adecuado", color: "green", max: null },
      ],
      levels: [],
      taken_every_year: true,
    },
    {
      type: "subject",
      id: 3,
      name: "Idioma Español",
      scale: { min: 1, max: 10 },
      coverage: 6,
      number_coverage: 0,
      level_coverage: 6,
      latest_year: 2026,
      bands: [],
      levels: [
        { id: 11, name: "Inicial", position: 1 },
        { id: 13, name: "Logrado", position: 3 },
      ],
    },
    {
      type: "indicator",
      id: 5,
      name: "Herramienta de lectura (comprensión)",
      scale: { min: 0, max: 100 },
      coverage: 6,
      number_coverage: 6,
      level_coverage: 0,
      latest_year: null,
      bands: [
        { name: "Bajo", color: "red", max: 39 },
        { name: "Medio", color: "yellow", max: 59 },
        { name: "Esperado", color: "green", max: null },
      ],
      levels: [],
      in_cross_chart: true,
    },
  ],
  assessments: [],
}

const indicator: api.Indicator = {
  id: 5,
  name: "Herramienta de lectura (comprensión)",
  calculation: "raw",
  inputs: [{ type: "screening", id: 9, name: "Comprensión lectora" }],
  base_100: false,
  z_population: null,
  bands: [
    { name: "Bajo", color: "red", max: 39, meaning: "El equipo lo mira de cerca." },
    { name: "Medio", color: "yellow", max: 59, meaning: "Se vuelve a mirar." },
    { name: "Esperado", color: "green", max: null, meaning: "No requiere acción." },
  ],
  display_places: ["cross_chart"],
  teacher_visible: false,
  summary: {
    roster_count: 6,
    students_with_data: 6,
    bands: [
      { name: "Bajo", color: "red", count: 4 },
      { name: "Medio", color: "yellow", count: 0 },
      { name: "Esperado", color: "green", count: 2 },
    ],
    populations: [],
  },
  updated_at: null,
}

function renderPage(role: Role, path = "/indicadores-y-cruces") {
  const value: AuthContextValue = {
    user: { id: 1, name: "Adriana", email: "adriana@escuela.test", roles: [role] },
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
  }
  return render(
    <AuthContext value={value}>
      <MemoryRouter initialEntries={[path]}>
        <IndicatorsPage />
      </MemoryRouter>
    </AuthContext>,
  )
}

describe("IndicatorsPage", () => {
  beforeEach(() => {
    vi.spyOn(api, "fetchIndicatorData").mockResolvedValue(catalog)
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("is closed to teachers without loading anything", () => {
    const fetch = vi.spyOn(api, "fetchIndicatorData")
    renderPage("teacher")

    expect(screen.getByText("Esta pantalla no está disponible para tu cuenta")).toBeInTheDocument()
    expect(fetch).not.toHaveBeenCalled()
  })

  it("shows the four tabs and the available data with scale and coverage", async () => {
    renderPage("psychopedagogue")

    for (const tab of ["Datos", "Indicadores", "Cruces", "Gráfico de cruces"]) {
      expect(screen.getByRole("tab", { name: tab })).toBeInTheDocument()
    }
    const row = (await screen.findByText("Screening · Comprensión lectora")).closest("tr") as HTMLElement
    expect(within(row).getByText("0 a 100")).toBeInTheDocument()
    expect(within(row).getByText("6 alumnos con dato")).toBeInTheDocument()
    const subject = screen.getByText("Promedio del año · Idioma Español").closest("tr") as HTMLElement
    expect(within(subject).getByText("Nivel de avance")).toBeInTheDocument()
    expect(screen.queryByText(/Rúbrica/)).not.toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Descargar la plantilla de Aula+" })).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Cargar desde Excel (CSV)" })).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Cargar a mano" })).toBeInTheDocument()
  })

  it("loads an external datum by hand", async () => {
    vi.spyOn(api, "createExternalMeasure").mockResolvedValue({
      id: 7,
      name: "Prueba de vocabulario",
      scale_min: 0,
      scale_max: 50,
      taken_every_year: true,
    })
    vi.spyOn(api, "fetchExternalMeasureRoster").mockResolvedValue({
      measure: { id: 7, name: "Prueba de vocabulario", scale_min: 0, scale_max: 50, taken_every_year: true },
      school_year: 2026,
      rows: [{ student_id: 21, name: "Lucía Pereyra", group_name: "5.°A", value: null }],
    })
    const save = vi.spyOn(api, "saveExternalMeasureValues").mockResolvedValue({
      saved: 1,
      cleared: 0,
      skipped: 0,
      school_year: 2026,
    })
    renderPage("director")

    await userEvent.click(await screen.findByRole("button", { name: "Cargar a mano" }))
    await userEvent.type(screen.getByLabelText("Nombre del dato"), "Prueba de vocabulario")
    await userEvent.clear(screen.getByLabelText("Valor máximo"))
    await userEvent.type(screen.getByLabelText("Valor máximo"), "50")
    await userEvent.click(screen.getByRole("button", { name: "Crear el dato" }))
    await userEvent.type(await screen.findByLabelText("Valor de Lucía Pereyra"), "42")
    await userEvent.click(screen.getByRole("button", { name: "Guardar" }))

    await waitFor(() => expect(save).toHaveBeenCalledWith(7, [{ student_id: 21, value: 42 }]))
    expect(await screen.findByText("1 valor guardado.")).toBeInTheDocument()
  })

  it("lists the indicators with what they calculate and an aggregate reading of today", async () => {
    vi.spyOn(api, "fetchIndicators").mockResolvedValue([indicator])
    renderPage("psychopedagogue", "/indicadores-y-cruces?solapa=indicadores")

    const row = (await screen.findByText("Herramienta de lectura (comprensión)", { selector: "td" })).closest(
      "tr",
    ) as HTMLElement
    expect(within(row).getByText(/Dato tal cual: Screening · Comprensión lectora/)).toBeInTheDocument()
    expect(within(row).getByText("Bajo · Medio · Esperado")).toBeInTheDocument()
    expect(within(row).getByText("Psicopedagogía y Dirección")).toBeInTheDocument()
    expect(within(row).getByText(/6 de 6 alumnos con dato: Bajo 4 · Medio 0 · Esperado 2/)).toBeInTheDocument()
  })

  it("builds an indicator with three bands and their meaning", async () => {
    vi.spyOn(api, "fetchIndicators").mockResolvedValue([])
    const preview = vi.spyOn(api, "previewIndicator").mockResolvedValue(indicator.summary)
    const save = vi.spyOn(api, "saveIndicator").mockResolvedValue(indicator)
    renderPage("psychopedagogue", "/indicadores-y-cruces?solapa=indicadores")

    await userEvent.click(await screen.findByRole("button", { name: "Nuevo indicador" }))
    await userEvent.type(screen.getByLabelText("Nombre"), "Lectura")
    await userEvent.click(screen.getByRole("button", { name: "Agregar dato" }))
    const names = ["Bajo", "Medio", "Esperado"]
    for (const [index, name] of names.entries()) {
      await userEvent.type(screen.getByLabelText(`Nombre de la franja ${index + 1}`), name)
    }

    // «Qué significa» is mandatory.
    await userEvent.click(screen.getByRole("button", { name: "Guardar el indicador" }))
    expect(screen.getByRole("alert")).toHaveTextContent("«Qué significa» es obligatorio")
    expect(save).not.toHaveBeenCalled()

    const meanings = screen.getAllByLabelText("Qué significa")
    for (const field of meanings) await userEvent.type(field, "Texto del colegio.")

    await userEvent.click(screen.getByRole("button", { name: "Ver cómo queda con los datos de hoy" }))
    expect(await screen.findByText("Así queda con los datos de hoy")).toBeInTheDocument()
    expect(preview).toHaveBeenCalled()

    await userEvent.click(screen.getByRole("button", { name: "Guardar el indicador" }))
    await waitFor(() => expect(save).toHaveBeenCalled())
    const [payload] = save.mock.calls[0]
    expect(payload).toMatchObject({
      name: "Lectura",
      calculation: "raw",
      inputs: [{ type: "screening", id: 9 }],
      teacher_visible: false,
      z_population: null,
    })
    expect(payload.bands.map((band) => [band.name, band.max])).toEqual([
      ["Bajo", 39],
      ["Medio", 59],
      ["Esperado", null],
    ])
  })

  it("previews a crossing counted per group, without names", async () => {
    vi.spyOn(api, "fetchCrossings").mockResolvedValue([])
    const preview = vi
      .spyOn(api, "previewCrossing")
      .mockResolvedValue({ total: 4, groups: [{ group_id: 1, group_name: "5.°A", count: 3 }, { group_id: 2, group_name: "5.°B", count: 1 }] })
    renderPage("psychopedagogue", "/indicadores-y-cruces?solapa=cruces")

    await userEvent.click(await screen.findByRole("button", { name: "Nuevo cruce" }))
    // Starts on the first indicator, by band.
    expect(screen.getByLabelText("Dato de la condición 1")).toHaveValue("indicator:5")
    await userEvent.selectOptions(screen.getByLabelText("Franja de la condición 1"), "Bajo")
    await userEvent.click(screen.getByRole("button", { name: "Agregar condición" }))
    await userEvent.selectOptions(screen.getByLabelText("Dato de la condición 2"), "subject:3")
    expect(screen.getByLabelText("Cómo se lee la condición 2")).toHaveValue("level")
    await userEvent.selectOptions(screen.getByLabelText("Nivel de la condición 2"), "13")
    await userEvent.click(screen.getByRole("button", { name: "Ver a quiénes alcanza hoy" }))

    expect(await screen.findByText("Vista previa con los datos de hoy: 3 alumnos de 5.°A · 1 de 5.°B")).toBeInTheDocument()
    expect(preview).toHaveBeenCalledWith([
      { source: { type: "indicator", id: 5 }, operator: "band", value: "Bajo" },
      { source: { type: "subject", id: 3 }, operator: "level", value: 13 },
    ])
  })

  it("draws one point per student with the name only in the hover title, plus the band cuts", async () => {
    vi.spyOn(api, "fetchChart").mockResolvedValue({
      x: {
        type: "indicator",
        id: 5,
        name: "Herramienta de lectura (comprensión)",
        kind: "number",
        scale: { min: 0, max: 100 },
        cuts: [39, 59],
        bands: [],
        levels: [],
      },
      y: {
        type: "screening",
        id: 9,
        name: "Comprensión lectora",
        kind: "number",
        scale: { min: 0, max: 100 },
        cuts: [39],
        bands: [],
        levels: [],
      },
      points: [
        { name: "Lucía Pereyra", group_name: "5.°A", x: 20, x_label: "Bajo", x_population: null, y: 20, y_label: "Inicial", y_population: null },
        { name: "Sofía Ramírez", group_name: "5.°B", x: 90, x_label: "Esperado", x_population: null, y: 90, y_label: "Adecuado", y_population: null },
      ],
    })
    renderPage("director", "/indicadores-y-cruces?solapa=grafico")

    const points = await screen.findAllByTestId("chart-point")
    expect(points).toHaveLength(2)
    expect(points[0].querySelector("title")?.textContent).toContain("Lucía Pereyra · 5.°A")
    expect(screen.getAllByTestId("band-cut")).toHaveLength(3)
    // No ranked list of students anywhere.
    expect(screen.queryByRole("table")).not.toBeInTheDocument()
    expect(screen.getByRole("option", { name: "Todo el colegio" })).toBeInTheDocument()
  })
})

describe("screen 30 helpers", () => {
  it("formats the preview as counts per group", () => {
    expect(previewText({ total: 0, groups: [] })).toBe("Hoy no le aparece a ningún alumno")
    expect(
      previewText({
        total: 2,
        groups: [
          { group_id: 1, group_name: "5.°A", count: 1 },
          { group_id: 2, group_name: "5.°B", count: 1 },
        ],
      }),
    ).toBe("1 alumno de 5.°A · 1 de 5.°B")
  })

  it("reads the template CSV and counts bad rows", () => {
    const csv = 'id_alumno;alumno;grupo;valor\n21;"Pereyra; Lucía";5.°A;42\n22;Federico;5.°A;\nabc;X;5.°A;3\n23;Sofía;5.°B;7,5'
    expect(parseTemplateCsv(csv)).toEqual({
      values: [
        { student_id: 21, value: 42 },
        { student_id: 23, value: 7.5 },
      ],
      bad: 1,
    })
  })
})
