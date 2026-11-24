import { act, fireEvent, render, screen, waitFor } from "@testing-library/react"
import { MemoryRouter } from "react-router-dom"
import userEvent from "@testing-library/user-event"
import { afterEach, describe, expect, it, vi } from "vitest"
import { AuthContext } from "@/features/auth/AuthContext"
import * as groupsApi from "@/features/groups/groupsApi"
import type { AIInstructionType, SchoolAIInstruction, User } from "@/types"
import { OfficialScalesPage } from "./OfficialScalesPage"
import * as aiInstructionsApi from "./aiInstructionsApi"
import { PREVIEW_MAX_POLLS, PREVIEW_POLL_MS } from "./JudgmentPreviewCard"

const LEVELS = {
  data: [
    {
      id: 2,
      key: "primary",
      label: "Primaria",
      grades: [
        { id: 21, key: "p1", label: "1° de Primaria" },
        { id: 22, key: "p2", label: "2° de Primaria" },
        { id: 23, key: "p3", label: "3° de Primaria" },
      ],
    },
  ],
  groups_without_level: 0,
}

function rule(overrides: Partial<SchoolAIInstruction> = {}): SchoolAIInstruction {
  return {
    id: 1,
    request_type: "judgment",
    education_level_id: null,
    school_grade_ids: [],
    settings: { tone: "formal" },
    prompt_text: "Reglas fijas de Aula+\n\nINDICACIONES DEL COLEGIO PARA LOS JUICIOS\n- Tono: Formal.",
    updated_at: null,
    ...overrides,
  }
}

function types(judgmentRules: SchoolAIInstruction[] = []): AIInstructionType[] {
  return [
    { request_type: "judgment", configurable: true, default_prompt_text: "Base fija de Aula+", rules: judgmentRules },
    { request_type: "assessment", configurable: true, default_prompt_text: "Base de evaluaciones", rules: [] },
    { request_type: "annual_plan", configurable: false, default_prompt_text: null, rules: [] },
    { request_type: "unit", configurable: false, default_prompt_text: null, rules: [] },
    { request_type: "class_session", configurable: false, default_prompt_text: null, rules: [] },
    { request_type: "report_summary", configurable: false, default_prompt_text: null, rules: [] },
  ]
}

function renderPage(path = "/") {
  const user = { id: 1, name: "Dir", email: "d@x.uy", school_id: 1, roles: ["director"] } as unknown as User
  return render(
    <AuthContext.Provider value={{ user, loading: false, login: vi.fn(), logout: vi.fn() } as never}>
      <MemoryRouter initialEntries={[path]}>
        <OfficialScalesPage />
      </MemoryRouter>
    </AuthContext.Provider>,
  )
}

function mockLoad(judgmentRules: SchoolAIInstruction[] = []) {
  vi.spyOn(aiInstructionsApi, "fetchAIInstructions").mockResolvedValue(types(judgmentRules))
  vi.spyOn(aiInstructionsApi, "fetchJudgmentPreviewOptions").mockResolvedValue([
    { id: 5, name: "5.°A", school_year: 2026, subjects: [{ id: 9, name: "Matemática" }] },
  ])
  vi.spyOn(groupsApi, "fetchEducationLevels").mockResolvedValue(LEVELS)
}

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

