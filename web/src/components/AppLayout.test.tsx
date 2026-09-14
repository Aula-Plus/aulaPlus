import { cleanup, render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { describe, expect, it, vi } from "vitest"
import { AppLayout } from "./AppLayout"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"

function renderLayout(logout = vi.fn(), role: "director" | "teacher" | "psychopedagogue" = "director") {
  const value: AuthContextValue = {
    user: { id: 1, name: "Ana", email: "ana@escuela.test", roles: [role] },
    loading: false,
    login: vi.fn(),
    logout,
  }

  render(
    <AuthContext value={value}>
      <MemoryRouter>
        <AppLayout>
          <p>contenido</p>
        </AppLayout>
      </MemoryRouter>
    </AuthContext>,
  )

  return { logout }
}

describe("AppLayout", () => {
  it("shows navigation links and the wrapped content", () => {
    renderLayout()

    expect(screen.getByRole("link", { name: "Clases" })).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "Alumnos" })).toBeInTheDocument()
    expect(screen.getByText("contenido")).toBeInTheDocument()
  })

  it("shows the screening-tests nav link to psychopedagogy and direction but not to a teacher", () => {
    renderLayout(vi.fn(), "psychopedagogue")
    expect(screen.getByRole("link", { name: "Pruebas de sondeo" })).toBeInTheDocument()
    cleanup()

    renderLayout(vi.fn(), "director")
    expect(screen.getByRole("link", { name: "Pruebas de sondeo" })).toBeInTheDocument()
    cleanup()

    renderLayout(vi.fn(), "teacher")
    expect(screen.queryByRole("link", { name: "Pruebas de sondeo" })).not.toBeInTheDocument()
  })

  it("calls logout when the button is clicked", async () => {
    const { logout } = renderLayout()

    await userEvent.click(screen.getByRole("button", { name: /cerrar sesión/i }))

    expect(logout).toHaveBeenCalled()
  })
})
