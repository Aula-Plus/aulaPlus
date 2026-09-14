import { render, screen } from "@testing-library/react"
import { MemoryRouter } from "react-router-dom"
import { describe, expect, it, vi } from "vitest"
import { AppLayout } from "./AppLayout"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"

function renderLayout() {
  const value: AuthContextValue = {
    user: { id: 1, name: "Ana", email: "ana@escuela.test", roles: ["director"] },
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
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
}

describe("AppLayout", () => {
  it("renders the sidebar navigation and the wrapped content", () => {
    renderLayout()

    // Nav links live in the sidebar (rendered by <Sidebar />).
    expect(screen.getByRole("link", { name: "Clases" })).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "Alumnos" })).toBeInTheDocument()
    expect(screen.getByText("contenido")).toBeInTheDocument()
  })
})
