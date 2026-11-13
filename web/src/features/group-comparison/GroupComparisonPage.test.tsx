import { render, screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import type { Role } from "@/types"
import * as comparisonApi from "./groupComparisonApi"
import { GroupComparisonPage } from "./GroupComparisonPage"

const options: comparisonApi.ComparisonOption[] = [
  {
    school_grade_id: 8,
    school_grade: "5° de Primaria",
    school_year: 2026,
    groups: [
      { id: 1, name: "5.°A" },
      { id: 2, name: "5.°B" },
    ],
  },
]

const comparison: comparisonApi.GroupComparison = {
  school_grade: "5° de Primaria",
  school_year: 2026,
  groups: [
    {
      id: 1,
      name: "5.°A",
      student_count: 16,
      subject_averages: [{ subject_id: 3, subject_name: "Matemática", average: 7.5, results_count: 30, level_results_count: 0 }],
      accommodations_by_type: [{ type: "Tiempo extendido 25%", category: "access", student_count: 2 }],
      screenings: [
        {
          screening_test_type_id: 9,
          name: "Comprensión lectora",
          application_date: "2026-10-01",
          bands: [
            { name: "Inicial", count: 1 },
            { name: "En proceso", count: 10 },
            { name: "Adecuado", count: 5 },
          ],
        },
      ],
    },
    {
      id: 2,
      name: "5.°B",
      student_count: 16,
      subject_averages: [{ subject_id: 3, subject_name: "Matemática", average: 7, results_count: 28, level_results_count: 0 }],
      accommodations_by_type: [],
      screenings: [],
    },
  ],
}

function renderPage(role: Role) {
  const value: AuthContextValue = {
    user: { id: 1, name: "Adriana", email: "adriana@escuela.test", roles: [role] },
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
  }
  return render(
    <AuthContext value={value}>
      <MemoryRouter>
        <GroupComparisonPage />
      </MemoryRouter>
    </AuthContext>,
  )
}

describe("GroupComparisonPage", () => {
  afterEach(() => vi.restoreAllMocks())

  it("puts the groups of the first comparable year side by side", async () => {
    vi.spyOn(comparisonApi, "fetchComparisonOptions").mockResolvedValue(options)
    const fetch = vi.spyOn(comparisonApi, "fetchGroupComparison").mockResolvedValue(comparison)

    renderPage("director")

    const table = await screen.findByRole("table")
    expect(fetch).toHaveBeenCalledWith(8, 2026)
    const headers = within(table).getAllByRole("columnheader").map((cell) => cell.textContent)
    expect(headers).toEqual(["Variable", "5.°A", "5.°B"])
    const average = within(table).getByText("Promedio Matemática").closest("tr")!
    expect(within(average).getByText("7,5")).toBeInTheDocument()
    expect(within(average).getByText("7")).toBeInTheDocument()
    expect(within(table).getByText("Tiempo extendido 25%: 2")).toBeInTheDocument()
    expect(within(table).getByText("Sin ajustes vigentes")).toBeInTheDocument()
    expect(within(table).getByText("Inicial 1 · En proceso 10 · Adecuado 5")).toBeInTheDocument()
    expect(within(table).getByText("Sin tomas")).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "Evolución de 5.°A" })).toHaveAttribute("href", "/clases/1/seguimiento")
    expect(screen.getByRole("link", { name: "Comentarios de 5.°B" })).toHaveAttribute("href", "/clases/2/seguimiento")
  })

  it("tells scale-level results and an application without scores apart from no data", async () => {
    vi.spyOn(comparisonApi, "fetchComparisonOptions").mockResolvedValue(options)
    const [first, second] = comparison.groups
    vi.spyOn(comparisonApi, "fetchGroupComparison").mockResolvedValue({
      ...comparison,
      groups: [
        {
          ...first,
          subject_averages: [
            { subject_id: 4, subject_name: "Lengua", average: null, results_count: 0, level_results_count: 16 },
          ],
          screenings: [{ ...first.screenings[0], bands: [] }],
        },
        second,
      ],
    })

    renderPage("director")

    const table = await screen.findByRole("table")
    const language = within(table).getByText("Promedio Lengua").closest("tr")!
    expect(within(language).getByText("Sin nota numérica (16 resultados por nivel)")).toBeInTheDocument()
    expect(within(language).getByText("Sin calificaciones")).toBeInTheDocument()
    expect(within(table).getByText("Sin resultados cargados")).toBeInTheDocument()
  })

  it("turns a variable off and on", async () => {
    vi.spyOn(comparisonApi, "fetchComparisonOptions").mockResolvedValue(options)
    vi.spyOn(comparisonApi, "fetchGroupComparison").mockResolvedValue(comparison)

    renderPage("psychopedagogue")
    await screen.findByRole("table")

    const toggle = screen.getByRole("button", { name: "Screenings" })
    await userEvent.click(toggle)
    expect(toggle).toHaveAttribute("aria-pressed", "false")
    expect(screen.queryByText("Comprensión lectora")).not.toBeInTheDocument()

    await userEvent.click(toggle)
    expect(screen.getByText("Comprensión lectora")).toBeInTheDocument()
  })

  it("explains when no year has two groups", async () => {
    vi.spyOn(comparisonApi, "fetchComparisonOptions").mockResolvedValue([])

    renderPage("director")

    expect(await screen.findByText(/Todavía no hay un año de cursada con dos o más grupos/)).toBeInTheDocument()
  })

  it("is not available to teachers", () => {
    const fetchOptions = vi.spyOn(comparisonApi, "fetchComparisonOptions")

    renderPage("teacher")

    expect(screen.getByText("Esta pantalla no está disponible para tu cuenta")).toBeInTheDocument()
    expect(fetchOptions).not.toHaveBeenCalled()
  })
})
