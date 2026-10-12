import { render, screen, within } from "@testing-library/react"
import { MemoryRouter } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import * as alertsApi from "./alertsApi"
import * as scheduledFollowUpsApi from "@/features/tracking/scheduledFollowUpsApi"
import { AlertsAndFollowUpsPage } from "./AlertsAndFollowUpsPage"

describe("AlertsAndFollowUpsPage", () => {
  afterEach(() => vi.restoreAllMocks())

  it("shows alerts and follow-ups on one screen", async () => {
    vi.spyOn(alertsApi, "fetchMyAlerts").mockResolvedValue([])
    vi.spyOn(scheduledFollowUpsApi, "fetchMyFollowUps").mockResolvedValue([])
    vi.spyOn(scheduledFollowUpsApi, "fetchNotifications").mockResolvedValue([])
    vi.spyOn(scheduledFollowUpsApi, "fetchUnreadNotificationCount").mockResolvedValue(0)

    render(
      <MemoryRouter>
        <AlertsAndFollowUpsPage />
      </MemoryRouter>,
    )

    expect(screen.getByRole("heading", { name: "Alertas y seguimientos" })).toBeInTheDocument()
    expect(await screen.findByText(/no tenés alertas abiertas/i)).toBeInTheDocument()
    expect(await screen.findByText(/no tenés seguimientos pendientes/i)).toBeInTheDocument()
    expect(screen.getByRole("heading", { name: "Mis seguimientos" })).toBeInTheDocument()
  })

  it("shows a teacher's subject alert next to the follow-ups assigned to them", async () => {
    // The product example: Mariana (Matemática) sees Lucía's alert and her own
    // follow-ups on the same screen. Which alerts reach her is the server's call.
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

    render(
      <MemoryRouter>
        <AlertsAndFollowUpsPage />
      </MemoryRouter>,
    )

    const alerts = await screen.findByRole("region", { name: "Alertas" })
    expect(await within(alerts).findByRole("link", { name: "Lucía Pereyra" })).toBeInTheDocument()
    expect(within(alerts).getByText(/Desempeño bajo sostenido — Matemática — desde el/)).toBeInTheDocument()

    const followUps = screen.getByRole("region", { name: "Mis seguimientos" })
    expect(await within(followUps).findByText("Revisar el cuaderno de tareas")).toBeInTheDocument()
    expect(within(followUps).getByText("Tomás Silva")).toBeInTheDocument()
  })

  it("keeps a page → section → subsection heading outline, even while loading", () => {
    vi.spyOn(alertsApi, "fetchMyAlerts").mockReturnValue(new Promise(() => {}))
    vi.spyOn(scheduledFollowUpsApi, "fetchMyFollowUps").mockReturnValue(new Promise(() => {}))
    vi.spyOn(scheduledFollowUpsApi, "fetchNotifications").mockResolvedValue([])

    render(
      <MemoryRouter>
        <AlertsAndFollowUpsPage />
      </MemoryRouter>,
    )

    const headings = screen.getAllByRole("heading").map((heading) => [heading.tagName, heading.textContent])
    expect(headings).toEqual([
      ["H1", "Alertas y seguimientos"],
      ["H2", "Alertas"],
      ["H2", "Mis seguimientos"],
      ["H3", "Pendientes"],
    ])
  })
})
