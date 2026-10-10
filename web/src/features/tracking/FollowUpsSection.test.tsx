import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { Sidebar } from "@/components/Sidebar"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import type { ScheduledFollowUp } from "@/types"
import { FollowUpsSection } from "./FollowUpsSection"
import * as api from "./scheduledFollowUpsApi"
import { resetUnreadNoticeCount } from "./unreadNotices"

function pendingFollowUp(overrides: Partial<ScheduledFollowUp> = {}): ScheduledFollowUp {
  return {
    id: 1,
    student_id: 3,
    student: { id: 3, full_name: "Juan Pérez" },
    description: "Revisar tiempo extendido",
    due_date: "2026-10-30",
    created_by_id: 5,
    assigned_to_id: 7,
    assigned_to: { id: 7, name: "Paula Psico", role: "psychopedagogue" },
    shared_with: [],
    resolved: false,
    resolved_by_id: null,
    resolved_at: null,
    resolution_note: null,
    is_overdue: false,
    created_at: null,
    ...overrides,
  }
}

describe("FollowUpsSection", () => {
  beforeEach(() => {
    resetUnreadNoticeCount()
    vi.spyOn(api, "fetchUnreadNotificationCount").mockResolvedValue(0)
  })
  afterEach(() => vi.restoreAllMocks())

  it("is a section: an h2 heading with h3 subsections, shown while loading", async () => {
    vi.spyOn(api, "fetchMyFollowUps").mockReturnValue(new Promise<ScheduledFollowUp[]>(() => {}))
    vi.spyOn(api, "fetchNotifications").mockResolvedValue([])

    render(
      <MemoryRouter>
        <FollowUpsSection />
      </MemoryRouter>,
    )

    expect(screen.getByRole("heading", { level: 2, name: "Mis seguimientos" })).toBeInTheDocument()
    expect(screen.getByRole("heading", { level: 3, name: "Pendientes" })).toBeInTheDocument()
    expect(screen.getByText("Cargando…")).toBeInTheDocument()
    expect(screen.queryByRole("heading", { level: 1 })).not.toBeInTheDocument()
  })

  it("labels a follow-up past its date without calling it expired", async () => {
    vi.spyOn(api, "fetchMyFollowUps").mockResolvedValue([pendingFollowUp({ is_overdue: true })])
    vi.spyOn(api, "fetchNotifications").mockResolvedValue([])

    render(
      <MemoryRouter>
        <FollowUpsSection />
      </MemoryRouter>,
    )

    expect(await screen.findByText("Llegó a su fecha sin resolver")).toBeInTheDocument()
    expect(screen.queryByText(/vencid/i)).not.toBeInTheDocument()
  })

  it("drops the sidebar count once the notice is read", async () => {
    vi.spyOn(api, "fetchMyFollowUps").mockResolvedValue([pendingFollowUp()])
    vi.spyOn(api, "fetchNotifications").mockResolvedValue([
      {
        id: "n1",
        data: { type: "follow_up_assigned", follow_up_id: 1, student_id: 3, due_date: "2026-10-30" },
        created_at: null,
      },
    ])
    vi.spyOn(api, "markNotificationRead").mockResolvedValue()
    // Server truth: one unread notice until it is marked read, then none.
    vi.spyOn(api, "fetchUnreadNotificationCount").mockResolvedValueOnce(1).mockResolvedValue(0)
    const auth: AuthContextValue = {
      user: { id: 7, name: "Paula", email: "paula@escuela.test", roles: ["psychopedagogue"] },
      loading: false,
      login: vi.fn(),
      logout: vi.fn(),
    }

    render(
      <AuthContext value={auth}>
        <MemoryRouter>
          <Sidebar />
          <FollowUpsSection />
        </MemoryRouter>
      </AuthContext>,
    )

    expect(await screen.findByRole("link", { name: "Alertas y seguimientos, 1 aviso de seguimiento sin leer" })).toBeInTheDocument()

    await userEvent.click(await screen.findByRole("button", { name: /entendido/i }))

    expect(await screen.findByRole("link", { name: "Alertas y seguimientos" })).toBeInTheDocument()
    expect(screen.queryByText(/sin leer/i)).not.toBeInTheDocument()
  })

  it("lists pending follow-ups and lets the user dismiss an assignment notice", async () => {
    vi.spyOn(api, "fetchMyFollowUps").mockResolvedValue([
      {
        id: 1,
        student_id: 3,
        student: { id: 3, full_name: "Juan Pérez" },
        description: "Revisar tiempo extendido",
        due_date: "2026-10-30",
        created_by_id: 5,
        assigned_to_id: 7,
        assigned_to: { id: 7, name: "Paula Psico", role: "psychopedagogue" },
        shared_with: [],
        resolved: false,
        resolved_by_id: null,
        resolved_at: null,
        resolution_note: null,
        is_overdue: false,
        created_at: null,
      },
    ])
    vi.spyOn(api, "fetchNotifications").mockResolvedValue([
      {
        id: "n1",
        data: { type: "follow_up_assigned", follow_up_id: 1, student_id: 3, due_date: "2026-10-30" },
        created_at: null,
      },
    ])
    const markRead = vi.spyOn(api, "markNotificationRead").mockResolvedValue()

    render(
      <MemoryRouter>
        <FollowUpsSection />
      </MemoryRouter>,
    )

    expect(await screen.findByText("Revisar tiempo extendido")).toBeInTheDocument()
    expect(screen.getByText("Juan Pérez")).toBeInTheDocument()
    expect(screen.getByText(/te asignaron un seguimiento de Juan Pérez/i)).toBeInTheDocument()

    await userEvent.click(screen.getByRole("button", { name: /entendido/i }))

    expect(markRead).toHaveBeenCalledWith("n1")
    expect(screen.queryByText(/te asignaron un seguimiento/i)).not.toBeInTheDocument()
  })
})
