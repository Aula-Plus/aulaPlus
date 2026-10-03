import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import { StudentHistoryPage } from "./StudentHistoryPage"
import * as trackingApi from "./trackingApi"
import type { AuditLogEntry, Paginated } from "@/types"

function entry(overrides: Partial<AuditLogEntry> = {}): AuditLogEntry {
  return {
    id: 1,
    auditable_type: "Accommodation",
    auditable_id: 42,
    action: "updated",
    user_id: 5,
    origin: "user",
    changes: { approved: { before: null, after: true } },
    created_at: "2026-08-20T10:00:00+00:00",
    ...overrides,
  }
}

function page(
  data: AuditLogEntry[],
  meta: Partial<Paginated<AuditLogEntry>["meta"]> = {},
): Paginated<AuditLogEntry> {
  return {
    data,
    meta: {
      current_page: 1,
      last_page: 1,
      total: data.length,
      ...meta,
    },
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

describe("StudentHistoryPage", () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("renders 'Sistema' (not 'Usuario #null') for a system-origin entry", async () => {
    vi.spyOn(trackingApi, "fetchStudentHistory").mockResolvedValue(
      page([entry({ id: 2, origin: "system", user_id: null })]),
    )

    renderPage()

    expect(await screen.findByText("Sistema")).toBeInTheDocument()
    expect(screen.queryByText(/usuario #null/i)).not.toBeInTheDocument()
  })

  it("renders 'Usuario #<id>' for a user-origin entry with a user_id", async () => {
    vi.spyOn(trackingApi, "fetchStudentHistory").mockResolvedValue(
      page([entry({ id: 3, origin: "user", user_id: 12 })]),
    )

    renderPage()

    expect(await screen.findByText("Usuario #12")).toBeInTheDocument()
  })

  it("paginates: prev is disabled on page 1, next requests page 2", async () => {
    const fetchSpy = vi.spyOn(trackingApi, "fetchStudentHistory")
    fetchSpy.mockResolvedValueOnce(
      page([entry({ id: 1 })], { current_page: 1, last_page: 2, total: 25 }),
    )
    fetchSpy.mockResolvedValueOnce(
      page([entry({ id: 2 })], { current_page: 2, last_page: 2, total: 25 }),
    )

    renderPage()

    // First load — page 1.
    await screen.findByText(/página 1 de 2/i)
    expect(screen.getByRole("button", { name: /anterior/i })).toBeDisabled()

    await userEvent.click(screen.getByRole("button", { name: /siguiente/i }))
    expect(fetchSpy).toHaveBeenLastCalledWith(3, 2)
    await screen.findByText(/página 2 de 2/i)
    expect(screen.getByRole("button", { name: /siguiente/i })).toBeDisabled()
  })

  it("hides `changes` until 'Ver detalle' is clicked, then prints them as JSON", async () => {
    vi.spyOn(trackingApi, "fetchStudentHistory").mockResolvedValue(
      page([entry({ id: 4, changes: { approved: { before: null, after: true } } })]),
    )

    renderPage()

    await screen.findByText("Actualizado")
    // Not visible before clicking.
    expect(screen.queryByText(/"approved"/)).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole("button", { name: /ver detalle/i }))
    // After clicking, JSON is on-screen; testing-library normalizes whitespace,
    // so match against the flat form.
    expect(await screen.findByText(/"approved":/)).toBeInTheDocument()
  })

  it("shows an empty state when there are no rows", async () => {
    vi.spyOn(trackingApi, "fetchStudentHistory").mockResolvedValue(page([]))

    renderPage()

    expect(await screen.findByText(/no hay registros en el historial/i)).toBeInTheDocument()
  })
})
