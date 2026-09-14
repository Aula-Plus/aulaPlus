import { render, screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import { ScreeningTestApplicationPage } from "./ScreeningTestApplicationPage"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import * as groupsApi from "@/features/groups/groupsApi"
import * as screeningApi from "./screeningTestsApi"
import type { Group, Role, ScreeningTestDesign, ScreeningTestType } from "@/types"

function group(): Group {
  return {
    id: 1,
    name: "3° A",
    level: "Primaria",
    school_year: 2026,
    group_profile: null,
    related_documents: null,
    teachers: [],
  }
}

function design(typeId: number): ScreeningTestDesign {
  return {
    id: typeId * 10,
    screening_test_type_id: typeId,
    cutoff_low: 4,
    cutoff_high: 7,
    meaning_red: "r",
    meaning_yellow: "y",
    meaning_green: "g",
    approved: true,
    approved_by_id: 9,
    created_by_id: 2,
  }
}

function type(id: number, name: string, withDesign: boolean): ScreeningTestType {
  return {
    id,
    name,
    active: true,
    created_by_id: 2,
    current_design: withDesign ? design(id) : null,
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
      <MemoryRouter initialEntries={["/clases/1/pruebas-de-sondeo"]}>
        <Routes>
          <Route path="/clases/:id/pruebas-de-sondeo" element={<ScreeningTestApplicationPage />} />
        </Routes>
      </MemoryRouter>
    </AuthContext>,
  )
}

describe("ScreeningTestApplicationPage", () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("offers only types with an approved design in the Nueva aplicación select", async () => {
    vi.spyOn(groupsApi, "fetchGroup").mockResolvedValue(group())
    vi.spyOn(screeningApi, "fetchScreeningTestTypes").mockResolvedValue([
      type(1, "Comprensión lectora", true),
      type(2, "Cálculo (sin diseño)", false),
    ])
    vi.spyOn(screeningApi, "fetchGroupScreeningTestApplications").mockResolvedValue([])

    renderPage()

    const select = await screen.findByLabelText(/tipo de prueba/i)
    const options = within(select).getAllByRole("option")
    // Placeholder + the single applicable type; the design-less type is excluded.
    expect(options.map((option) => option.textContent)).toEqual([
      "Elegí un tipo…",
      "Comprensión lectora",
    ])
    expect(screen.queryByRole("option", { name: /sin diseño/i })).not.toBeInTheDocument()
  })

  it("sends the chosen type and date when creating an application", async () => {
    vi.spyOn(groupsApi, "fetchGroup").mockResolvedValue(group())
    vi.spyOn(screeningApi, "fetchScreeningTestTypes").mockResolvedValue([
      type(1, "Comprensión lectora", true),
    ])
    vi.spyOn(screeningApi, "fetchGroupScreeningTestApplications").mockResolvedValue([])
    const create = vi
      .spyOn(screeningApi, "createScreeningTestApplication")
      .mockResolvedValue({
        id: 50,
        screening_test_type_id: 1,
        group_id: 1,
        applied_by_id: 1,
        application_date: "2026-09-15",
      })

    renderPage()

    await userEvent.selectOptions(await screen.findByLabelText(/tipo de prueba/i), "1")
    const dateInput = screen.getByLabelText(/fecha/i)
    await userEvent.clear(dateInput)
    await userEvent.type(dateInput, "2026-09-15")
    await userEvent.click(screen.getByRole("button", { name: /crear aplicación/i }))

    await waitFor(() =>
      expect(create).toHaveBeenCalledWith(1, {
        screening_test_type_id: 1,
        application_date: "2026-09-15",
      }),
    )
  })

  it("does not show psychopedagogy tools to a director viewing the list", async () => {
    vi.spyOn(groupsApi, "fetchGroup").mockResolvedValue(group())
    vi.spyOn(screeningApi, "fetchScreeningTestTypes").mockResolvedValue([
      type(1, "Comprensión lectora", true),
    ])
    vi.spyOn(screeningApi, "fetchGroupScreeningTestApplications").mockResolvedValue([
      {
        id: 50,
        screening_test_type_id: 1,
        group_id: 1,
        applied_by_id: 1,
        application_date: "2026-09-15",
        results_count: 3,
      },
    ])

    renderPage("director")

    expect(await screen.findByText("Pruebas de sondeo — 3° A")).toBeInTheDocument()
    // The application is listed…
    expect(screen.getByText("Comprensión lectora")).toBeInTheDocument()
    // …but no create form, roster sheet, or results loader (psychopedagogy only).
    expect(screen.queryByText(/nueva aplicación/i)).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /hoja para aplicar/i })).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /cargar resultados/i })).not.toBeInTheDocument()
  })
})