describe("Evaluación y juicios · «Juicios» and «Evaluaciones» (ClickUp 1dfd3mc4bh6)", () => {
  it("opens on «Juicios» with the institution rule, the scoped rules and «Así le llega a la IA»", async () => {
    mockLoad([rule(), rule({ id: 2, education_level_id: 2, school_grade_ids: [21, 22, 23], settings: {} })])
    renderPage()

    expect(await screen.findByText("Cómo querés que escriba los juicios")).toBeInTheDocument()
    expect(screen.getByRole("tab", { name: "Toda la institución" })).toHaveAttribute("aria-selected", "true")
    expect(screen.getByRole("tab", { name: "De 1° de Primaria a 3° de Primaria" })).toBeInTheDocument()
    expect(screen.getByLabelText("Tono")).toHaveValue("formal")
    expect(screen.getByText(/INDICACIONES DEL COLEGIO PARA LOS JUICIOS/)).toBeInTheDocument()
    // The other kinds of request have their place, empty for now.
    expect(screen.getByText("Planificación anual")).toBeInTheDocument()
    expect(screen.getByText("Resumen de informe")).toBeInTheDocument()
  })

  it("creates the institution rule on the first change and saves on its own", async () => {
    mockLoad()
    const create = vi.spyOn(aiInstructionsApi, "createAIInstruction").mockResolvedValue(rule())
    renderPage()

    const goal = await screen.findByLabelText("Qué querés lograr con los juicios")
    vi.useFakeTimers()
    fireEvent.change(goal, { target: { value: "Nombrar un logro." } })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(800)
    })

    expect(create).toHaveBeenCalledWith(
      expect.objectContaining({
        request_type: "judgment",
        education_level_id: null,
        settings: expect.objectContaining({ goal: "Nombrar un logro." }),
      }),
    )
    expect(screen.getByRole("status")).toHaveTextContent("Guardado")
  })

  it("adds a rule for a range of grades of a level", async () => {
    mockLoad()
    const create = vi
      .spyOn(aiInstructionsApi, "createAIInstruction")
      .mockResolvedValue(rule({ id: 3, education_level_id: 2, school_grade_ids: [21, 22], settings: {} }))
    renderPage()

    await userEvent.click(await screen.findByRole("button", { name: "+ Nueva regla para un nivel o rango" }))
    await userEvent.selectOptions(screen.getByLabelText("Nivel"), "2")
    await userEvent.selectOptions(screen.getByLabelText("Desde"), "21")
    await userEvent.selectOptions(screen.getByLabelText("Hasta"), "22")
    await userEvent.click(screen.getByRole("button", { name: "Crear regla" }))

    expect(create).toHaveBeenCalledWith({
      request_type: "judgment",
      education_level_id: 2,
      school_grade_ids: [21, 22],
    })
    expect(await screen.findByRole("tab", { name: "De 1° de Primaria a 2° de Primaria" })).toHaveAttribute(
      "aria-selected",
      "true",
    )
  })

  it("asks for «Ver ejemplo» only on demand, for the chosen group and subject", async () => {
    mockLoad()
    const request = vi
      .spyOn(aiInstructionsApi, "requestJudgmentPreview")
      .mockResolvedValue({ id: 7, status: "completed", draft: "Sofía avanzó en fracciones.", error: false })
    renderPage()

    expect(await screen.findByText("Así quedaría un juicio con tu estilo (alumno de ejemplo)")).toBeInTheDocument()
    await waitFor(() => expect(screen.getByLabelText("Grupo y materia")).not.toBeDisabled())
    expect(request).not.toHaveBeenCalled()

    await userEvent.selectOptions(screen.getByLabelText("Grupo y materia"), "5:9")
    await userEvent.click(screen.getByRole("button", { name: "Ver ejemplo" }))

    expect(request).toHaveBeenCalledWith({ group_id: 5, subject_id: 9 })
    expect(await screen.findByText("Sofía avanzó en fracciones.")).toBeInTheDocument()
  })

  it("shows the assessment fields and what the indication does not change", async () => {
    mockLoad()
    renderPage("/?solapa=evaluaciones")

    expect(await screen.findByText("Cómo querés que arme las evaluaciones")).toBeInTheDocument()
    expect(screen.getByLabelText("Palabras que no van en las consignas")).toBeInTheDocument()
    expect(screen.getByText(/No cambia la escala, los criterios ni la regla de una sola evaluación accesible/)).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "+ Nueva regla para un nivel o grado" })).toBeInTheDocument()
    expect(screen.queryByText(/alumno de ejemplo/)).not.toBeInTheDocument()
  })
  it("still saves the last change when Direction switches to another rule before it was saved", async () => {
    mockLoad([rule(), rule({ id: 2, education_level_id: 2, school_grade_ids: [], settings: { tone: "close" } })])
    const update = vi.spyOn(aiInstructionsApi, "updateAIInstruction").mockResolvedValue(rule())
    renderPage()

    const levelTab = await screen.findByRole("tab", { name: "Todo Primaria" })
    const goal = screen.getByLabelText("Qué querés lograr con los juicios")
    fireEvent.change(goal, { target: { value: "Nombrar un logro." } })
    fireEvent.click(levelTab)

    await waitFor(() =>
      expect(update).toHaveBeenCalledWith(1, expect.objectContaining({ goal: "Nombrar un logro." })),
    )
    expect(update).toHaveBeenCalledTimes(1)
  })

  it("says the rule could not be deleted, not that a change could not be saved", async () => {
    mockLoad([rule(), rule({ id: 2, education_level_id: 2, school_grade_ids: [], settings: { tone: "close" } })])
    vi.spyOn(aiInstructionsApi, "deleteAIInstruction").mockRejectedValue(new Error("boom"))
    renderPage()

    await userEvent.click(await screen.findByRole("tab", { name: "Todo Primaria" }))
    await userEvent.click(screen.getByRole("button", { name: "Borrar esta regla" }))
    await userEvent.click(screen.getByRole("button", { name: "Borrar" }))

    expect(await screen.findByText("No pudimos borrar la regla. Probá de nuevo.")).toBeInTheDocument()
    expect(screen.queryByText(/No pudimos guardar el último cambio/)).not.toBeInTheDocument()
  })

  it("stops waiting for «Ver ejemplo» after a while and lets Direction ask again", async () => {
    mockLoad()
    vi.spyOn(aiInstructionsApi, "requestJudgmentPreview").mockResolvedValue({
      id: 7, status: "pending", draft: null, error: false,
    })
    const fetchPreview = vi
      .spyOn(aiInstructionsApi, "fetchJudgmentPreview")
      .mockResolvedValue({ id: 7, status: "pending", draft: null, error: false })
    renderPage()

    await waitFor(() => expect(screen.getByLabelText("Grupo y materia")).not.toBeDisabled())
    fireEvent.change(screen.getByLabelText("Grupo y materia"), { target: { value: "5:9" } })
    vi.useFakeTimers()
    fireEvent.click(screen.getByRole("button", { name: "Ver ejemplo" }))
    await act(async () => {
      await vi.advanceTimersByTimeAsync(PREVIEW_POLL_MS * (PREVIEW_MAX_POLLS + 1))
    })

    expect(fetchPreview).toHaveBeenCalledTimes(PREVIEW_MAX_POLLS)
    expect(screen.getByText(/El ejemplo está tardando demasiado/)).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Ver ejemplo" })).not.toBeDisabled()
  })
})
