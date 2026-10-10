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

  it("shares an alert it has with someone who is not already in charge, and shows both holders", async () => {
    const held: Alert = {
      ...newAlert,
      outcome: "handed_off",
      assignee: { id: 3, name: "Galia Cohen" },
      outcome_by: { id: 1, name: "Mariana López" },
      outcome_at: "2026-09-13T10:00:00Z",
      due_on: "2026-09-20",
      can: { act: true, share: true, note: true, resolve: true },
    }
    vi.mocked(alertsApi.fetchMyAlerts).mockResolvedValue([held])
    vi.mocked(alertsApi.fetchHandoffCandidates).mockResolvedValue([
      { id: 1, name: "Mariana López", roles: ["teacher"] },
      { id: 4, name: "Adriana Ruiz", roles: ["director"] },
    ])
    const shared: Alert = {
      ...held,
      collaborators: [{ id: 1, name: "Mariana López" }],
      actions: [
        {
          id: 7,
          kind: "share",
          outcome: null,
          actor: { id: 3, name: "Galia Cohen" },
          assignee: { id: 1, name: "Mariana López" },
          due_on: null,
          note: "La seguimos juntas.",
          created_at: "2026-09-14T10:00:00Z",
        },
      ],
      can: { act: true, share: false, note: true, resolve: true },
    }
    vi.mocked(alertsApi.shareAlert).mockResolvedValue(shared)
    // The page refreshes the list quietly after any change.
    vi.mocked(alertsApi.fetchMyAlerts).mockResolvedValueOnce([held]).mockResolvedValue([shared])
    renderPage()

    await userEvent.click(await screen.findByRole("button", { name: "Compartir con otro rol" }))
    expect(alertsApi.fetchHandoffCandidates).toHaveBeenCalledWith(10)

    // Empty submit is caught before reaching the server.
    await userEvent.click(screen.getByRole("button", { name: "Compartir" }))
    expect(await screen.findByText("Elegí con quién la compartís.")).toBeInTheDocument()
    expect(alertsApi.shareAlert).not.toHaveBeenCalled()

    await userEvent.click(screen.getByLabelText(/Con quién la compartís/))
    // The person already responsible is never offered.
    expect(screen.queryByRole("button", { name: /Galia Cohen/ })).not.toBeInTheDocument()
    await userEvent.click(await screen.findByRole("button", { name: /Mariana López · Docente/ }))
    await userEvent.type(screen.getByLabelText(/Nota para quien la recibe/), "La seguimos juntas.")
    await userEvent.click(screen.getByRole("button", { name: "Compartir" }))

    await waitFor(() =>
      expect(alertsApi.shareAlert).toHaveBeenCalledWith(10, { user_id: 1, note: "La seguimos juntas." }),
    )
    expect(await screen.findByText(/La tienen Galia Cohen y Mariana López desde el/)).toBeInTheDocument()
    expect(screen.getByText(/la compartió con Mariana López/)).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Compartir con otro rol" })).not.toBeInTheDocument()
  })

  it("leaves a note in the thread", async () => {
    const held: Alert = {
      ...newAlert,
      outcome: "owned",
      assignee: { id: 1, name: "Mariana López" },
      outcome_at: "2026-09-13T10:00:00Z",
      due_on: "2026-09-20",
      can: { act: true, share: true, note: true, resolve: true },
    }
    vi.mocked(alertsApi.fetchMyAlerts).mockResolvedValue([held])
    const noted: Alert = {
      ...held,
      actions: [
        {
          id: 8,
          kind: "note",
          outcome: null,
          actor: { id: 1, name: "Mariana López" },
          assignee: null,
          due_on: null,
          note: "Hablé con la familia.",
          created_at: "2026-09-14T10:00:00Z",
        },
      ],
    }
    vi.mocked(alertsApi.addAlertNote).mockResolvedValue(noted)
    vi.mocked(alertsApi.fetchMyAlerts).mockResolvedValueOnce([held]).mockResolvedValue([noted])
    renderPage()

    await userEvent.click(await screen.findByRole("button", { name: "Dejar una nota" }))
    await userEvent.type(screen.getByLabelText("Nota"), "Hablé con la familia.")
    await userEvent.click(screen.getByRole("button", { name: "Guardar nota" }))

    await waitFor(() => expect(alertsApi.addAlertNote).toHaveBeenCalledWith(10, "Hablé con la familia."))
    expect(await screen.findByText("Hablé con la familia.")).toBeInTheDocument()
  })

  it("hides sharing and notes when the server says the user can't", async () => {
    vi.mocked(alertsApi.fetchMyAlerts).mockResolvedValue([
      {
        ...newAlert,
        outcome: "handed_off",
        assignee: { id: 3, name: "Galia Cohen" },
        can: { act: false, share: false, note: false, resolve: false },
      },
    ])
    renderPage()

    expect(await screen.findByText(/La tiene Galia Cohen/)).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Compartir con otro rol" })).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Dejar una nota" })).not.toBeInTheDocument()
  })

  it("offers no note before a way out was chosen: nobody has the alert yet", async () => {
    vi.mocked(alertsApi.fetchMyAlerts).mockResolvedValue([
      { ...newAlert, can: { act: true, share: false, note: false, resolve: false } },
    ])
    renderPage()

    expect(await screen.findByRole("button", { name: "Me ocupo yo" })).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Dejar una nota" })).not.toBeInTheDocument()
  })

  it("says so when there are no alerts", async () => {
    vi.mocked(alertsApi.fetchMyAlerts).mockResolvedValue([])
    renderPage()
    expect(await screen.findByText("No tenés alertas abiertas.")).toBeInTheDocument()
  })
})
