import { render, screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import type { Role } from "@/types"
import { buildTemplateCsv, parseTemplateCsv } from "./csvTemplate"
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
    const subject = screen.getByText("Calificaciones · Idioma Español").closest("tr") as HTMLElement
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
    // The creator says which grades the subject reads (PO answer of 17/11).
    expect(screen.getByLabelText("Qué calificaciones usa el dato de la condición 2")).toHaveValue("all")
    await userEvent.selectOptions(
      screen.getByLabelText("Qué calificaciones usa el dato de la condición 2"),
      "latest_of_category",
    )
    await userEvent.selectOptions(screen.getByLabelText("Categoría de calificación del dato de la condición 2"), "oral")
    await userEvent.click(screen.getByRole("button", { name: "Ver a quiénes alcanza hoy" }))

    expect(await screen.findByText("Vista previa con los datos de hoy: 3 alumnos de 5.°A · 1 de 5.°B")).toBeInTheDocument()
    expect(preview).toHaveBeenCalledWith([
      { source: { type: "indicator", id: 5 }, operator: "band", value: "Bajo" },
      { source: { type: "subject", id: 3, grades: { rule: "latest_of_category", category: "oral" } }, operator: "level", value: 13 },
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

describe("IndicatorsPage · follow-ups of 17/11", () => {
  const external: api.DataSource = {
    type: "external",
    id: 7,
    name: "Prueba de vocabulario",
    scale: { min: 0, max: 50 },
    coverage: 0,
    number_coverage: 0,
    level_coverage: 0,
    latest_year: null,
    bands: [],
    levels: [],
    taken_every_year: true,
  }
  const math: api.DataSource = {
    type: "subject",
    id: 4,
    name: "Matemática",
    scale: { min: 1, max: 10 },
    coverage: 6,
    number_coverage: 6,
    level_coverage: 0,
    latest_year: 2026,
    bands: [],
    levels: [],
  }

  beforeEach(() => {
    vi.spyOn(api, "fetchIndicatorData").mockResolvedValue({ ...catalog, sources: [...catalog.sources, math, external] })
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("asks which student a CSV row is when the name is repeated, showing each one's code", async () => {
    vi.spyOn(api, "fetchExternalMeasureRoster").mockResolvedValue({
      measure: { id: 7, name: "Prueba de vocabulario", scale_min: 0, scale_max: 50, taken_every_year: true },
      school_year: 2026,
      rows: [],
    })
    const match = vi.spyOn(api, "matchTemplateStudents").mockResolvedValue([
      { status: "matched", student_id: 21, candidates: [] },
      {
        status: "ambiguous",
        student_id: null,
        candidates: [
          { student_id: 31, name: "Sofía Pérez", group_name: "5.°A" },
          { student_id: 32, name: "Sofía Pérez", group_name: "5.°B" },
        ],
      },
      { status: "unknown", student_id: null, candidates: [] },
    ])
    const save = vi
      .spyOn(api, "saveExternalMeasureValues")
      .mockResolvedValue({ saved: 2, cleared: 0, skipped: 0, school_year: 2026 })
    renderPage("psychopedagogue")

    await userEvent.click(await screen.findByRole("button", { name: "Cargar desde Excel (CSV)" }))
    const csv = "id_alumno;alumno;grupo;valor\n21;Lucía;5.°A;40\n;Sofía Pérez;;30\n;Nadie;;10"
    await userEvent.upload(screen.getByLabelText("Archivo CSV con la plantilla completa"), new File([csv], "datos.csv"))

    expect(await screen.findByText("Hay un nombre que se repite. Elegí a qué alumno corresponde la fila.")).toBeInTheDocument()
    expect(match).toHaveBeenCalledWith(7, [
      { student_id: 21, name: "Lucía", group: "5.°A" },
      { student_id: null, name: "Sofía Pérez", group: null },
      { student_id: null, name: "Nadie", group: null },
    ])
    expect(save).not.toHaveBeenCalled()
    const picker = screen.getByLabelText("Alumno de la fila «Sofía Pérez» 1")
    expect(within(picker).getByRole("option", { name: "Sofía Pérez · 5.°A · código 31" })).toBeInTheDocument()
    expect(within(picker).getByRole("option", { name: "Sofía Pérez · 5.°B · código 32" })).toBeInTheDocument()

    // Nothing is loaded until every repeated name is resolved.
    await userEvent.click(screen.getByRole("button", { name: "Cargar" }))
    expect(screen.getByRole("alert")).toHaveTextContent("Elegí a qué alumno corresponde cada fila")
    expect(save).not.toHaveBeenCalled()

    await userEvent.selectOptions(picker, "32")
    await userEvent.click(screen.getByRole("button", { name: "Cargar" }))
    await waitFor(() =>
      expect(save).toHaveBeenCalledWith(7, [
        { student_id: 21, value: 40 },
        { student_id: 32, value: 30 },
      ]),
    )
    expect(await screen.findByText("2 valores cargados · 1 fila con errores.")).toBeInTheDocument()
  })

  it("saves which grades a subject reads in an indicator and shows the rule in the list", async () => {
    const fetchIndicators = vi.spyOn(api, "fetchIndicators").mockResolvedValue([])
    const save = vi.spyOn(api, "saveIndicator").mockResolvedValue(indicator)
    renderPage("director", "/indicadores-y-cruces?solapa=indicadores")

    await userEvent.click(await screen.findByRole("button", { name: "Nuevo indicador" }))
    await userEvent.type(screen.getByLabelText("Nombre"), "Escritos de Matemática")
    await userEvent.click(screen.getByRole("button", { name: "Agregar dato" }))
    await userEvent.selectOptions(screen.getByLabelText("Dato 1"), "subject:4")
    await userEvent.selectOptions(screen.getByLabelText("Qué calificaciones usa el dato 1"), "category_average")
    await userEvent.selectOptions(screen.getByLabelText("Categoría de calificación del dato 1"), "written")
    for (const [index, name] of ["Bajo", "Medio", "Alto"].entries()) {
      await userEvent.type(screen.getByLabelText(`Nombre de la franja ${index + 1}`), name)
    }
    for (const field of screen.getAllByLabelText("Qué significa")) await userEvent.type(field, "Texto.")
    fetchIndicators.mockResolvedValue([
      {
        ...indicator,
        name: "Escritos de Matemática",
        inputs: [{ type: "subject", id: 4, name: "Matemática", grades: { rule: "category_average", category: "written" } }],
      },
    ])
    await userEvent.click(screen.getByRole("button", { name: "Guardar el indicador" }))

    await waitFor(() => expect(save).toHaveBeenCalled())
    expect(save.mock.calls[0][0].inputs).toEqual([
      { type: "subject", id: 4, grades: { rule: "category_average", category: "written" } },
    ])
    expect(
      await screen.findByText(/Calificaciones · Matemática \(promedio del año de las de tipo «Escrita»\)/),
    ).toBeInTheDocument()
  })

  it("tells which crossing to change first when a band it uses cannot be renamed", async () => {
    vi.spyOn(api, "fetchIndicators").mockResolvedValue([indicator])
    vi.spyOn(api, "saveIndicator").mockRejectedValue(
      Object.assign(new Error("422"), {
        isAxiosError: true,
        response: {
          status: 422,
          data: { errors: { bands: ["A band used by a crossing…"], used_by_crossings: ["Lectura baja y atención baja"] } },
        },
      }),
    )
    renderPage("psychopedagogue", "/indicadores-y-cruces?solapa=indicadores")

    await userEvent.click(await screen.findByRole("button", { name: "Editar" }))
    const first = screen.getByLabelText("Nombre de la franja 1")
    await userEvent.clear(first)
    await userEvent.type(first, "Bajo rendimiento")
    await userEvent.click(screen.getByRole("button", { name: "Guardar el indicador" }))

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "El cruce «Lectura baja y atención baja» usa una franja que cambiaste o sacaste. Cambiá primero ese cruce",
    )
  })

  it("refuses repeated band names before asking the server", async () => {
    vi.spyOn(api, "fetchIndicators").mockResolvedValue([indicator])
    const save = vi.spyOn(api, "saveIndicator")
    renderPage("psychopedagogue", "/indicadores-y-cruces?solapa=indicadores")

    await userEvent.click(await screen.findByRole("button", { name: "Editar" }))
    const second = screen.getByLabelText("Nombre de la franja 2")
    await userEvent.clear(second)
    await userEvent.type(second, "Bajo")
    await userEvent.click(screen.getByRole("button", { name: "Guardar el indicador" }))

    expect(screen.getByRole("alert")).toHaveTextContent("Dos franjas no pueden llamarse igual.")
    expect(save).not.toHaveBeenCalled()
  })

  it("shows the grade rule in a crossing's condition", async () => {
    vi.spyOn(api, "fetchCrossings").mockResolvedValue([
      {
        id: 1,
        name: "Oral bajo",
        reading: "El equipo mira la oralidad.",
        conditions: [
          {
            source: { type: "subject", id: 4, name: "Matemática", grades: { rule: "latest", category: null } },
            operator: "below",
            value: 5,
          },
        ],
        preview: { total: 0, groups: [] },
        updated_at: null,
      },
    ])
    renderPage("psychopedagogue", "/indicadores-y-cruces?solapa=cruces")

    expect(
      await screen.findByText("Se cumple si: Calificaciones · Matemática (solo la última calificación del año) menor que 5."),
    ).toBeInTheDocument()
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
    const csv =
      'id_alumno;alumno;grupo;valor\n21;"Pereyra; Lucía";5.°A;42\n22;Federico;5.°A;\nabc;X;5.°A;3\n23;Sofía;5.°B;7,5\n;Juan Gómez;5.°B;6\n;;5.°B;6'
    expect(parseTemplateCsv(csv)).toEqual({
      rows: [
        { student_id: 21, name: "Pereyra; Lucía", group: "5.°A", value: 42 },
        { student_id: 23, name: "Sofía", group: "5.°B", value: 7.5 },
        // No code: the name (and group) say who it is.
        { student_id: null, name: "Juan Gómez", group: "5.°B", value: 6 },
      ],
      bad: 2,
    })
  })

  it("reads a sheet with names and no codes", () => {
    expect(parseTemplateCsv("alumno,valor\nJuan Gómez,8")).toEqual({
      rows: [{ student_id: null, name: "Juan Gómez", group: null, value: 8 }],
      bad: 0,
    })
    expect(parseTemplateCsv("grupo,valor\n5.°A,8")).toEqual({ rows: [], bad: 1 })
  })

  it("builds the template the way Excel in Spanish opens it, and reads it back", () => {
    const csv = buildTemplateCsv([
      { student_id: 21, name: "Martínez; Lucía", group_name: "5.°A", value: 7.5 },
      { student_id: 22, name: "Federico", group_name: "5.°A", value: null },
    ])

    expect(csv.startsWith("\uFEFFid_alumno;alumno;grupo;valor\r\n")).toBe(true)
    expect(csv).toContain('21;"Martínez; Lucía";5.°A;7,5')
    expect(parseTemplateCsv(csv)).toEqual({
      rows: [{ student_id: 21, name: "Martínez; Lucía", group: "5.°A", value: 7.5 }],
      bad: 0,
    })
  })
})
