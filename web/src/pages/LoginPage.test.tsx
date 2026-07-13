import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { describe, expect, it, vi } from "vitest"
import { LoginPage } from "./LoginPage"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"

function renderLogin(login = vi.fn()) {
  const value: AuthContextValue = {
    user: null,
    loading: false,
    login,
    logout: vi.fn(),
  }

  render(
    <AuthContext value={value}>
      <MemoryRouter>
        <LoginPage />
      </MemoryRouter>
    </AuthContext>,
  )

  return { login }
}

describe("LoginPage", () => {
  it("shows validation errors and does not submit when fields are empty", async () => {
    const { login } = renderLogin()

    await userEvent.click(screen.getByRole("button", { name: /ingresar/i }))

    expect(await screen.findByText(/ingresá tu email/i)).toBeInTheDocument()
    expect(login).not.toHaveBeenCalled()
  })

  it("calls login with the entered credentials on a valid submit", async () => {
    const login = vi.fn().mockResolvedValue(undefined)
    renderLogin(login)

    await userEvent.type(screen.getByLabelText(/email/i), "ana@escuela.test")
    await userEvent.type(screen.getByLabelText(/contraseña/i), "secret-pass-1")
    await userEvent.click(screen.getByRole("button", { name: /ingresar/i }))

    expect(login).toHaveBeenCalledWith("ana@escuela.test", "secret-pass-1")
  })
})
