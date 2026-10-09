import { render, screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { beforeEach, describe, expect, it, vi } from "vitest"
import type { Role } from "@/types"
import { AlertSettingsPage } from "./AlertSettingsPage"
import * as alertSettingsApi from "./alertSettingsApi"
import * as subjectsApi from "@/features/subjects/subjectsApi"

vi.mock("./alertSettingsApi")
vi.mock("@/features/subjects/subjectsApi")

let roles: Role[] = ["director"]
vi.mock("@/features/auth/AuthContext", () => ({
  useAuth: () => ({ user: { id: 1, name: "Dir", email: "d@x.com", roles } }),
}))

const routing = [
  { type: "performance" as const, recipients: ["teacher" as const], is_default: true },
  {
    type: "planning_attendance" as const,
    recipients: ["teacher" as const, "psychopedagogue" as const],
    is_default: true,
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
    await userEvent.click(await screen.findByRole("button", { name: "3 notas seguidas por debajo de 5" }))

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

  it("does not load anything for a teacher", () => {
    roles = ["teacher"]
    render(<AlertSettingsPage />)
    expect(screen.getByText("No tenés permiso para ver esta pantalla.")).toBeInTheDocument()
    expect(alertSettingsApi.fetchAlertSettings).not.toHaveBeenCalled()
  })
})
