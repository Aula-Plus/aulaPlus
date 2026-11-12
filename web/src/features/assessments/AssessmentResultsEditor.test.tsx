import { render, screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, describe, expect, it, vi } from "vitest"
import type { Assessment, AssessmentResult, AssessmentResultScale, OfficialScale } from "@/types"
import { AssessmentResultsEditor } from "./AssessmentResultsEditor"
import * as assessmentsApi from "./assessmentsApi"

const SCALE: OfficialScale = {
  id: 1,
  framework: { id: 1, name: "ANEP EBI" },
  education_level: { key: "primary", label: "Primaria" },
  name: "Educación Primaria · Educación Básica Integrada · 1.er y 2.º ciclo",
  issuing_body: "ANEP",
  citation: { document: "REDE 2024, artículo 21", page: "14", url: null },
  levels: [
    { id: 11, position: 1, name: "Avance destacado", description: null, official_band: null },
    { id: 12, position: 2, name: "Avance significativo", description: null, official_band: null },
    { id: 13, position: 3, name: "Avance moderado", description: null, official_band: null },
  ],
}

function scaleMode(
  numeric: Extract<AssessmentResultScale, { mode: "scale" }>["numeric_grade"],
): AssessmentResultScale {
  return { mode: "scale", scale: SCALE, numeric_grade: numeric }
}

const assessment = { id: 10, group_id: 1 } as Assessment
const students = [
  { id: 3, full_name: "Juan Pérez", open_alerts_count: 0 },
  { id: 4, full_name: "Ana Gómez", open_alerts_count: 0 },
]

function levelResult(patch: Partial<AssessmentResult>): AssessmentResult {
  return {
    id: 1,
    assessment_id: 10,
    student_id: 3,
    score: null,
    scale_level: { id: 12, position: 2, name: "Avance significativo" },
    numeric_grade: null,
    numeric_grade_source: null,
    feedback: null,
    created_by_id: 1,
    created_at: null,
    updated_at: null,
    ...patch,
  }
}

afterEach(() => vi.restoreAllMocks())

