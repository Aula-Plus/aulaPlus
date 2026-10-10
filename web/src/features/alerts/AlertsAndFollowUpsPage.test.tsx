import { render, screen } from "@testing-library/react"
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
