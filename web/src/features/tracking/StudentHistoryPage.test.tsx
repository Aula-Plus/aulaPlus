import { act, fireEvent, render, screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import { StudentHistoryPage } from "./StudentHistoryPage"
import * as trackingApi from "./trackingApi"
import type { StudentHistoryEntry, StudentHistoryPage as HistoryPage } from "@/types"

function entry(overrides: Partial<StudentHistoryEntry> = {}): StudentHistoryEntry {
  return {
    id: 1,
    created_at: "2026-08-20T10:00:00+00:00",
    action: "updated",
    section: "accommodations",
    origin: "user",
    actor: { id: 5, name: "Paula Psico", role: "psychopedagogue" },
    element: { type: "accommodation", id: 42, label: "Tiempo extra" },
    changes: [{ field: "description", before: "Texto anterior", after: "Texto nuevo" }],
    ...overrides,
  }
}

function page(data: StudentHistoryEntry[], meta: Partial<HistoryPage["meta"]> = {}): HistoryPage {
  return {
    data,
    meta: { current_page: 1, last_page: 1, total: data.length, ...meta },
    actors: [
      { id: 5, name: "Paula Psico", role: "psychopedagogue" },
      { id: 7, name: "Dora Directora", role: "director" },
    ],
  }
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={["/alumnos/3/historial"]}>
      <Routes>
        <Route path="/alumnos/:id/historial" element={<StudentHistoryPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

function triggers() {
  // Persona, Sección, Acción — in that order on the page.
  return screen.getAllByTestId("single-select-trigger")
}

describe("StudentHistoryPage", () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("shows who (with role), which section, element and action, and the old → new value", async () => {
    vi.spyOn(trackingApi, "fetchStudentHistory").mockResolvedValue(page([entry()]))

    renderPage()

    expect(await screen.findByRole("heading", { name: "Historial de cambios" })).toBeInTheDocument()
    const item = within(screen.getByRole("list", { name: /cambios registrados/i })).getByRole("listitem")
    expect(within(item).getByText("Modificación")).toBeInTheDocument()
    expect(within(item).getByText("Ajustes")).toBeInTheDocument()
    expect(within(item).getByText("Ajuste: Tiempo extra")).toBeInTheDocument()
    expect(within(item).getByText("Paula Psico · Psicopedagogía")).toBeInTheDocument()
    expect(within(item).getByText("Descripción")).toBeInTheDocument()
    expect(within(item).getByText("Texto anterior")).toBeInTheDocument()
    expect(within(item).getByText("Texto nuevo")).toBeInTheDocument()
    expect(within(item).getByText(/valor anterior/i)).toBeInTheDocument()
  })

  it("shows what was added on an alta and what was removed on a baja", async () => {
    vi.spyOn(trackingApi, "fetchStudentHistory").mockResolvedValue(
      page([
        entry({
          id: 1,
          action: "created",
          section: "observations",
          element: { type: "comment", id: 9, label: "Participa más" },
          changes: [
            { field: "content", before: null, after: "Participa más" },
            { field: "visible_to", before: null, after: ["psychopedagogue", "director"] },
          ],
        }),
        entry({
          id: 2,
          action: "deleted",
          section: "barriers",
          element: { type: "barrier", id: 4, label: null },
          changes: [{ field: "active", before: true, after: null }],
        }),
      ]),
    )

    renderPage()

    const [added, removed] = within(
      await screen.findByRole("list", { name: /cambios registrados/i }),
    ).getAllByRole("listitem")

    expect(within(added).getByText("Alta")).toBeInTheDocument()
    expect(within(added).getByText("Se agregó")).toBeInTheDocument()
    expect(within(added).getByText("Observación: Participa más")).toBeInTheDocument()
    expect(within(added).getByText("Psicopedagogía, Dirección")).toBeInTheDocument()

    expect(within(removed).getByText("Baja")).toBeInTheDocument()
    expect(within(removed).getByText("Se quitó")).toBeInTheDocument()
    expect(within(removed).getByText("Barrera #4")).toBeInTheDocument()
    expect(within(removed).getByText("Sí")).toBeInTheDocument()
  })

  it("labels system changes and redacted fields without inventing content", async () => {
    vi.spyOn(trackingApi, "fetchStudentHistory").mockResolvedValue(
      page([
        entry({
          origin: "system",
          actor: null,
          section: "reports",
          element: { type: "technical_report", id: 8, label: null },
          changes: [{ field: "document_url", redacted: true }],
        }),
      ]),
    )

    renderPage()

    expect(await screen.findByText("Sistema")).toBeInTheDocument()
    expect(screen.getByText("Informe #8")).toBeInTheDocument()
    expect(screen.getByText(/contenido no disponible/i)).toBeInTheDocument()
  })

  it("filters by persona, sección, acción and fechas through the API", async () => {
    const fetchSpy = vi.spyOn(trackingApi, "fetchStudentHistory").mockResolvedValue(page([entry()]))

    renderPage()
    await screen.findByText("Ajuste: Tiempo extra")
    expect(fetchSpy).toHaveBeenLastCalledWith(3, 1, {})

    await userEvent.click(triggers()[0])
    await userEvent.click(await screen.findByRole("button", { name: "Dora Directora (Dirección)" }))
    expect(fetchSpy).toHaveBeenLastCalledWith(3, 1, { user_id: 7 })

    await userEvent.click(triggers()[1])
    await userEvent.click(await screen.findByRole("button", { name: "Observaciones" }))
    expect(fetchSpy).toHaveBeenLastCalledWith(3, 1, { user_id: 7, section: "observations" })

    await userEvent.click(triggers()[2])
    await userEvent.click(await screen.findByRole("button", { name: "Bajas" }))
    expect(fetchSpy).toHaveBeenLastCalledWith(3, 1, {
      user_id: 7,
      section: "observations",
      action: "deleted",
    })

    fireEvent.change(screen.getByLabelText("Desde"), { target: { value: "2026-10-01" } })
    fireEvent.change(screen.getByLabelText("Hasta"), { target: { value: "2026-10-05" } })
    expect(fetchSpy).toHaveBeenLastCalledWith(3, 1, {
      user_id: 7,
      section: "observations",
      action: "deleted",
      from: new Date(2026, 9, 1).toISOString(),
      to: new Date(2026, 9, 5, 23, 59, 59, 999).toISOString(),
    })

    await userEvent.click(screen.getByRole("button", { name: /limpiar filtros/i }))
    expect(fetchSpy).toHaveBeenLastCalledWith(3, 1, {})
  })

  it("paginates: prev is disabled on page 1, next requests page 2 with the same filters", async () => {
    const fetchSpy = vi.spyOn(trackingApi, "fetchStudentHistory")
    fetchSpy.mockResolvedValueOnce(page([entry({ id: 1 })], { current_page: 1, last_page: 2, total: 25 }))
    fetchSpy.mockResolvedValueOnce(page([entry({ id: 2 })], { current_page: 2, last_page: 2, total: 25 }))

    renderPage()

    await screen.findByText(/página 1 de 2/i)
    expect(screen.getByRole("button", { name: /anterior/i })).toBeDisabled()

    await userEvent.click(screen.getByRole("button", { name: /siguiente/i }))
    expect(fetchSpy).toHaveBeenLastCalledWith(3, 2, {})
    await screen.findByText(/página 2 de 2/i)
    expect(screen.getByRole("button", { name: /siguiente/i })).toBeDisabled()
  })

  it("names the student's group «Grupo» and shows an observation's categories before → after", async () => {
    vi.spyOn(trackingApi, "fetchStudentHistory").mockResolvedValue(
      page([
        entry({
          id: 1,
          section: "student_data",
          element: { type: "student", id: 3, label: null },
          changes: [{ field: "group", before: "3°A", after: "3°B" }],
        }),
        entry({
          id: 2,
          section: "observations",
          element: { type: "comment", id: 9, label: "Habla de la mudanza" },
          changes: [{ field: "categories", before: ["Familiar"], after: [] }],
        }),
      ]),
    )

    renderPage()

    const [group, categories] = within(
      await screen.findByRole("list", { name: /cambios registrados/i }),
    ).getAllByRole("listitem")

    expect(within(group).getByText("Grupo")).toBeInTheDocument()
    expect(within(group).queryByText("Clase")).not.toBeInTheDocument()
    expect(within(categories).getByText("Categorías")).toBeInTheDocument()
    expect(within(categories).getByText("Familiar")).toBeInTheDocument()
    expect(within(categories).getByText("Sin categoría")).toBeInTheDocument()
  })

  it("ignores a slower response for filters that are no longer selected", async () => {
    let resolveStale: (value: HistoryPage) => void = () => {}
    const stale = new Promise<HistoryPage>((resolve) => {
      resolveStale = resolve
    })
    const fetchSpy = vi.spyOn(trackingApi, "fetchStudentHistory")
    fetchSpy.mockResolvedValueOnce(page([entry({ id: 1 })]))
    fetchSpy.mockReturnValueOnce(stale)
    fetchSpy.mockResolvedValueOnce(
      page([entry({ id: 3, element: { type: "barrier", id: 4, label: "Atención" } })]),
    )

    renderPage()
    await screen.findByText("Ajuste: Tiempo extra")

    fireEvent.change(screen.getByLabelText("Desde"), { target: { value: "2026-10-01" } })
    fireEvent.change(screen.getByLabelText("Desde"), { target: { value: "2026-10-02" } })
    expect(await screen.findByText("Barrera: Atención")).toBeInTheDocument()

    await act(async () => {
      resolveStale(page([entry({ id: 2, element: { type: "accommodation", id: 1, label: "Viejo" } })]))
      await stale
    })

    expect(screen.getByText("Barrera: Atención")).toBeInTheDocument()
    expect(screen.queryByText("Ajuste: Viejo")).not.toBeInTheDocument()
  })

  it("shows an empty state when there are no changes", async () => {
    vi.spyOn(trackingApi, "fetchStudentHistory").mockResolvedValue(page([]))

    renderPage()

    expect(await screen.findByText(/todavía no hay cambios registrados/i)).toBeInTheDocument()
  })
})