describe("AssessmentResultsEditor with an official scale (Primaria)", () => {
  it("loads 5.°A results with a level of the scale, in order, and no number when the switch is off", async () => {
    vi.spyOn(assessmentsApi, "fetchAssessmentResults").mockResolvedValue([])
    vi.spyOn(assessmentsApi, "fetchAssessmentResultScale").mockResolvedValue(
      scaleMode({ shown: false, source: null, equivalences: [] }),
    )
    const save = vi.spyOn(assessmentsApi, "saveAssessmentResults").mockResolvedValue([])

    render(<AssessmentResultsEditor assessment={assessment} students={students} canManage />)

    const select = await screen.findByLabelText("Nivel de avance de Juan Pérez")
    expect(within(select).getAllByRole("option").map((o) => o.textContent)).toEqual([
      "—",
      "Avance destacado",
      "Avance significativo",
      "Avance moderado",
    ])
    expect(screen.getByText(/REDE 2024, artículo 21/)).toBeInTheDocument()
    expect(screen.queryByLabelText(/nota de juan pérez/i)).not.toBeInTheDocument()
    expect(screen.queryByLabelText(/calificación de juan pérez/i)).not.toBeInTheDocument()

    await userEvent.selectOptions(select, "12")
    await userEvent.click(screen.getByRole("button", { name: /guardar calificaciones/i }))

    expect(save).toHaveBeenCalledWith(10, [{ student_id: 3, scale_level_id: 12, feedback: null }])
  })

  it("lets the teacher write the number when the school shows it and has no equivalence", async () => {
    vi.spyOn(assessmentsApi, "fetchAssessmentResults").mockResolvedValue([])
    vi.spyOn(assessmentsApi, "fetchAssessmentResultScale").mockResolvedValue(
      scaleMode({ shown: true, source: "teacher", equivalences: [] }),
    )
    const save = vi.spyOn(assessmentsApi, "saveAssessmentResults").mockResolvedValue([])

    render(<AssessmentResultsEditor assessment={assessment} students={students} canManage />)

    await userEvent.selectOptions(await screen.findByLabelText("Nivel de avance de Juan Pérez"), "11")
    await userEvent.selectOptions(screen.getByLabelText("Nota de Juan Pérez (opcional)"), "9")
    await userEvent.selectOptions(screen.getByLabelText("Nivel de avance de Ana Gómez"), "13")
    await userEvent.click(screen.getByRole("button", { name: /guardar calificaciones/i }))

    expect(save).toHaveBeenCalledWith(10, [
      { student_id: 3, scale_level_id: 11, numeric_grade: 9, feedback: null },
      { student_id: 4, scale_level_id: 13, numeric_grade: null, feedback: null },
    ])
  })

  it("shows the school's equivalence next to the level and never asks the teacher for a number", async () => {
    vi.spyOn(assessmentsApi, "fetchAssessmentResults").mockResolvedValue([])
    vi.spyOn(assessmentsApi, "fetchAssessmentResultScale").mockResolvedValue(
      scaleMode({
        shown: true,
        source: "school",
        equivalences: [
          { level_id: 11, numeric_grade: 10 },
          { level_id: 12, numeric_grade: 8 },
          { level_id: 13, numeric_grade: 6 },
        ],
      }),
    )

    render(<AssessmentResultsEditor assessment={assessment} students={students} canManage />)

    await userEvent.selectOptions(await screen.findByLabelText("Nivel de avance de Juan Pérez"), "12")
    expect(screen.getByText("8")).toBeInTheDocument()
    expect(screen.getByText(/equivalencia del colegio/i)).toBeInTheDocument()
    expect(screen.queryByLabelText(/nota de juan pérez/i)).not.toBeInTheDocument()
  })

  it("read-only: shows the level with the allowed number, or only the level", async () => {
    vi.spyOn(assessmentsApi, "fetchAssessmentResults").mockResolvedValue([
      levelResult({ student_id: 3, numeric_grade: 8, numeric_grade_source: "teacher" }),
      levelResult({ id: 2, student_id: 4, scale_level: { id: 13, position: 3, name: "Avance moderado" } }),
    ])
    vi.spyOn(assessmentsApi, "fetchAssessmentResultScale").mockResolvedValue(
      scaleMode({ shown: true, source: "teacher", equivalences: [] }),
    )

    render(<AssessmentResultsEditor assessment={assessment} students={students} canManage={false} />)

    expect(await screen.findByText("Avance significativo · 8")).toBeInTheDocument()
    // Switch on, no equivalence, no number → the level with a discreet «sin nota».
    expect(screen.getByText("Avance moderado")).toBeInTheDocument()
    expect(screen.getByText("sin nota")).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /guardar calificaciones/i })).not.toBeInTheDocument()
  })

  it("keeps a result recorded as a score visible and only replaces it on purpose", async () => {
    vi.spyOn(assessmentsApi, "fetchAssessmentResults").mockResolvedValue([
      levelResult({ student_id: 3, score: 7, scale_level: null }),
    ])
    vi.spyOn(assessmentsApi, "fetchAssessmentResultScale").mockResolvedValue(
      scaleMode({ shown: false, source: null, equivalences: [] }),
    )
    const save = vi.spyOn(assessmentsApi, "saveAssessmentResults").mockResolvedValue([])

    render(<AssessmentResultsEditor assessment={assessment} students={students} canManage />)

    expect(await screen.findByText("Registrado antes como nota 7")).toBeInTheDocument()
    expect(screen.queryByLabelText("Nivel de avance de Juan Pérez")).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole("button", { name: "Reemplazar el resultado de Juan Pérez" }))
    await userEvent.selectOptions(screen.getByLabelText("Nivel de avance de Juan Pérez"), "11")
    await userEvent.click(screen.getByRole("button", { name: /guardar calificaciones/i }))

    expect(save).toHaveBeenCalledWith(10, [
      { student_id: 3, scale_level_id: 11, replace_previous: true, feedback: null },
    ])
  })

  it("never resends a result recorded the other way that was not replaced", async () => {
    vi.spyOn(assessmentsApi, "fetchAssessmentResults").mockResolvedValue([
      levelResult({ student_id: 3, score: 7, scale_level: null }),
    ])
    vi.spyOn(assessmentsApi, "fetchAssessmentResultScale").mockResolvedValue(
      scaleMode({ shown: false, source: null, equivalences: [] }),
    )
    const save = vi.spyOn(assessmentsApi, "saveAssessmentResults").mockResolvedValue([])

    render(<AssessmentResultsEditor assessment={assessment} students={students} canManage />)

    await userEvent.selectOptions(await screen.findByLabelText("Nivel de avance de Ana Gómez"), "12")
    await userEvent.click(screen.getByRole("button", { name: /guardar calificaciones/i }))

    expect(save).toHaveBeenCalledWith(10, [{ student_id: 4, scale_level_id: 12, feedback: null }])
  })

  it("makes the teacher choose the scale first when the group has two", async () => {
    const pyp: OfficialScale = { ...SCALE, id: 2, framework: { id: 2, name: "IB PYP" }, name: "PYP" }
    vi.spyOn(assessmentsApi, "fetchAssessmentResults").mockResolvedValue([])
    vi.spyOn(assessmentsApi, "fetchAssessmentResultScale").mockResolvedValue({
      mode: "choose",
      scale: null,
      numeric_grade: null,
      choices: [SCALE, pyp],
    })
    const choose = vi
      .spyOn(assessmentsApi, "chooseAssessmentResultScale")
      .mockResolvedValue({ mode: "scale", scale: pyp, numeric_grade: { shown: false, source: null, equivalences: [] } })

    render(<AssessmentResultsEditor assessment={assessment} students={students} canManage />)

    const select = await screen.findByLabelText("Escala de esta evaluación")
    expect(screen.queryByLabelText("Nivel de avance de Juan Pérez")).not.toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Usar esta escala" })).toBeDisabled()

    await userEvent.selectOptions(select, "2")
    await userEvent.click(screen.getByRole("button", { name: "Usar esta escala" }))

    expect(choose).toHaveBeenCalledWith(10, 2)
    expect(await screen.findByLabelText("Nivel de avance de Juan Pérez")).toBeInTheDocument()
    expect(screen.getByText(/Se registra con la escala IB PYP/)).toBeInTheDocument()
  })

  it("read-only: says the scale is not chosen yet", async () => {
    vi.spyOn(assessmentsApi, "fetchAssessmentResults").mockResolvedValue([])
    vi.spyOn(assessmentsApi, "fetchAssessmentResultScale").mockResolvedValue({
      mode: "choose",
      scale: null,
      numeric_grade: null,
      choices: [SCALE],
    })

    render(<AssessmentResultsEditor assessment={assessment} students={students} canManage={false} />)

    expect(await screen.findByText(/Todavía no se eligió con qué escala/)).toBeInTheDocument()
    expect(screen.queryByLabelText("Escala de esta evaluación")).not.toBeInTheDocument()
  })
})
