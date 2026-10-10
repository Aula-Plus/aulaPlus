import { act, cleanup, render, screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { Sidebar } from "./Sidebar"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import * as scheduledFollowUpsApi from "@/features/tracking/scheduledFollowUpsApi"
import { resetUnreadNoticeCount } from "@/features/tracking/unreadNotices"
import type { Role } from "@/types"

function renderSidebar(role: Role = "director", logout = vi.fn()) {
  const value: AuthContextValue = {
    user: { id: 1, name: "Ana", email: "ana@escuela.test", roles: [role] },
    loading: false,
    login: vi.fn(),
    logout,
  }

  render(
    <AuthContext value={value}>
      <MemoryRouter>
        <Sidebar />
      </MemoryRouter>
    </AuthContext>,
  )

  return { logout }
}

beforeEach(() => {
  localStorage.clear()
  resetUnreadNoticeCount()
  vi.spyOn(scheduledFollowUpsApi, "fetchUnreadNotificationCount").mockResolvedValue(0)
})

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
})

describe("Sidebar", () => {
  it("shows the base navigation links", () => {
    renderSidebar()

    expect(screen.getByRole("link", { name: "Inicio" })).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "Grupos" })).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "Alumnos" })).toBeInTheDocument()
  })

  it("lists Grupos and Alumnos directly, without a 'Seguimiento' section heading", () => {
    renderSidebar()

    expect(screen.getByRole("link", { name: "Grupos" })).toHaveAttribute("href", "/clases")
    expect(screen.getByRole("link", { name: "Alumnos" })).toHaveAttribute("href", "/alumnos")
    expect(screen.queryByRole("heading", { name: /seguimiento/i })).not.toBeInTheDocument()
  })

  it("shows the unread notice count next to 'Mis seguimientos'", async () => {
    vi.spyOn(scheduledFollowUpsApi, "fetchUnreadNotificationCount").mockResolvedValue(2)
    renderSidebar("psychopedagogue")

    expect(
      await screen.findByRole("link", { name: "Mis seguimientos, 2 avisos sin leer" }),
    ).toHaveAttribute("href", "/mis-seguimientos")
  })

  it("shows no count next to 'Mis seguimientos' when every notice is read", async () => {
    const fetchCount = vi
      .spyOn(scheduledFollowUpsApi, "fetchUnreadNotificationCount")
      .mockResolvedValue(0)
    renderSidebar("psychopedagogue")

    await waitFor(() => expect(fetchCount).toHaveBeenCalled())
    expect(screen.getByRole("link", { name: "Mis seguimientos" })).toBeInTheDocument()
    expect(screen.queryByText(/sin leer/i)).not.toBeInTheDocument()
  })

  it("shows '50+' past the number of notices the page lists", async () => {
    vi.spyOn(scheduledFollowUpsApi, "fetchUnreadNotificationCount").mockResolvedValue(51)
    renderSidebar("psychopedagogue")

    expect(
      await screen.findByRole("link", { name: "Mis seguimientos, más de 50 avisos sin leer" }),
    ).toBeInTheDocument()
    expect(screen.getByText("50+")).toBeInTheDocument()
  })

  it("does not carry the previous user's count over to the next login", async () => {
    let releaseA!: (count: number) => void
    vi.spyOn(scheduledFollowUpsApi, "fetchUnreadNotificationCount")
      .mockResolvedValueOnce(3)
      .mockReturnValueOnce(new Promise<number>((resolve) => (releaseA = resolve)))
      .mockResolvedValue(0)
    const auth = (user: AuthContextValue["user"]): AuthContextValue => ({
      user,
      loading: false,
      login: vi.fn(),
      logout: vi.fn(),
    })
    const tree = (value: AuthContextValue) => (
      <AuthContext value={value}>
        <MemoryRouter>
          <Sidebar />
        </MemoryRouter>
      </AuthContext>
    )
    const userA = { id: 1, name: "Ana", email: "ana@escuela.test", roles: ["psychopedagogue" as Role] }
    const userB = { id: 2, name: "Bruno", email: "bruno@escuela.test", roles: ["teacher" as Role] }

    const { rerender } = render(tree(auth(userA)))
    expect(await screen.findByRole("link", { name: "Mis seguimientos, 3 avisos sin leer" })).toBeInTheDocument()

    // A navigates (a refresh starts and hangs), logs out, then B logs in.
    await userEvent.click(screen.getByRole("link", { name: "Alumnos" }))
    rerender(tree(auth(null)))
    rerender(tree(auth(userB)))
    expect(screen.getByRole("link", { name: "Mis seguimientos" })).toBeInTheDocument()

    // A's late answer must not show up for B.
    await act(async () => releaseA(7))
    expect(screen.getByText("Bruno")).toBeInTheDocument()
    expect(screen.queryByText(/sin leer/i)).not.toBeInTheDocument()
  })

  it("shows the help and suggestions link to every role", () => {
    for (const role of ["teacher", "psychopedagogue", "director"] as const) {
      renderSidebar(role)
      expect(screen.getByRole("link", { name: "Ayuda y sugerencias" })).toHaveAttribute(
        "href",
        "/ayuda",
      )
      cleanup()
    }
  })

  it("gates the screening-tests link to psychopedagogy and direction, not teachers", () => {
    renderSidebar("psychopedagogue")
    expect(screen.getByRole("link", { name: "Screenings" })).toBeInTheDocument()
    cleanup()

    renderSidebar("director")
    expect(screen.getByRole("link", { name: "Screenings" })).toBeInTheDocument()
    cleanup()

    renderSidebar("teacher")
    expect(screen.queryByRole("link", { name: "Screenings" })).not.toBeInTheDocument()
  })

  it("shows the adoption link and its section only to a director", () => {
    renderSidebar("director")
    expect(screen.getByRole("link", { name: "Adopción" })).toBeInTheDocument()
    expect(screen.getByRole("heading", { name: /administración/i })).toBeInTheDocument()
    cleanup()

    renderSidebar("teacher")
    expect(screen.queryByRole("link", { name: "Adopción" })).not.toBeInTheDocument()
    // The empty "Administración" section must not render for a teacher.
    expect(screen.queryByRole("heading", { name: /administración/i })).not.toBeInTheDocument()
  })

  it("toggles collapsed state and persists the preference", async () => {
    renderSidebar()

    const collapse = screen.getByRole("button", { name: "Contraer menú" })
    await userEvent.click(collapse)

    expect(screen.getByRole("button", { name: "Expandir menú" })).toBeInTheDocument()
    expect(localStorage.getItem("aulaplus:sidebar-collapsed")).toBe("1")
  })

  it("starts collapsed when the stored preference says so", () => {
    localStorage.setItem("aulaplus:sidebar-collapsed", "1")
    renderSidebar()

    expect(screen.getByRole("button", { name: "Expandir menú" })).toBeInTheDocument()
  })

  it("opens the mobile drawer with the hamburger and closes it on Escape", async () => {
    renderSidebar()

    expect(screen.queryByRole("button", { name: "Cerrar menú" })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole("button", { name: "Abrir menú" }))
    expect(screen.getByRole("button", { name: "Cerrar menú" })).toBeInTheDocument()

    await userEvent.keyboard("{Escape}")
    expect(screen.queryByRole("button", { name: "Cerrar menú" })).not.toBeInTheDocument()
  })

  it("calls logout when the logout button is clicked", async () => {
    const { logout } = renderSidebar()

    await userEvent.click(screen.getByRole("button", { name: /cerrar sesión/i }))

    expect(logout).toHaveBeenCalled()
  })
})
