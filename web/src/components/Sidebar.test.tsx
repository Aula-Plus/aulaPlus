import { cleanup, render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { Sidebar } from "./Sidebar"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
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
})

afterEach(() => {
  cleanup()
})

describe("Sidebar", () => {
  it("shows the base navigation links", () => {
    renderSidebar()

    expect(screen.getByRole("link", { name: "Inicio" })).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "Clases" })).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "Alumnos" })).toBeInTheDocument()
  })

  it("gates the screening-tests link to psychopedagogy and direction, not teachers", () => {
    renderSidebar("psychopedagogue")
    expect(screen.getByRole("link", { name: "Pruebas de sondeo" })).toBeInTheDocument()
    cleanup()

    renderSidebar("director")
    expect(screen.getByRole("link", { name: "Pruebas de sondeo" })).toBeInTheDocument()
    cleanup()

    renderSidebar("teacher")
    expect(screen.queryByRole("link", { name: "Pruebas de sondeo" })).not.toBeInTheDocument()
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
