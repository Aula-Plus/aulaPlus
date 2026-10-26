import { render, screen } from "@testing-library/react"
import { MemoryRouter } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import * as alertsApi from "@/features/alerts/alertsApi"
import * as scheduledFollowUpsApi from "@/features/tracking/scheduledFollowUpsApi"
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

describe("DashboardPage", () => {
  afterEach(() => vi.restoreAllMocks())

  it("shows what there is to do instead of the account card", async () => {
    vi.spyOn(alertsApi, "fetchMyAlerts").mockResolvedValue([])
    vi.spyOn(scheduledFollowUpsApi, "fetchMyFollowUps").mockResolvedValue([])
    vi.spyOn(scheduledFollowUpsApi, "fetchNotifications").mockResolvedValue([])

    renderPage()

    expect(screen.getByRole("heading", { level: 1, name: "¿Qué tenés que hacer hoy?" })).toBeInTheDocument()
    expect(screen.getByText("Hola, Mariana López")).toBeInTheDocument()
    expect(screen.getByRole("heading", { level: 2, name: "Alertas" })).toBeInTheDocument()
    expect(screen.getByRole("heading", { level: 2, name: "Seguimientos" })).toBeInTheDocument()
    expect(await screen.findByText("No tenés alertas pendientes.")).toBeInTheDocument()
    expect(await screen.findByText("No tenés seguimientos pendientes.")).toBeInTheDocument()
    expect(screen.queryByText("mariana@e.com")).not.toBeInTheDocument()
    expect(screen.queryByText("Tu cuenta")).not.toBeInTheDocument()
  })

  it("lists the user's alerts with their ways out and their follow-ups with the deadline", async () => {
    vi.spyOn(alertsApi, "fetchMyAlerts").mockResolvedValue([
      {
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
      },
    ])
    vi.spyOn(scheduledFollowUpsApi, "fetchMyFollowUps").mockResolvedValue([
      {
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
      },
    ])
    vi.spyOn(scheduledFollowUpsApi, "fetchNotifications").mockResolvedValue([])

    renderPage()

    expect(await screen.findByRole("link", { name: "Lucía Pereyra" })).toBeInTheDocument()
    expect(screen.getByRole("heading", { name: "Te toca elegir una salida" })).toBeInTheDocument()
    expect(await screen.findByText("Tomás Silva")).toBeInTheDocument()
    expect(screen.getByText(/Plazo:/)).toBeInTheDocument()
  })
})
