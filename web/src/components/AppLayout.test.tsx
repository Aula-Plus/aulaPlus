import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { describe, expect, it, vi } from "vitest"
import { AppLayout } from "./AppLayout"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"

function renderLayout(logout = vi.fn()) {
  const value: AuthContextValue = {
    user: { id: 1, name: "Ana", email: "ana@escuela.test", roles: ["director"] },
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

  it("calls logout when the button is clicked", async () => {
    const { logout } = renderLayout()

    await userEvent.click(screen.getByRole("button", { name: /cerrar sesión/i }))

    expect(logout).toHaveBeenCalled()
  })
})
