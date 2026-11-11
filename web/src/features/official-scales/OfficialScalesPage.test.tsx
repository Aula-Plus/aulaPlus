import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, describe, expect, it, vi } from "vitest"
import { AuthContext } from "@/features/auth/AuthContext"
import type { OfficialScale, Role, User } from "@/types"
import { OfficialScalesPage } from "./OfficialScalesPage"
import * as officialScalesApi from "./officialScalesApi"

const PRIMARY: OfficialScale = {
  id: 1,
  framework: { id: 1, name: "ANEP EBI" },
  education_level: { key: "primary", label: "Primaria" },
  name: "Educación Primaria · Educación Básica Integrada · 1.er y 2.º ciclo",
  issuing_body: "ANEP",
  citation: { document: "REDE 2024, artículo 21", page: "14", url: "https://www.anep.edu.uy/rede.pdf" },
  levels: [
    { id: 11, position: 1, name: "Avance destacado", description: "Activa procesos complejos." },
    { id: 12, position: 2, name: "Avance significativo", description: null },
    { id: 13, position: 3, name: "Avance mínimo", description: null },
  ],
}

function renderPage(role: Role) {
  const user = { id: 1, name: "Dir", email: "d@x.uy", school_id: 1, roles: [role] } as unknown as User
  return render(
    <AuthContext.Provider value={{ user, loading: false, login: vi.fn(), logout: vi.fn() } as never}>
      <OfficialScalesPage />
    </AuthContext.Provider>,
  )
}

function mockLoad(equivalences: { level_id: number; numeric_grade: number }[] = []) {
  vi.spyOn(officialScalesApi, "fetchOfficialScales").mockResolvedValue([PRIMARY])
  vi.spyOn(officialScalesApi, "fetchNumericGradeSettings").mockResolvedValue({
    show_numeric_grade: false,
    primary_scale_id: 1,
    equivalences,
  })
}

afterEach(() => vi.restoreAllMocks())

describe("OfficialScalesPage («Escalas oficiales»)", () => {
  it("shows each scale expandable, with its levels in order and its citation, read-only", async () => {
    mockLoad()
    renderPage("director")

    expect(await screen.findByText(/ANEP EBI — Educación Primaria/)).toBeInTheDocument()
    const levels = screen.getByRole("list", { name: /Niveles de ANEP EBI/ })
    expect(Array.from(levels.querySelectorAll("li > span:first-child")).map((n) => n.textContent)).toEqual([
      "Avance destacado",
      "Avance significativo",
      "Avance mínimo",
    ])
    expect(screen.getByText(/Fuente: REDE 2024, artículo 21, p\. 14/)).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "documento oficial" })).toHaveAttribute("rel", "noopener noreferrer")
    // Nothing of the scale itself is editable: no input carries a level name.
    expect(screen.queryByRole("textbox")).not.toBeInTheDocument()
    expect(screen.queryByLabelText(/Nota del colegio/)).not.toBeInTheDocument()
  })

  it("saves the switch and the school's own equivalence for its Primaria scale", async () => {
    mockLoad()
    const update = vi.spyOn(officialScalesApi, "updateNumericGradeSettings").mockResolvedValue({
      show_numeric_grade: true,
      primary_scale_id: 1,
      equivalences: [
        { level_id: 11, numeric_grade: 10 },
        { level_id: 12, numeric_grade: 7 },
        { level_id: 13, numeric_grade: 2 },
      ],
    })
    renderPage("director")

    await userEvent.click(
      await screen.findByLabelText("Mostrar la calificación numérica junto al nivel de avance"),
    )
    await userEvent.click(screen.getByLabelText(/propia equivalencia/))
    await userEvent.selectOptions(screen.getByLabelText("Nota del colegio para Avance destacado"), "10")
    await userEvent.selectOptions(screen.getByLabelText("Nota del colegio para Avance significativo"), "7")
    await userEvent.selectOptions(screen.getByLabelText("Nota del colegio para Avance mínimo"), "2")
    await userEvent.click(screen.getByRole("button", { name: "Guardar" }))

    expect(update).toHaveBeenCalledWith({
      show_numeric_grade: true,
      equivalences: [
        { level_id: 11, numeric_grade: 10 },
        { level_id: 12, numeric_grade: 7 },
        { level_id: 13, numeric_grade: 2 },
      ],
    })
    expect(await screen.findByRole("status")).toHaveTextContent("Guardado.")
  })

  it("does not send a partial equivalence", async () => {
    mockLoad()
    const update = vi.spyOn(officialScalesApi, "updateNumericGradeSettings")
    renderPage("director")

    await userEvent.click(await screen.findByLabelText(/propia equivalencia/))
    await userEvent.selectOptions(screen.getByLabelText("Nota del colegio para Avance destacado"), "10")
    await userEvent.click(screen.getByRole("button", { name: "Guardar" }))

    expect(update).not.toHaveBeenCalled()
    expect(screen.getByRole("alert")).toHaveTextContent(/cada nivel/)
  })

  it.each(["teacher", "psychopedagogue"] as Role[])("is not available to %s", (role) => {
    const fetchScales = vi.spyOn(officialScalesApi, "fetchOfficialScales")
    renderPage(role)

    expect(screen.getByText("Esta sección es solo para Dirección.")).toBeInTheDocument()
    expect(fetchScales).not.toHaveBeenCalled()
  })
})
