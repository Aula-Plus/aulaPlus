import { act, cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import { GlobalSearch } from "./GlobalSearch"
import * as searchApi from "./searchApi"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import type { Role, SearchResults } from "@/types"

const results: SearchResults = {
  students: [{ id: 7, full_name: "Martina López", group: { id: 3, name: "3° A" } }],
  groups: [{ id: 3, name: "Martes 3° A", level: "Primaria", school_year: 2026 }],
  assessments: [
    {
      id: 11,
      title: "Escrito de martes",
      type: "written",
      subject: "Matemática",
      group: { id: 3, name: "3° A" },
      date: "2026-05-12",
    },
  ],
}

function Where() {
  const location = useLocation()
  return <p data-testid="where">{location.pathname}</p>
}

function renderSearch(role: Role = "teacher") {
  const value: AuthContextValue = {
    user: { id: 1, name: "Ana", email: "ana@escuela.test", roles: [role] },
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
  }
  render(
    <AuthContext value={value}>
      <MemoryRouter initialEntries={["/calendario"]}>
        <GlobalSearch />
        <Routes>
          <Route path="*" element={<Where />} />
        </Routes>
      </MemoryRouter>
    </AuthContext>,
  )
}

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
})

describe("GlobalSearch", () => {
  it("opens with Ctrl+K and with ⌘K", async () => {
    renderSearch()

    fireEvent.keyDown(window, { key: "k", ctrlKey: true })
    expect(await screen.findByRole("dialog", { name: "Buscar en Aula+" })).toBeInTheDocument()

    fireEvent.keyDown(document.activeElement ?? window, { key: "Escape" })
    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument())

    fireEvent.keyDown(window, { key: "K", metaKey: true })
    expect(await screen.findByRole("dialog")).toBeInTheDocument()
  })

  it("asks for at least two letters before searching", async () => {
    const spy = vi.spyOn(searchApi, "searchAula").mockResolvedValue(results)
    const user = userEvent.setup()
    renderSearch()

    await user.click(screen.getByRole("button", { name: /buscar/i }))
    await user.type(screen.getByRole("combobox"), "m")

    expect(screen.getByRole("status")).toHaveTextContent("Escribí al menos 2 letras para buscar.")
    await new Promise((resolve) => setTimeout(resolve, 300))
    expect(spy).not.toHaveBeenCalled()
  })

  it("debounces, groups results by type and opens each one where it belongs", async () => {
    const spy = vi.spyOn(searchApi, "searchAula").mockResolvedValue(results)
    const user = userEvent.setup()
    renderSearch()

    await user.click(screen.getByRole("button", { name: /buscar/i }))
    await user.type(screen.getByRole("combobox"), "mart")

    await screen.findByRole("option", { name: /Martina López/ })
    // One request for the whole word, not one per keystroke.
    expect(spy).toHaveBeenCalledTimes(1)
    expect(spy.mock.calls[0][0]).toBe("mart")

    expect(screen.getByRole("group", { name: "Alumnos" })).toBeInTheDocument()
    expect(screen.getByRole("group", { name: "Grupos" })).toBeInTheDocument()
    expect(screen.getByRole("group", { name: "Evaluaciones" })).toBeInTheDocument()
    expect(screen.getByRole("option", { name: /Escrito de martes/ })).toHaveTextContent("Matemática · 3° A")

    await user.click(screen.getByRole("option", { name: /Escrito de martes/ }))
    expect(screen.getByTestId("where")).toHaveTextContent("/clases/3/evaluaciones")
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument()
  })

  it("navigates with the keyboard", async () => {
    vi.spyOn(searchApi, "searchAula").mockResolvedValue(results)
    const user = userEvent.setup()
    renderSearch()

    await user.click(screen.getByRole("button", { name: /buscar/i }))
    const input = screen.getByRole("combobox")
    await user.type(input, "mart")
    const first = await screen.findByRole("option", { name: /Martina López/ })
    expect(first).toHaveAttribute("aria-selected", "true")
    expect(input).toHaveAttribute("aria-activedescendant", first.id)

    await user.keyboard("{ArrowDown}")
    expect(screen.getByRole("option", { name: /Martes 3° A/ })).toHaveAttribute("aria-selected", "true")
    await user.keyboard("{Enter}")
    expect(screen.getByTestId("where")).toHaveTextContent("/clases/3/seguimiento")
  })

  it("opens a student's file", async () => {
    vi.spyOn(searchApi, "searchAula").mockResolvedValue(results)
    const user = userEvent.setup()
    renderSearch()

    await user.click(screen.getByRole("button", { name: /buscar/i }))
    await user.type(screen.getByRole("combobox"), "mart")
    await user.click(await screen.findByRole("option", { name: /Martina López/ }))
    expect(screen.getByTestId("where")).toHaveTextContent("/alumnos/7/seguimiento")
  })

  it("finds only the menu sections of the role, ignoring accents", async () => {
    vi.spyOn(searchApi, "searchAula").mockResolvedValue({ students: [], groups: [], assessments: [] })
    const user = userEvent.setup()
    renderSearch("teacher")

    await user.click(screen.getByRole("button", { name: /buscar/i }))
    await user.type(screen.getByRole("combobox"), "usuar")
    await waitFor(() =>
      expect(screen.getByRole("status")).toHaveTextContent("No hay coincidencias en lo que podés consultar."),
    )

    await user.clear(screen.getByRole("combobox"))
    await user.type(screen.getByRole("combobox"), "calendario")
    const section = await screen.findByRole("option", { name: /Calendario/ })
    expect(screen.getByRole("group", { name: "Secciones" })).toContainElement(section)
  })

  it("shows the director's admin sections", async () => {
    vi.spyOn(searchApi, "searchAula").mockResolvedValue({ students: [], groups: [], assessments: [] })
    const user = userEvent.setup()
    renderSearch("director")

    await user.click(screen.getByRole("button", { name: /buscar/i }))
    await user.type(screen.getByRole("combobox"), "ano y calen")
    await user.click(await screen.findByRole("option", { name: /Año y calendario/ }))
    expect(screen.getByTestId("where")).toHaveTextContent("/administracion/ano-y-calendario")
  })

  it("says so when the search fails", async () => {
    vi.spyOn(searchApi, "searchAula").mockRejectedValue(new Error("network"))
    const user = userEvent.setup()
    renderSearch()

    await user.click(screen.getByRole("button", { name: /buscar/i }))
    await act(async () => {
      await user.type(screen.getByRole("combobox"), "mart")
    })
    await waitFor(() =>
      expect(screen.getByRole("status")).toHaveTextContent("No se pudo buscar. Probá de nuevo en unos segundos."),
    )
  })
})
