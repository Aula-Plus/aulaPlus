import { render, screen } from "@testing-library/react"
import { afterEach, describe, expect, it, vi } from "vitest"
import App from "./App"
import * as authApi from "@/features/auth/authApi"
import * as alertsApi from "@/features/alerts/alertsApi"
import * as scheduledFollowUpsApi from "@/features/tracking/scheduledFollowUpsApi"
import * as reportsApi from "@/features/reports/reportsApi"
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

  it("shows «No encontramos esa página» for an unknown address instead of home", async () => {
    vi.spyOn(authApi, "fetchCurrentUser").mockResolvedValue({
      id: 1,
      name: "Ana",
      email: "ana@escuela.test",
      roles: ["director"],
    })
    window.history.replaceState(null, "", "/esto-no-existe")

    render(<App />)

    expect(
      await screen.findByRole("heading", { level: 1, name: "No encontramos esa página" }),
    ).toBeInTheDocument()
    expect(window.location.pathname).toBe("/esto-no-existe")
    expect(screen.getByRole("link", { name: "Volver al inicio" })).toHaveAttribute("href", "/")
  })

  it("also shows it for an unknown sub-path of an existing screen", async () => {
    vi.spyOn(authApi, "fetchCurrentUser").mockResolvedValue({
      id: 1,
      name: "Ana",
      email: "ana@escuela.test",
      roles: ["teacher"],
    })
    window.history.replaceState(null, "", "/alumnos/1/esto-no-existe")

    render(<App />)

    expect(
      await screen.findByRole("heading", { level: 1, name: "No encontramos esa página" }),
    ).toBeInTheDocument()
    expect(window.location.pathname).toBe("/alumnos/1/esto-no-existe")
  })

  it("sends an unauthenticated visitor of an unknown address to login first", async () => {
    vi.spyOn(authApi, "fetchCurrentUser").mockResolvedValue(null)
    window.history.replaceState(null, "", "/esto-no-existe")

    render(<App />)

    expect(await screen.findByRole("button", { name: "Ingresar" })).toBeInTheDocument()
    expect(window.location.pathname).toBe("/login")
    expect(screen.queryByText("No encontramos esa página")).not.toBeInTheDocument()
  })

  it("sends the old «IA en informes» address to «Usuarios y permisos › Límites»", async () => {
    vi.spyOn(authApi, "fetchCurrentUser").mockResolvedValue({
      id: 1,
      name: "Ana",
      email: "ana@escuela.test",
      roles: ["director"],
    })
    vi.spyOn(scheduledFollowUpsApi, "fetchUnreadNotificationCount").mockResolvedValue(0)
    vi.spyOn(reportsApi, "fetchAiReportSetting").mockResolvedValue({ enabled: false, available: true })
    window.history.replaceState(null, "", "/ajustes/informes-ia")

    render(<App />)

    // The real route, not `/usuarios/:id` (the edit-user form).
    expect(await screen.findByRole("button", { name: "Activar la IA en informes" })).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "Límites" })).toHaveAttribute("aria-current", "page")
    expect(window.location.pathname).toBe("/usuarios/limites")
  })

  it("gives a teacher with the old «IA en informes» link a way back home", async () => {
    vi.spyOn(authApi, "fetchCurrentUser").mockResolvedValue({
      id: 1,
      name: "Ana",
      email: "ana@escuela.test",
      roles: ["teacher"],
    })
    vi.spyOn(scheduledFollowUpsApi, "fetchUnreadNotificationCount").mockResolvedValue(0)
    window.history.replaceState(null, "", "/ajustes/informes-ia")

    render(<App />)

    expect(
      await screen.findByRole("heading", { level: 1, name: "Esta pantalla no está habilitada para tu cuenta" }),
    ).toBeInTheDocument()
    expect(window.location.pathname).toBe("/usuarios/limites")
    expect(screen.getByRole("link", { name: "Volver al inicio" })).toHaveAttribute("href", "/")
  })
})
