import { render, screen } from "@testing-library/react"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import { AuthContext } from "@/features/auth/AuthContext"
import * as reportsApi from "@/features/reports/reportsApi"
import type { Role, User } from "@/types"
import { LimitsPage } from "./LimitsPage"

function renderAt(path: string, role: Role) {
  const user = { id: 1, name: "U", email: "u@x.uy", school_id: 1, roles: [role] } as unknown as User
  return render(
    <AuthContext.Provider value={{ user, loading: false, login: vi.fn(), logout: vi.fn() } as never}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/usuarios/limites" element={<LimitsPage />} />
        </Routes>
      </MemoryRouter>
    </AuthContext.Provider>,
  )
}

afterEach(() => vi.restoreAllMocks())

describe("LimitsPage", () => {
  it("shows the AI-in-reports switch under «Usuarios y permisos › Límites» for direction", async () => {
    vi.spyOn(reportsApi, "fetchAiReportSetting").mockResolvedValue({ enabled: false, available: true })

    renderAt("/usuarios/limites", "director")

    expect(screen.getByRole("heading", { name: "Usuarios y permisos" })).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "Límites" })).toHaveAttribute("aria-current", "page")
    expect(screen.getByRole("link", { name: "Usuarios" })).not.toHaveAttribute("aria-current")
    expect(await screen.findByRole("button", { name: "Activar la IA en informes" })).toBeInTheDocument()
    expect(screen.getByText(/No se envía nada que identifique al alumno/)).toBeInTheDocument()
  })

  it("is for direction only", () => {
    renderAt("/usuarios/limites", "psychopedagogue")

    expect(
      screen.getByRole("heading", { level: 1, name: "Esta pantalla no está habilitada para tu cuenta" }),
    ).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "Volver al inicio" })).toHaveAttribute("href", "/")
    expect(screen.queryByText(/solo para Dirección/)).not.toBeInTheDocument()
    expect(screen.queryByRole("link", { name: "Límites" })).not.toBeInTheDocument()
  })
})
