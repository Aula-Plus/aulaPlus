import { render, screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import * as alertsApi from "@/features/alerts/alertsApi"
import * as scheduledFollowUpsApi from "@/features/tracking/scheduledFollowUpsApi"
import type { Alert, ScheduledFollowUp } from "@/types"
import { DashboardPage } from "./DashboardPage"

vi.mock("@/features/auth/AuthContext", () => ({
  useAuth: () => ({
    user: { id: 4, name: "Mariana López", email: "mariana@e.com", roles: ["teacher"], school: { id: 1, name: "Escuela" } },
  }),
}))

function renderPage() {
  return render(
    <MemoryRouter>
      <DashboardPage />
    </MemoryRouter>,
  )
}

const alert: Alert = {
  id: 10,
  student_id: 5,
  student_name: "Lucía Pereyra",
  subject_id: 2,
  subject_name: "Matemática",
  type: "performance",
  severity: "medium",
  description: "Condición configurada por el colegio: 3 notas seguidas por debajo de 5.",
  condition_met_on: "2026-09-12",
  recipients: ["teacher"],
  outcome: null,
  can: { act: true, resolve: false },
  resolved: false,
  resolved_by_id: null,
  resolved_at: null,
  created_at: "2026-09-12T10:00:00Z",
}

const followUp: ScheduledFollowUp = {
  id: 1,
  student_id: 8,
  student: { id: 8, full_name: "Tomás Silva" },
  description: "Revisar el cuaderno de tareas",
  due_date: "2026-10-30",
  created_by_id: 3,
  assigned_to_id: 4,
  assigned_to: { id: 4, name: "Mariana López", role: "teacher" },
  shared_with: [],
  resolved: false,
  resolved_by_id: null,
  resolved_at: null,
  resolution_note: null,
  is_overdue: false,
  created_at: null,
}

function section(name: string) {
  return screen.getByRole("heading", { level: 2, name }).closest("section") as HTMLElement
}

describe("DashboardPage", () => {
  // Only Date is faked: promises and timers stay real for findBy*.
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ["Date"] })
    vi.setSystemTime(new Date(2026, 10, 20, 9, 0))
  })
  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

  it("shows what there is to do instead of the account card", async () => {
    vi.spyOn(alertsApi, "fetchMyAlerts").mockResolvedValue([])
    vi.spyOn(scheduledFollowUpsApi, "fetchMyFollowUps").mockResolvedValue([])
    vi.spyOn(scheduledFollowUpsApi, "fetchNotifications").mockResolvedValue([])

    renderPage()

    expect(screen.getByRole("heading", { level: 1, name: "¿Qué tenés que hacer hoy?" })).toBeInTheDocument()
    expect(screen.getByText("Viernes 20 de noviembre de 2026")).toBeInTheDocument()
    expect(screen.getByText("Hola, Mariana López")).toBeInTheDocument()
    expect(screen.getByRole("heading", { level: 2, name: "Alertas" })).toBeInTheDocument()
    expect(screen.getByRole("heading", { level: 2, name: "Seguimientos" })).toBeInTheDocument()
    expect(await screen.findByText("No tenés alertas pendientes.")).toBeInTheDocument()
    expect(await screen.findByText("No tenés seguimientos pendientes.")).toBeInTheDocument()
    expect(screen.queryByText("mariana@e.com")).not.toBeInTheDocument()
    expect(screen.queryByText("Tu cuenta")).not.toBeInTheDocument()
  })

  it("lists the user's alerts with their ways out and their follow-ups with the deadline", async () => {
    vi.spyOn(alertsApi, "fetchMyAlerts").mockResolvedValue([alert])
    vi.spyOn(scheduledFollowUpsApi, "fetchMyFollowUps").mockResolvedValue([followUp])
    vi.spyOn(scheduledFollowUpsApi, "fetchNotifications").mockResolvedValue([])

    renderPage()

    expect(await screen.findByRole("link", { name: "Lucía Pereyra" })).toBeInTheDocument()
    expect(screen.getByRole("heading", { name: "Te toca elegir una salida" })).toBeInTheDocument()
    expect(await screen.findByText("Tomás Silva")).toBeInTheDocument()
    expect(screen.getByText(/Plazo:/)).toBeInTheDocument()
  })

  it("puts the notices with the alerts, below them, and keeps only the work with a deadline apart", async () => {
    vi.spyOn(alertsApi, "fetchMyAlerts").mockResolvedValue([])
    vi.spyOn(scheduledFollowUpsApi, "fetchMyFollowUps").mockResolvedValue([followUp])
    vi.spyOn(scheduledFollowUpsApi, "fetchNotifications").mockResolvedValue([
      {
        id: "n1",
        data: { type: "follow_up_assigned", follow_up_id: 1, student_id: 8, due_date: "2026-10-30" },
        created_at: null,
      },
    ])

    renderPage()

    const alerts = section("Alertas")
    // No alerts but a notice: the empty line stays and the notice goes below it.
    expect(await within(alerts).findByText("No tenés alertas pendientes.")).toBeInTheDocument()
    const notice = await within(alerts).findByText(/te asignaron un seguimiento de Tomás Silva/i)
    expect(within(alerts).getByRole("heading", { level: 3, name: "Avisos" })).toBeInTheDocument()
    expect(
      within(alerts).getByText("No tenés alertas pendientes.").compareDocumentPosition(notice) &
        Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy()

    const followUps = section("Seguimientos")
    expect(within(followUps).getByText("Tomás Silva")).toBeInTheDocument()
    expect(within(followUps).queryByText("Avisos")).not.toBeInTheDocument()
    expect(within(followUps).queryByText(/te asignaron un seguimiento/i)).not.toBeInTheDocument()
  })

  it("loads each list once, and a dismissed notice leaves", async () => {
    vi.spyOn(alertsApi, "fetchMyAlerts").mockResolvedValue([])
    const fetchMine = vi.spyOn(scheduledFollowUpsApi, "fetchMyFollowUps").mockResolvedValue([])
    const fetchNotices = vi.spyOn(scheduledFollowUpsApi, "fetchNotifications").mockResolvedValue([
      {
        id: "n2",
        data: { type: "alert_overdue", alert_id: 10, student_id: 5, due_date: "2026-11-01" },
        created_at: null,
      },
    ])
    vi.spyOn(scheduledFollowUpsApi, "markNotificationRead").mockResolvedValue()
    vi.spyOn(scheduledFollowUpsApi, "fetchUnreadNotificationCount").mockResolvedValue(0)

    renderPage()

    expect(await screen.findByText(/llegó a su fecha sin salida/i)).toBeInTheDocument()
    expect(fetchMine).toHaveBeenCalledTimes(1)
    expect(fetchNotices).toHaveBeenCalledTimes(1)

    await userEvent.click(screen.getByRole("button", { name: "Entendido" }))
    await waitFor(() => expect(screen.queryByText(/llegó a su fecha sin salida/i)).not.toBeInTheDocument())
  })

  it("drops a resolved alert and reloads the follow-ups a way out may have linked", async () => {
    vi.spyOn(alertsApi, "fetchMyAlerts")
      .mockResolvedValueOnce([{ ...alert, can: { act: false, resolve: true } }])
      .mockResolvedValue([])
    vi.spyOn(alertsApi, "resolveAlert").mockResolvedValue({ ...alert, resolved: true })
    const fetchMine = vi.spyOn(scheduledFollowUpsApi, "fetchMyFollowUps").mockResolvedValue([])
    vi.spyOn(scheduledFollowUpsApi, "fetchNotifications").mockResolvedValue([])

    renderPage()

    await userEvent.click(await screen.findByRole("button", { name: "Marcar como resuelta" }))

    expect(await screen.findByText("No tenés alertas pendientes.")).toBeInTheDocument()
    expect(screen.queryByRole("link", { name: "Lucía Pereyra" })).not.toBeInTheDocument()
    await waitFor(() => expect(fetchMine).toHaveBeenCalledTimes(2))
  })
})
