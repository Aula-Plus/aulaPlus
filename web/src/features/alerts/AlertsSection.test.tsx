import { render, screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { beforeEach, describe, expect, it, vi } from "vitest"
import type { Alert } from "@/types"
import * as scheduledFollowUpsApi from "@/features/tracking/scheduledFollowUpsApi"
import { AlertsSection } from "./AlertsSection"
import * as alertsApi from "./alertsApi"

vi.mock("./alertsApi")
vi.mock("@/features/tracking/scheduledFollowUpsApi")

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
      <AlertsSection />
    </MemoryRouter>,
  )
}

describe("AlertsSection", () => {
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

  it("shows a handed-off alert as «a tu cargo» to whoever received it, and as handed off to whoever passed it", async () => {
    const handedOff: Alert = {
      ...newAlert,
      outcome: "handed_off",
      assignee: { id: 3, name: "Galia Cohen" },
      outcome_by: { id: 1, name: "Mariana López" },
      outcome_at: "2026-10-20T12:00:00Z",
      due_on: "2026-10-27",
    }

    // Galia, who received it: it is hers, nothing left to choose.
    vi.mocked(alertsApi.fetchMyAlerts).mockResolvedValue([
      { ...handedOff, is_in_charge: true, can: { act: true, note: true, resolve: true, link_follow_up: true } },
    ])
    const { unmount } = render(
      <MemoryRouter>
        <AlertsSection />
      </MemoryRouter>,
    )

    expect(await screen.findByRole("heading", { name: "A tu cargo" })).toBeInTheDocument()
    expect(screen.getByText("A tu cargo desde el 20/10 · plazo al 27/10")).toBeInTheDocument()
    expect(screen.queryByRole("heading", { name: "Te toca elegir una salida" })).not.toBeInTheDocument()
    expect(screen.queryByText("Pasada a otro rol")).not.toBeInTheDocument()
    expect(screen.queryByText(/La tiene Galia Cohen/)).not.toBeInTheDocument()
    unmount()

    // Mariana, who handed it off: unchanged, she still reads «Pasada a otro rol».
    vi.mocked(alertsApi.fetchMyAlerts).mockResolvedValue([
      { ...handedOff, is_in_charge: false, can: { act: false, note: false, resolve: false, link_follow_up: false } },
    ])
    renderPage()

    expect(await screen.findByText("Pasada a otro rol")).toBeInTheDocument()
    expect(screen.getByRole("heading", { name: "En curso" })).toBeInTheDocument()
    expect(screen.getByText(/La tiene Galia Cohen desde el/)).toBeInTheDocument()
    expect(screen.queryByRole("heading", { name: "A tu cargo" })).not.toBeInTheDocument()
    expect(screen.queryByText(/A tu cargo desde el/)).not.toBeInTheDocument()
  })

  it("keeps «Te toca elegir una salida» for new alerts only, and lists the ones the viewer took under «A tu cargo»", async () => {
    vi.mocked(alertsApi.fetchMyAlerts).mockResolvedValue([
      newAlert,
      {
        ...newAlert,
        id: 11,
        student_name: "Tomás Díaz",
        outcome: "owned",
        assignee: { id: 1, name: "Mariana López" },
        outcome_at: "2026-10-20T12:00:00Z",
        due_on: "2026-10-27",
        is_in_charge: true,
        can: { act: true, note: true, resolve: true, link_follow_up: true },
      },
    ])
    renderPage()

    const pending = (await screen.findByRole("heading", { name: "Te toca elegir una salida" })).closest("section")
    const mine = screen.getByRole("heading", { name: "A tu cargo" }).closest("section")
    expect(pending).toHaveTextContent("Lucía Pereyra")
    expect(pending).not.toHaveTextContent("Tomás Díaz")
    expect(mine).toHaveTextContent("Tomás Díaz")
  })

  it("offers no way to share the alert itself", async () => {
    vi.mocked(alertsApi.fetchMyAlerts).mockResolvedValue([
      {
        ...newAlert,
        outcome: "owned",
        assignee: { id: 1, name: "Mariana López" },
        outcome_at: "2026-09-13T10:00:00Z",
        due_on: "2026-09-20",
        can: { act: true, note: true, resolve: true, link_follow_up: true },
      },
    ])
    renderPage()

    expect(await screen.findByRole("button", { name: "Crear seguimiento vinculado" })).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /Compartir/ })).not.toBeInTheDocument()
    expect("shareAlert" in alertsApi).toBe(false)
  })

  it("shows the alert read-only to whoever handed it off", async () => {
    vi.mocked(alertsApi.fetchMyAlerts).mockResolvedValue([
      {
        ...newAlert,
        outcome: "handed_off",
        assignee: { id: 3, name: "Galia Cohen" },
        outcome_by: { id: 1, name: "Mariana López" },
        outcome_at: "2026-09-13T10:00:00Z",
        due_on: "2026-09-20",
        actions: [
          {
            id: 6,
            kind: "outcome",
            outcome: "handed_off",
            actor: { id: 1, name: "Mariana López" },
            assignee: { id: 3, name: "Galia Cohen" },
            due_on: "2026-09-20",
            note: "Bajó de 7 a 4.",
            created_at: "2026-09-13T10:00:00Z",
          },
        ],
        can: { act: false, note: false, resolve: false, link_follow_up: false },
      },
    ])
    renderPage()

    expect(await screen.findByText(/La tiene Galia Cohen desde el/)).toBeInTheDocument()
    expect(screen.getByText(/Mariana López se la pasó a Galia Cohen, plazo al/)).toBeInTheDocument()
    expect(screen.getByText("Bajó de 7 a 4.")).toBeInTheDocument()
    for (const name of [
      "Me ocupo yo",
      "Dejar una nota",
      "Marcar como resuelta",
      "Crear seguimiento vinculado",
    ]) {
      expect(screen.queryByRole("button", { name })).not.toBeInTheDocument()
    }
  })

  it("marks the same alert when it reached its deadline without a way out, keeping its thread", async () => {
    vi.mocked(alertsApi.fetchMyAlerts).mockResolvedValue([
      {
        ...newAlert,
        outcome: "observing",
        assignee: { id: 1, name: "Mariana López" },
        outcome_at: "2026-09-13T10:00:00Z",
        due_on: "2026-09-20",
        is_overdue: true,
        overdue_at: "2026-09-21T03:00:00Z",
        actions: [
          {
            id: 6,
            kind: "outcome",
            outcome: "observing",
            actor: { id: 1, name: "Mariana López" },
            assignee: { id: 1, name: "Mariana López" },
            due_on: "2026-09-20",
            note: null,
            created_at: "2026-09-13T10:00:00Z",
          },
          {
            id: 9,
            kind: "overdue",
            outcome: null,
            actor: null,
            assignee: { id: 1, name: "Mariana López" },
            due_on: "2026-09-20",
            note: null,
            created_at: "2026-09-21T03:00:00Z",
          },
        ],
        can: { act: true, note: true, resolve: true, link_follow_up: true },
      },
    ])
    renderPage()

    // One card, the same case: no second "escalated" alert.
    expect(await screen.findAllByText("Lucía Pereyra")).toHaveLength(1)
    expect(screen.getByText("Llegó a su fecha sin salida", { selector: "span" })).toBeInTheDocument()
    expect(screen.getByText(/Mariana López la dejó en observación hasta el/)).toBeInTheDocument()
    expect(screen.getByText(/^Llegó a su fecha sin salida \(plazo al .*, la tenía Mariana López\)/)).toBeInTheDocument()
    // Still answerable with a new way out.
    expect(screen.getByRole("button", { name: "Me ocupo yo" })).toBeInTheDocument()
  })

  it("creates a follow-up linked to the alert without changing the alert", async () => {
    const held: Alert = {
      ...newAlert,
      outcome: "handed_off",
      assignee: { id: 3, name: "Galia Cohen" },
      outcome_at: "2026-09-13T10:00:00Z",
      due_on: "2026-09-20",
      follow_ups: [],
      can: { act: true, note: true, resolve: true, link_follow_up: true },
    }
    // The page refreshes the list quietly after any change: the server then
    // returns the same alert, now listing its linked follow-up.
    vi.mocked(alertsApi.fetchMyAlerts)
      .mockResolvedValueOnce([held])
      .mockResolvedValue([
        { ...held, follow_ups: [{ id: 40, due_date: "2026-10-30", resolved: false, is_overdue: false }] },
      ])
    vi.mocked(scheduledFollowUpsApi.createScheduledFollowUp).mockResolvedValue({
      id: 40,
      student_id: 5,
      alert_id: 10,
      description: "Armar el plan con la familia.",
      due_date: "2026-10-30",
      created_by_id: 3,
      resolved: false,
      resolved_by_id: null,
      resolved_at: null,
      resolution_note: null,
      is_overdue: false,
      created_at: "2026-09-14T10:00:00Z",
    })
    renderPage()

    await userEvent.click(await screen.findByRole("button", { name: "Crear seguimiento vinculado" }))
    expect(screen.getByText("Seguimiento vinculado a esta alerta")).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText("Título"), "Plan con la familia")
    await userEvent.type(screen.getByLabelText("Qué hay que hacer y por qué"), "Armar el plan con la familia.")
    await userEvent.type(screen.getByLabelText("Fecha límite"), "2026-10-30")
    await userEvent.click(screen.getByRole("button", { name: "Programar seguimiento" }))

    await waitFor(() =>
      expect(scheduledFollowUpsApi.createScheduledFollowUp).toHaveBeenCalledWith(5, {
        title: "Plan con la familia",
        description: "Armar el plan con la familia.",
        due_date: "2026-10-30",
        alert_id: 10,
      }),
    )
    expect(await screen.findByText("Seguimientos vinculados")).toBeInTheDocument()
    expect(screen.getByText("Pendiente")).toBeInTheDocument()
    // The alert keeps its state: no way out was chosen or closed on its behalf.
    expect(alertsApi.chooseAlertOutcome).not.toHaveBeenCalled()
    expect(alertsApi.resolveAlert).not.toHaveBeenCalled()
    expect(screen.getByText("Pasada a otro rol")).toBeInTheDocument()
  })

  it("leaves a note in the thread", async () => {
    const held: Alert = {
      ...newAlert,
      outcome: "owned",
      assignee: { id: 1, name: "Mariana López" },
      outcome_at: "2026-09-13T10:00:00Z",
      due_on: "2026-09-20",
      can: { act: true, note: true, resolve: true },
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

  it("hides notes when the server says the user can't", async () => {
    vi.mocked(alertsApi.fetchMyAlerts).mockResolvedValue([
      {
        ...newAlert,
        outcome: "handed_off",
        assignee: { id: 3, name: "Galia Cohen" },
        can: { act: false, note: false, resolve: false },
      },
    ])
    renderPage()

    expect(await screen.findByText(/La tiene Galia Cohen/)).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Dejar una nota" })).not.toBeInTheDocument()
  })

  it("offers no note before a way out was chosen: nobody has the alert yet", async () => {
    vi.mocked(alertsApi.fetchMyAlerts).mockResolvedValue([
      { ...newAlert, can: { act: true, note: false, resolve: false } },
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

  it("keeps its heading while loading and when loading fails", async () => {
    let fail!: (reason: unknown) => void
    vi.mocked(alertsApi.fetchMyAlerts).mockReturnValue(new Promise<Alert[]>((_, reject) => (fail = reject)))
    renderPage()

    expect(screen.getByRole("heading", { level: 2, name: "Alertas" })).toBeInTheDocument()
    expect(screen.getByText("Cargando…")).toBeInTheDocument()

    fail(new Error("network"))
    expect(await screen.findByText("No pudimos cargar tus alertas.")).toBeInTheDocument()
    expect(screen.getByRole("heading", { level: 2, name: "Alertas" })).toBeInTheDocument()
  })

  it("is a section: an h2 heading with h3 subsections, no page title", async () => {
    vi.mocked(alertsApi.fetchMyAlerts).mockResolvedValue([
      newAlert,
      { ...newAlert, id: 11, can: { act: false, resolve: false } },
    ])
    renderPage()

    expect(await screen.findByRole("heading", { level: 3, name: "Te toca elegir una salida" })).toBeInTheDocument()
    expect(screen.getByRole("heading", { level: 3, name: "En curso" })).toBeInTheDocument()
    expect(screen.getByRole("region", { name: "Alertas" })).toBeInTheDocument()
    expect(screen.queryByRole("heading", { level: 1 })).not.toBeInTheDocument()
  })
})
