import { render, screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { beforeEach, describe, expect, it, vi } from "vitest"
import type { Alert } from "@/types"
import { MyAlertsPage } from "./MyAlertsPage"
import * as alertsApi from "./alertsApi"

vi.mock("./alertsApi")

function isoInDays(days: number): string {
  const date = new Date()
  date.setDate(date.getDate() + days)
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`
}

const newAlert: Alert = {
  id: 10,
  student_id: 5,
  student_name: "Lucía Pereyra",
  subject_id: 2,
  subject_name: "Matemática",
  type: "performance",
  severity: "medium",
  description: "Condición configurada por el colegio: 3 notas seguidas por debajo de 5 en la misma materia.",
  condition_met_on: "2026-09-12",
  recipients: ["teacher"],
  outcome: null,
  assignee: null,
  outcome_at: null,
  due_on: null,
  is_overdue: false,
  actions: [],
  can: { act: true, resolve: false },
  resolved: false,
  resolved_by_id: null,
  resolved_at: null,
  created_at: "2026-09-12T10:00:00Z",
}

function renderPage() {
  render(
    <MemoryRouter>
      <MyAlertsPage />
    </MemoryRouter>,
  )
}

describe("MyAlertsPage", () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(alertsApi.fetchMyAlerts).mockResolvedValue([newAlert])
    vi.mocked(alertsApi.fetchHandoffCandidates).mockResolvedValue([
      { id: 3, name: "Galia Cohen", roles: ["psychopedagogue"] },
    ])
    vi.mocked(alertsApi.chooseAlertOutcome).mockResolvedValue({
      ...newAlert,
      outcome: "handed_off",
      assignee: { id: 3, name: "Galia Cohen" },
      can: { act: false, resolve: false },
    })
  })

  it("shows the alert with its subject and the three ways out", async () => {
    renderPage()

    expect(await screen.findByText("Lucía Pereyra")).toBeInTheDocument()
    expect(screen.getByText(/Desempeño bajo sostenido — Matemática/)).toBeInTheDocument()
    expect(screen.getByText("Sin salida elegida")).toBeInTheDocument()
    for (const label of ["Me ocupo yo", "Se la paso a otro rol", "La dejo en observación"]) {
      expect(screen.getByRole("button", { name: label })).toBeInTheDocument()
    }
  })

  it("takes the alert with the suggested one-week deadline in one tap", async () => {
    renderPage()
    await userEvent.click(await screen.findByRole("button", { name: "Me ocupo yo" }))
    await userEvent.click(screen.getByRole("button", { name: "Confirmar" }))

    await waitFor(() =>
      expect(alertsApi.chooseAlertOutcome).toHaveBeenCalledWith(10, {
        outcome: "owned",
        due_on: isoInDays(7),
        assignee_id: null,
        note: null,
      }),
    )
  })

  it("requires choosing who receives it when handing it off", async () => {
    renderPage()
    await userEvent.click(await screen.findByRole("button", { name: "Se la paso a otro rol" }))
    await userEvent.click(screen.getByRole("button", { name: "Confirmar" }))

    expect(await screen.findByText("Elegí a quién se la pasás.")).toBeInTheDocument()
    expect(alertsApi.chooseAlertOutcome).not.toHaveBeenCalled()
    expect(alertsApi.fetchHandoffCandidates).toHaveBeenCalledWith(10)
  })

  it("shows who has an alert and since when, without the buttons", async () => {
    vi.mocked(alertsApi.fetchMyAlerts).mockResolvedValue([
      {
        ...newAlert,
        outcome: "handed_off",
        assignee: { id: 3, name: "Galia Cohen" },
        outcome_at: "2026-09-13T10:00:00Z",
        due_on: "2026-09-20",
        can: { act: false, resolve: false },
      },
    ])
    renderPage()

    expect(await screen.findByText(/La tiene Galia Cohen desde el/)).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Me ocupo yo" })).not.toBeInTheDocument()
  })

  it("says so when there are no alerts", async () => {
    vi.mocked(alertsApi.fetchMyAlerts).mockResolvedValue([])
    renderPage()
    expect(await screen.findByText("No tenés alertas abiertas.")).toBeInTheDocument()
  })
})
