import { render, screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import { ScreeningTestDesignPage } from "./ScreeningTestDesignPage"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import * as screeningApi from "./screeningTestsApi"
import type { Role, ScreeningTestDesign, ScreeningTestType } from "@/types"

function type(withDesign = false): ScreeningTestType {
  return {
    id: 1,
    name: "Comprensión lectora",
    active: true,
    created_by_id: 2,
    current_design: withDesign
      ? {
          id: 10,
          screening_test_type_id: 1,
          cutoff_low: 4,
          cutoff_high: 7,
          meaning_red: "r",
          meaning_yellow: "y",
          meaning_green: "g",
          approved: true,
          approved_by_id: 9,
          created_by_id: 2,
        }
      : null,
  }
}

function pendingDesign(): ScreeningTestDesign {
  return {
    id: 11,
    screening_test_type_id: 1,
    cutoff_low: 4,
    cutoff_high: 7,
    meaning_red: "Necesita apoyo",
    meaning_yellow: "En proceso",
    meaning_green: "Logrado",
    approved: null,
    approved_by_id: null,
    created_by_id: 1,
  }
}

function renderPage(role: Role = "psychopedagogue") {
  const value: AuthContextValue = {
    user: { id: 1, name: "Ana", email: "ana@escuela.test", roles: [role] },
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
  }
  return render(
    <AuthContext value={value}>
      <MemoryRouter>
        <ScreeningTestDesignPage />
      </MemoryRouter>
    </AuthContext>,
  )
}

describe("ScreeningTestDesignPage", () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("lets psychopedagogy create a type", async () => {
    vi.spyOn(screeningApi, "fetchScreeningTestTypes").mockResolvedValue([])
    const create = vi
      .spyOn(screeningApi, "createScreeningTestType")
      .mockResolvedValue(type())

    renderPage()

    await userEvent.type(await screen.findByLabelText(/nombre del tipo/i), "Cálculo mental")
    await userEvent.click(screen.getByRole("button", { name: /crear tipo/i }))

    await waitFor(() => expect(create).toHaveBeenCalledWith({ name: "Cálculo mental" }))
  })

  it("shows a just-created design as pending, without approve buttons for psychopedagogy", async () => {
    vi.spyOn(screeningApi, "fetchScreeningTestTypes").mockResolvedValue([type(false)])
    const create = vi
      .spyOn(screeningApi, "createScreeningTestDesign")
      .mockResolvedValue(pendingDesign())

    renderPage()

    // Wait for the type to render (its "Sin diseño aprobado." note).
    expect(await screen.findByText("Sin diseño aprobado.")).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText(/corte bajo/i), "4")
    await userEvent.type(screen.getByLabelText(/corte alto/i), "7")
    await userEvent.type(screen.getByLabelText(/significado rojo/i), "Necesita apoyo")
    await userEvent.type(screen.getByLabelText(/significado amarillo/i), "En proceso")
    await userEvent.type(screen.getByLabelText(/significado verde/i), "Logrado")
    await userEvent.click(screen.getByRole("button", { name: /guardar diseño/i }))

    await waitFor(() =>
      expect(create).toHaveBeenCalledWith(1, {
        cutoff_low: 4,
        cutoff_high: 7,
        meaning_red: "Necesita apoyo",
        meaning_yellow: "En proceso",
        meaning_green: "Logrado",
      }),
    )

    expect(await screen.findByText("Pendiente de aprobación")).toBeInTheDocument()
    // A psychopedagogue is not a director: no approve/reject on the pending design.
    expect(screen.queryByRole("button", { name: "Aprobar" })).not.toBeInTheDocument()
  })

  it("blocks a design whose low cutoff is not below the high cutoff (UX validation)", async () => {
    vi.spyOn(screeningApi, "fetchScreeningTestTypes").mockResolvedValue([type(false)])
    const create = vi.spyOn(screeningApi, "createScreeningTestDesign")

    renderPage()

    await screen.findByText("Sin diseño aprobado.")

    await userEvent.type(screen.getByLabelText(/corte bajo/i), "7")
    await userEvent.type(screen.getByLabelText(/corte alto/i), "4")
    await userEvent.type(screen.getByLabelText(/significado rojo/i), "a")
    await userEvent.type(screen.getByLabelText(/significado amarillo/i), "b")
    await userEvent.type(screen.getByLabelText(/significado verde/i), "c")
    await userEvent.click(screen.getByRole("button", { name: /guardar diseño/i }))

    expect(await screen.findByText(/el corte alto debe ser mayor que el bajo/i)).toBeInTheDocument()
    expect(create).not.toHaveBeenCalled()
  })

  it("does not show create forms to a director (they can only approve)", async () => {
    vi.spyOn(screeningApi, "fetchScreeningTestTypes").mockResolvedValue([type(false)])

    renderPage("director")

    expect(await screen.findByText("Comprensión lectora")).toBeInTheDocument()
    expect(screen.queryByLabelText(/nombre del tipo/i)).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /guardar diseño/i })).not.toBeInTheDocument()
  })
})
