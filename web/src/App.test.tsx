import { render, screen } from "@testing-library/react"
import { afterEach, describe, expect, it, vi } from "vitest"
import App from "./App"
import * as authApi from "@/features/auth/authApi"
import * as alertsApi from "@/features/alerts/alertsApi"
import * as scheduledFollowUpsApi from "@/features/tracking/scheduledFollowUpsApi"
import * as gradesVisibilityApi from "@/features/tracking/gradesVisibilityApi"

describe("App routes", () => {
  afterEach(() => {
    vi.restoreAllMocks()
    window.history.replaceState(null, "", "/")
  })

  it("redirects the old /mis-seguimientos to «Alertas y seguimientos»", async () => {
    vi.spyOn(authApi, "fetchCurrentUser").mockResolvedValue({
      id: 1,
      name: "Ana",
      email: "ana@escuela.test",
      roles: ["teacher"],
    })
    vi.spyOn(alertsApi, "fetchMyAlerts").mockResolvedValue([])
    vi.spyOn(scheduledFollowUpsApi, "fetchMyFollowUps").mockResolvedValue([])
    vi.spyOn(scheduledFollowUpsApi, "fetchNotifications").mockResolvedValue([])
    vi.spyOn(scheduledFollowUpsApi, "fetchUnreadNotificationCount").mockResolvedValue(0)
    window.history.replaceState(null, "", "/mis-seguimientos")

    render(<App />)

    expect(
      await screen.findByRole("heading", { level: 1, name: "Alertas y seguimientos" }),
    ).toBeInTheDocument()
    expect(window.location.pathname).toBe("/alertas")
  })

  it("redirects the old /ajustes/notas to «Visibilidad de calificaciones»", async () => {
    vi.spyOn(authApi, "fetchCurrentUser").mockResolvedValue({
      id: 1,
      name: "Dir",
      email: "dir@escuela.test",
      roles: ["director"],
    })
    vi.spyOn(alertsApi, "fetchMyAlerts").mockResolvedValue([])
    vi.spyOn(scheduledFollowUpsApi, "fetchMyFollowUps").mockResolvedValue([])
    vi.spyOn(scheduledFollowUpsApi, "fetchNotifications").mockResolvedValue([])
    vi.spyOn(scheduledFollowUpsApi, "fetchUnreadNotificationCount").mockResolvedValue(0)
    vi.spyOn(gradesVisibilityApi, "fetchGradesVisibility").mockResolvedValue({
      mode: "own_subject",
      cutoff_months: null,
      cutoff_anchor: null,
      last_cutoff_at: null,
    })
    window.history.replaceState(null, "", "/ajustes/notas")

    render(<App />)

    expect(
      await screen.findByRole("heading", { level: 1, name: "Visibilidad de calificaciones" }),
    ).toBeInTheDocument()
    expect(window.location.pathname).toBe("/ajustes/calificaciones")
  })
})
