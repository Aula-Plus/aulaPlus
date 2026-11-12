import { render, screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { beforeEach, describe, expect, it, vi } from "vitest"
import type { Role } from "@/types"
import { AlertSettingsPage } from "./AlertSettingsPage"
import * as alertSettingsApi from "./alertSettingsApi"
import * as subjectsApi from "@/features/subjects/subjectsApi"
import * as groupsApi from "@/features/groups/groupsApi"

vi.mock("./alertSettingsApi")
vi.mock("@/features/subjects/subjectsApi")
vi.mock("@/features/groups/groupsApi")

const levels = [
  { id: 1, key: "initial", label: "Inicial", grades: [] },
  { id: 2, key: "primary", label: "Primaria", grades: [] },
  { id: 3, key: "lower_secondary", label: "Ciclo Básico", grades: [] },
  { id: 4, key: "upper_secondary", label: "Bachillerato", grades: [] },
]

let roles: Role[] = ["director"]
vi.mock("@/features/auth/AuthContext", () => ({
  useAuth: () => ({ user: { id: 1, name: "Dir", email: "d@x.com", roles } }),
}))

const routing = [
  { type: "performance" as const, recipients: ["teacher" as const], is_default: true },
  {
    type: "escalated_overdue" as const,
    recipients: ["teacher" as const, "psychopedagogue" as const],
    is_default: false,
  },
]

describe("AlertSettingsPage", () => {
  beforeEach(() => {
    vi.clearAllMocks()
    roles = ["director"]
    vi.mocked(subjectsApi.fetchSubjects).mockResolvedValue([
      { id: 7, name: "Matemática", short_code: null, color: null },
    ])
    vi.mocked(alertSettingsApi.fetchAlertSettings).mockResolvedValue({ rules: [], routing })
    vi.mocked(groupsApi.fetchEducationLevels).mockResolvedValue({ data: levels, groups_without_level: 0 })
    vi.mocked(alertSettingsApi.createAlertRule).mockResolvedValue({
      id: 1,
      condition: "consecutive_below",
      threshold: 5,
      consecutive_count: 3,
      period_days: null,
      subject_id: null,
      active: true,
      created_at: null,
    })
    vi.mocked(alertSettingsApi.updateAlertRouting).mockResolvedValue(routing)
  })

  it("explains that nothing is generated until the school picks a condition", async () => {
    render(<AlertSettingsPage />)
    expect(await screen.findByText(/Aula\+ no genera esta alerta hasta que el colegio elija una/)).toBeInTheDocument()
  })

  it("creates a condition from an example, adjusted by the school", async () => {
    render(<AlertSettingsPage />)
    await userEvent.click(await screen.findByRole("button", { name: "3 calificaciones seguidas por debajo de 5" }))

    const threshold = screen.getByLabelText("Por debajo de")
    await userEvent.clear(threshold)
    await userEvent.type(threshold, "4")
    await userEvent.click(screen.getByRole("button", { name: "Agregar condición" }))

    await waitFor(() =>
      expect(alertSettingsApi.createAlertRule).toHaveBeenCalledWith({
        condition: "consecutive_below",
        threshold: 4,
        consecutive_count: 3,
        period_days: null,
        subject_id: null,
        education_level_ids: null,
      }),
    )
  })

  it("lists configured conditions with their subject", async () => {
    vi.mocked(alertSettingsApi.fetchAlertSettings).mockResolvedValue({
      rules: [
        {
          id: 3,
          condition: "average_below",
          threshold: 6,
          consecutive_count: null,
          period_days: 90,
          subject_id: 7,
          active: true,
          created_at: null,
        },
      ],
      routing,
    })
    render(<AlertSettingsPage />)

    const item = await screen.findByRole("listitem")
    expect(within(item).getByText("Promedio de los últimos 90 días por debajo de 6")).toBeInTheDocument()
    expect(await within(item).findByText("Matemática")).toBeInTheDocument()
    expect(within(item).getByText("Activa")).toBeInTheDocument()
  })

  it("changes who the performance alert reaches first", async () => {
    render(<AlertSettingsPage />)
    await userEvent.click(
      await screen.findByRole("checkbox", { name: "Psicopedagogía", checked: false }),
    )
    await userEvent.click(screen.getByRole("button", { name: "Guardar" }))

    await waitFor(() =>
      expect(alertSettingsApi.updateAlertRouting).toHaveBeenCalledWith("performance", [
        "teacher",
        "psychopedagogue",
      ]),
    )
  })

  it("warns before saving Direction as the only recipient and still lets the school save", async () => {
    vi.mocked(alertSettingsApi.updateAlertRouting).mockResolvedValue([
      { type: "performance", recipients: ["director"], is_default: false },
      routing[1],
    ])
    render(<AlertSettingsPage />)
    const warning = "Esta alerta llegará solo a Dirección. El docente y Psicopedagogía no recibirán este aviso."

    const row = await screen.findByRole("group", { name: /Desempeño bajo sostenido/ })
    await userEvent.click(within(row).getByRole("checkbox", { name: "Dirección" }))
    expect(screen.queryByText(warning)).not.toBeInTheDocument()

    // Unchecking the teacher leaves Direction alone: the warning shows before saving.
    await userEvent.click(within(row).getByRole("checkbox", { name: "Docente de la materia" }))
    expect(within(row).getByText(warning)).toBeInTheDocument()

    await userEvent.click(within(row).getByRole("button", { name: "Guardar igual" }))
    await waitFor(() =>
      expect(alertSettingsApi.updateAlertRouting).toHaveBeenCalledWith("performance", ["director"]),
    )
    // The saved state is still Direction only, so the notice stays.
    expect(await screen.findByText(warning)).toBeInTheDocument()
  })

  it("drops the warning as soon as another recipient is added", async () => {
    vi.mocked(alertSettingsApi.fetchAlertSettings).mockResolvedValue({
      rules: [],
      routing: [{ type: "performance", recipients: ["director"], is_default: false }],
    })
    render(<AlertSettingsPage />)
    const warning = /Esta alerta llegará solo a Dirección/

    expect(await screen.findByText(warning)).toBeInTheDocument()
    await userEvent.click(screen.getByRole("checkbox", { name: "Psicopedagogía", checked: false }))
    expect(screen.queryByText(warning)).not.toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Guardar" })).toBeInTheDocument()
  })

  describe("levels each condition applies to (ClickUp 1dfd3mc2wx0)", () => {
    it("starts with every level ticked and sends only the levels the school keeps", async () => {
      render(<AlertSettingsPage />)
      await userEvent.click(await screen.findByRole("button", { name: "3 calificaciones seguidas por debajo de 5" }))

      const fieldset = screen.getByRole("group", { name: "Niveles donde aplica" })
      for (const label of ["Inicial", "Primaria", "Ciclo Básico", "Bachillerato"]) {
        expect(within(fieldset).getByRole("checkbox", { name: label })).toBeChecked()
      }
      await userEvent.click(within(fieldset).getByRole("checkbox", { name: "Inicial" }))
      await userEvent.click(within(fieldset).getByRole("checkbox", { name: "Bachillerato" }))
      await userEvent.click(screen.getByRole("button", { name: "Agregar condición" }))

      await waitFor(() =>
        expect(alertSettingsApi.createAlertRule).toHaveBeenCalledWith(
          expect.objectContaining({ education_level_ids: [2, 3] }),
        ),
      )
    })

    it("asks for at least one level", async () => {
      render(<AlertSettingsPage />)
      await userEvent.click(
        await screen.findByRole("button", { name: "Nueva condición de señal de dificultad" }),
      )
      await userEvent.type(screen.getByLabelText("Cantidad de señales de dificultad"), "3")
      await userEvent.type(screen.getByLabelText("En cuántos días"), "21")
      const fieldset = screen.getByRole("group", { name: "Niveles donde aplica" })
      for (const checkbox of within(fieldset).getAllByRole("checkbox")) {
        await userEvent.click(checkbox)
      }
      await userEvent.click(screen.getByRole("button", { name: "Guardar condición" }))

      expect(await screen.findByText("Elegí al menos un nivel.")).toBeInTheDocument()
      expect(alertSettingsApi.createAlertRule).not.toHaveBeenCalled()
    })

    it("shows each condition's levels and keeps them when editing", async () => {
      vi.mocked(alertSettingsApi.fetchAlertSettings).mockResolvedValue({
        rules: [
          {
            id: 3,
            condition: "consecutive_below",
            threshold: 5,
            consecutive_count: 3,
            period_days: null,
            subject_id: null,
            education_level_ids: [2],
            active: true,
            created_at: null,
          },
          {
            id: 4,
            condition: "average_below",
            threshold: 6,
            consecutive_count: null,
            period_days: 90,
            subject_id: null,
            education_level_ids: null,
            active: true,
            created_at: null,
          },
        ],
        routing,
      })
      vi.mocked(alertSettingsApi.updateAlertRule).mockResolvedValue({} as never)
      render(<AlertSettingsPage />)

      const [primaryOnly, everyLevel] = await screen.findAllByRole("listitem")
      expect(await within(primaryOnly).findByText("Primaria")).toBeInTheDocument()
      expect(within(everyLevel).getByText("Todos los niveles")).toBeInTheDocument()

      await userEvent.click(within(primaryOnly).getByRole("button", { name: "Editar" }))
      const fieldset = within(primaryOnly).getByRole("group", { name: "Niveles donde aplica" })
      expect(within(fieldset).getByRole("checkbox", { name: "Primaria" })).toBeChecked()
      expect(within(fieldset).getByRole("checkbox", { name: "Ciclo Básico" })).not.toBeChecked()
      await userEvent.click(within(fieldset).getByRole("checkbox", { name: "Ciclo Básico" }))
      await userEvent.click(within(primaryOnly).getByRole("button", { name: "Guardar cambios" }))

      await waitFor(() =>
        expect(alertSettingsApi.updateAlertRule).toHaveBeenCalledWith(
          3,
          expect.objectContaining({ education_level_ids: [2, 3] }),
        ),
      )
    })

    it("warns how many groups have no level and so are in no alert", async () => {
      vi.mocked(groupsApi.fetchEducationLevels).mockResolvedValue({ data: levels, groups_without_level: 2 })
      render(<AlertSettingsPage />)

      expect(
        await screen.findByText("2 grupos sin nivel: no entran en ninguna alerta hasta que se les elija uno."),
      ).toBeInTheDocument()
    })
  })

  it("does not load anything for a teacher", () => {
    roles = ["teacher"]
    render(<AlertSettingsPage />)
    expect(screen.getByText("No tenés permiso para ver esta pantalla.")).toBeInTheDocument()
    expect(alertSettingsApi.fetchAlertSettings).not.toHaveBeenCalled()
  })

  describe("«Señal de dificultad» (ClickUp 86e3qkwpn)", () => {
    it("starts both fields empty, with no suggested value, and requires them", async () => {
      render(<AlertSettingsPage />)
      await userEvent.click(
        await screen.findByRole("button", { name: "Nueva condición de señal de dificultad" }),
      )

      const count = screen.getByLabelText("Cantidad de señales de dificultad")
      const days = screen.getByLabelText("En cuántos días")
      expect(count).toHaveValue(null)
      expect(days).toHaveValue(null)
      expect(count).not.toHaveAttribute("placeholder")

      await userEvent.click(screen.getByRole("button", { name: "Guardar condición" }))
      expect(await screen.findByText("Completá la cantidad")).toBeInTheDocument()
      expect(screen.getByText("Completá el período")).toBeInTheDocument()
      expect(alertSettingsApi.createAlertRule).not.toHaveBeenCalled()

      await userEvent.type(count, "3")
      await userEvent.type(days, "21")
      await userEvent.click(screen.getByRole("button", { name: "Guardar condición" }))
      await waitFor(() =>
        expect(alertSettingsApi.createAlertRule).toHaveBeenCalledWith({
          condition: "difficulty_signals",
          signal_count: 3,
          period_days: 21,
          education_level_ids: null,
        }),
      )
    })

    it("lists difficulty conditions in their own card, apart from the performance ones", async () => {
      vi.mocked(alertSettingsApi.fetchAlertSettings).mockResolvedValue({
        rules: [
          {
            id: 9,
            condition: "difficulty_signals",
            threshold: null,
            consecutive_count: null,
            signal_count: 3,
            period_days: 21,
            subject_id: null,
            active: true,
            created_at: null,
          },
        ],
        routing,
      })
      render(<AlertSettingsPage />)

      expect(
        await screen.findByText("3 señales de dificultad en una misma categoría en 21 días"),
      ).toBeInTheDocument()
      // The performance card still says nothing is configured there.
      expect(screen.getByText(/Aula\+ no genera esta alerta hasta que el colegio elija una/)).toBeInTheDocument()
    })

    it("never offers teachers as recipients of the difficulty alert", async () => {
      vi.mocked(alertSettingsApi.fetchAlertSettings).mockResolvedValue({
        rules: [],
        routing: [
          ...routing,
          {
            type: "difficulty_signal" as const,
            recipients: ["psychopedagogue" as const],
            allowed_recipients: ["psychopedagogue" as const, "director" as const],
            is_default: true,
          },
        ],
      })
      render(<AlertSettingsPage />)

      const legend = await screen.findByText("Señal de dificultad", { selector: "legend" })
      const fieldset = legend.closest("fieldset") as HTMLElement
      expect(within(fieldset).queryByRole("checkbox", { name: "Docente de la materia" })).not.toBeInTheDocument()
      expect(within(fieldset).getByRole("checkbox", { name: "Psicopedagogía" })).toBeChecked()
      // Dirección is not marked by default.
      expect(within(fieldset).getByRole("checkbox", { name: "Dirección" })).not.toBeChecked()
    })
  })
})

