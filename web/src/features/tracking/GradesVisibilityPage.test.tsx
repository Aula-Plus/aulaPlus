import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, describe, expect, it, vi } from "vitest"
import { AuthContext } from "@/features/auth/AuthContext"
import type { Role, User } from "@/types"
import { GradesVisibilityPage } from "./GradesVisibilityPage"
import * as gradesVisibilityApi from "./gradesVisibilityApi"

function renderPage(role: Role) {
  const user = { id: 1, name: "Dir", email: "d@x.uy", school_id: 1, roles: [role] } as unknown as User
  return render(
    <AuthContext.Provider
      value={{ user, loading: false, login: vi.fn(), logout: vi.fn() } as never}
    >
      <GradesVisibilityPage />
    </AuthContext.Provider>,
  )
}

afterEach(() => vi.restoreAllMocks())

describe("GradesVisibilityPage", () => {
  it("saves a periodic cutoff chosen by direction", async () => {
    vi.spyOn(gradesVisibilityApi, "fetchGradesVisibility").mockResolvedValue({
      mode: "own_subject", cutoff_months: null, cutoff_anchor: null, last_cutoff_at: null,
    })
    const update = vi.spyOn(gradesVisibilityApi, "updateGradesVisibility").mockResolvedValue({
      mode: "all_periodic", cutoff_months: 3, cutoff_anchor: "2026-10-05", last_cutoff_at: null,
    })

    renderPage("director")
    await userEvent.click(await screen.findByLabelText(/con corte periódico/i))
    await userEvent.click(screen.getByRole("button", { name: "Guardar" }))

    expect(update).toHaveBeenCalledWith({ mode: "all_periodic", cutoff_months: 3 })
    expect(await screen.findByRole("status")).toHaveTextContent("Guardado.")
  })

  it("is not offered to teachers", () => {
    renderPage("teacher")
    expect(screen.getByText(/solo para dirección/i)).toBeInTheDocument()
  })
})
