import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, describe, expect, it, vi } from "vitest"
import { AuthContext } from "@/features/auth/AuthContext"
import type { Role, User } from "@/types"
import { AiReportSettingPage } from "./AiReportSettingPage"
import * as reportsApi from "./reportsApi"

function renderPage(role: Role) {
  const user = { id: 1, name: "U", email: "u@x.uy", school_id: 1, roles: [role] } as unknown as User
  return render(
    <AuthContext.Provider value={{ user, loading: false, login: vi.fn(), logout: vi.fn() } as never}>
      <AiReportSettingPage />
    </AuthContext.Provider>,
  )
}

afterEach(() => vi.restoreAllMocks())

describe("AiReportSettingPage", () => {
  it("lets direction switch the AI on and explains what happens to the data", async () => {
    vi.spyOn(reportsApi, "fetchAiReportSetting").mockResolvedValue({ enabled: false, available: true })
    const update = vi
      .spyOn(reportsApi, "updateAiReportSetting")
      .mockResolvedValue({ enabled: true, available: true })

    renderPage("director")
    expect(await screen.findByText(/Desactivada/)).toBeInTheDocument()
    expect(screen.getByText(/No se envía nada que identifique al alumno/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole("button", { name: "Activar la IA en informes" }))

    expect(update).toHaveBeenCalledWith(true)
    expect(await screen.findByText(/Activada/)).toBeInTheDocument()
  })

  it("tells direction when Aula+ has the feature paused for everyone", async () => {
    vi.spyOn(reportsApi, "fetchAiReportSetting").mockResolvedValue({ enabled: true, available: false })

    renderPage("director")

    expect(await screen.findByText(/pausada por ahora/)).toBeInTheDocument()
  })

  it("is for direction only", () => {
    renderPage("teacher")

    expect(screen.getByText("Esta sección es solo para Dirección.")).toBeInTheDocument()
  })
})
