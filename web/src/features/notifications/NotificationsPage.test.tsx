import { render, screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import * as alertsApi from "@/features/alerts/alertsApi"
import * as scheduledFollowUpsApi from "@/features/tracking/scheduledFollowUpsApi"
import type { AppNotification } from "@/types"
import * as notificationsApi from "./notificationsApi"
import { NotificationsPage } from "./NotificationsPage"

const unreadAccommodation: AppNotification = {
  id: "a",
  data: { type: "accommodation_confirmed", accommodation_id: 3, student_id: 7, student_name: "Lucía Pereyra" },
  read_at: null,
  created_at: "2026-11-12T10:00:00Z",
}
const readExtract: AppNotification = {
  id: "b",
  data: { type: "comment_shared", comment_share_id: 1, student_id: 8, student_name: "Tomás Silva" },
  read_at: "2026-11-11T10:00:00Z",
  created_at: "2026-11-10T10:00:00Z",
}

function renderPage() {
  return render(
    <MemoryRouter>
      <NotificationsPage />
    </MemoryRouter>,
  )
}

describe("NotificationsPage", () => {
  afterEach(() => vi.restoreAllMocks())

  it("shows the alerts and the whole notice history, read ones included", async () => {
    vi.spyOn(alertsApi, "fetchMyAlerts").mockResolvedValue([])
    const history = vi
      .spyOn(notificationsApi, "fetchNotificationHistory")
      .mockResolvedValue([unreadAccommodation, readExtract])

    renderPage()

    expect(screen.getByRole("heading", { name: "Notificaciones" })).toBeInTheDocument()
    expect(screen.getByRole("heading", { name: "Alertas" })).toBeInTheDocument()
    const item = (await screen.findByText(/ajuste nuevo vigente para Lucía Pereyra/)).closest("li")!
    expect(within(item).getByRole("link", { name: "Ver ajustes en la ficha" })).toHaveAttribute(
      "href",
      "/alumnos/7/seguimiento",
    )
    expect(within(item).getByRole("button", { name: "Entendido" })).toBeInTheDocument()
    // A read notice stays in the history, without «Entendido».
    const read = screen.getByText(/te compartió un extracto sobre Tomás Silva/).closest("li")!
    expect(within(read).queryByRole("button", { name: "Entendido" })).not.toBeInTheDocument()
    expect(history).toHaveBeenCalledWith("all")
  })

  it("filters to the unread ones", async () => {
    vi.spyOn(alertsApi, "fetchMyAlerts").mockResolvedValue([])
    const history = vi
      .spyOn(notificationsApi, "fetchNotificationHistory")
      .mockImplementation(async (filter) => (filter === "unread" ? [unreadAccommodation] : [unreadAccommodation, readExtract]))

    renderPage()
    await screen.findByText(/extracto sobre Tomás Silva/)
    await userEvent.click(screen.getByRole("button", { name: "Sin leer" }))

    expect(history).toHaveBeenLastCalledWith("unread")
    expect(await screen.findByText(/ajuste nuevo vigente/)).toBeInTheDocument()
    expect(screen.queryByText(/extracto sobre Tomás Silva/)).not.toBeInTheDocument()
  })

  it("marks one notice read with «Entendido» and keeps it in the history", async () => {
    vi.spyOn(alertsApi, "fetchMyAlerts").mockResolvedValue([])
    vi.spyOn(notificationsApi, "fetchNotificationHistory").mockResolvedValue([unreadAccommodation])
    vi.spyOn(scheduledFollowUpsApi, "fetchUnreadNotificationCount").mockResolvedValue(0)
    const markRead = vi.spyOn(scheduledFollowUpsApi, "markNotificationRead").mockResolvedValue()

    renderPage()
    await userEvent.click(await screen.findByRole("button", { name: "Entendido" }))

    expect(markRead).toHaveBeenCalledWith("a")
    expect(screen.getByText(/ajuste nuevo vigente/)).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Entendido" })).not.toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Marcar todas como leídas" })).toBeDisabled()
  })

  it("marks everything read and reloads the list", async () => {
    vi.spyOn(alertsApi, "fetchMyAlerts").mockResolvedValue([])
    const history = vi
      .spyOn(notificationsApi, "fetchNotificationHistory")
      .mockResolvedValueOnce([unreadAccommodation])
      .mockResolvedValue([{ ...unreadAccommodation, read_at: "2026-11-13T10:00:00Z" }])
    vi.spyOn(scheduledFollowUpsApi, "fetchUnreadNotificationCount").mockResolvedValue(0)
    const markAll = vi.spyOn(notificationsApi, "markAllNotificationsRead").mockResolvedValue()

    renderPage()
    await userEvent.click(await screen.findByRole("button", { name: "Marcar todas como leídas" }))

    expect(markAll).toHaveBeenCalled()
    expect(history).toHaveBeenCalledTimes(2)
    expect(await screen.findByText(/Leído/)).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Entendido" })).not.toBeInTheDocument()
  })

  it("says when there is nothing unread", async () => {
    vi.spyOn(alertsApi, "fetchMyAlerts").mockResolvedValue([])
    vi.spyOn(notificationsApi, "fetchNotificationHistory").mockResolvedValue([])

    renderPage()

    expect(await screen.findByText("Todavía no tenés avisos.")).toBeInTheDocument()
  })

  it("tells the owner their material was taken out of the catalog, with the reason when written", async () => {
    vi.spyOn(alertsApi, "fetchMyAlerts").mockResolvedValue([])
    vi.spyOn(notificationsApi, "fetchNotificationHistory").mockResolvedValue([
      {
        id: "m1",
        data: {
          type: "material_removed",
          material_id: 4,
          subject_id: 3,
          school_grade_id: 8,
          reason: "Ya hay una guía igual.",
          material_title: "Fracciones equivalentes",
        },
        read_at: null,
        created_at: "2026-11-12T10:00:00Z",
      },
      {
        id: "m2",
        data: { type: "material_removed", material_id: 5, subject_id: 3, school_grade_id: null, reason: null, material_title: null },
        read_at: null,
        created_at: "2026-11-11T10:00:00Z",
      },
    ])

    renderPage()

    const withReason = (await screen.findByText(/Sacaron del catálogo tu material «Fracciones equivalentes»/)).closest("li")!
    expect(within(withReason).getByText(/Motivo: Ya hay una guía igual\./)).toBeInTheDocument()
    expect(within(withReason).getByRole("link", { name: "Ver en Materiales" })).toHaveAttribute(
      "href",
      "/materiales?materia=3&nivel=8",
    )
    const bare = screen.getByText(/^Sacaron del catálogo tu material\.$/).closest("li")!
    expect(within(bare).queryByText(/Motivo/)).not.toBeInTheDocument()
    expect(within(bare).getByRole("link", { name: "Ver en Materiales" })).toHaveAttribute(
      "href",
      "/materiales?materia=3&nivel=none",
    )
  })
})
