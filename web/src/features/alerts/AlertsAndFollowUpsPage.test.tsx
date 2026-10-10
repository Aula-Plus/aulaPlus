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
})
