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
    education_level: null,
    school_grade: null,
    teachers: [],
  }
}

function design(typeId: number): ScreeningTestDesign {
  return {
    id: typeId * 10,
    screening_test_type_id: typeId,
    bands: [
      { name: "Bajo", color: "red", max: 4, meaning: "r" },
      { name: "Alto", color: "green", max: null, meaning: "g" },
    ],
    reason: null,
    approved: true,
    created_by_id: 2,
    reverted_at: null,
    reverted_by_id: null,
  }
}

function type(
  id: number,
  name: string,
  withDesign: boolean,
  levels: ScreeningTestType["levels"] = ["primary"],
): ScreeningTestType {
  return {
    id,
    name,
    area: null,
    levels,
    frequency: null,
    frequency_years: null,
    teacher_visible: false,
    active: true,
    created_by_id: 2,
    current_design: withDesign ? design(id) : null,
    previous_design: null,
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

    const trigger = await screen.findByLabelText(/^screening$/i)
    // Nothing selected yet: the trigger shows the placeholder.
    expect(within(trigger).getByText("Elegí un screening…")).toBeInTheDocument()
    await userEvent.click(trigger)
    // Only the applicable type is offered; the design-less type is excluded.
    expect(await screen.findByRole("button", { name: "Comprensión lectora" })).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /sin diseño/i })).not.toBeInTheDocument()
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

    await userEvent.click(await screen.findByLabelText(/^screening$/i))
    await userEvent.click(await screen.findByRole("button", { name: "Comprensión lectora" }))
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

    expect(await screen.findByText("Screenings — 3° A")).toBeInTheDocument()
    // The application is listed…
    expect(screen.getByText("Comprensión lectora")).toBeInTheDocument()
    // …but no create form, roster sheet, or results loader (psychopedagogy only).
    expect(screen.queryByText(/nueva aplicación/i)).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /hoja para aplicar/i })).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /cargar resultados/i })).not.toBeInTheDocument()
  })

  it("does not offer a screening without population and says it has to be chosen first", async () => {
    vi.spyOn(groupsApi, "fetchGroup").mockResolvedValue(group())
    vi.spyOn(screeningApi, "fetchScreeningTestTypes").mockResolvedValue([
      type(1, "Comprensión lectora", true),
      type(2, "Cálculo", true, []),
    ])
    vi.spyOn(screeningApi, "fetchGroupScreeningTestApplications").mockResolvedValue([])

    renderPage()

    expect(await screen.findByRole("note")).toHaveTextContent(
      "«Cálculo» no tiene población seleccionada: elegí a qué niveles se aplica en el diseño antes de aplicarlo.",
    )
    await userEvent.click(screen.getByLabelText(/^screening$/i))
    expect(await screen.findByRole("button", { name: "Comprensión lectora" })).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Cálculo" })).not.toBeInTheDocument()
  })

  it("agrees in plural when several screenings have no population", async () => {
    vi.spyOn(groupsApi, "fetchGroup").mockResolvedValue(group())
    vi.spyOn(screeningApi, "fetchScreeningTestTypes").mockResolvedValue([
      type(1, "Comprensión lectora", true, []),
      type(2, "Cálculo", true, []),
    ])
    vi.spyOn(screeningApi, "fetchGroupScreeningTestApplications").mockResolvedValue([])

    renderPage()

    expect(await screen.findByRole("note")).toHaveTextContent(
      "«Comprensión lectora», «Cálculo» no tienen población seleccionada: elegí a qué niveles se aplican en el diseño antes de aplicarlos.",
    )
  })

  it("lets direction recalculate an application with the current level", async () => {
    vi.spyOn(groupsApi, "fetchGroup").mockResolvedValue(group())
    vi.spyOn(screeningApi, "fetchScreeningTestTypes").mockResolvedValue([
      type(1, "Comprensión lectora", true),
    ])
    const application = {
      id: 50,
      screening_test_type_id: 1,
      group_id: 1,
      applied_by_id: 1,
      application_date: "2026-09-15",
      counts_in_impact: false,
      recalculated_at: null,
      recalculated_by_name: null,
    }
    vi.spyOn(screeningApi, "fetchGroupScreeningTestApplications")
      .mockResolvedValueOnce([application])
      .mockResolvedValueOnce([
        {
          ...application,
          counts_in_impact: true,
          recalculated_at: "2026-10-25T10:00:00+00:00",
          recalculated_by_name: "Dora Directora",
        },
      ])
    const recalculate = vi
      .spyOn(screeningApi, "recalculateScreeningTestApplication")
      .mockResolvedValue(application)

    renderPage("director")

    expect(await screen.findByText(/no cuenta en la vista de impacto/i)).toBeInTheDocument()
    await userEvent.click(screen.getByRole("button", { name: /recalcular con el nivel actual/i }))
    await userEvent.click(await screen.findByRole("button", { name: /^recalcular$/i }))

    expect(recalculate).toHaveBeenCalledWith(50)
    expect(await screen.findByText(/recalculado con el nivel actual por dora directora/i)).toBeInTheDocument()
    expect(screen.queryByText(/no cuenta en la vista de impacto/i)).not.toBeInTheDocument()
  })
})
