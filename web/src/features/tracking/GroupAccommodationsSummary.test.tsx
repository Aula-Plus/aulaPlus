import { render, screen, within } from "@testing-library/react"
import { afterEach, describe, expect, it, vi } from "vitest"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import type { GroupAccommodationSummaryEntry, Role } from "@/types"
import { GroupAccommodationsSummary } from "./GroupAccommodationsSummary"
import * as trackingApi from "./trackingApi"

function summary(): GroupAccommodationSummaryEntry[] {
  return [
    { type: "tiempo extra", category: "access", student_count: 3 },
    { type: "ortografía no puntúa", category: "criteria", student_count: 1 },
  ]
}

function renderAs(role: Role) {
  const value: AuthContextValue = {
    user: { id: 1, name: "Ana", email: "ana@escuela.test", roles: [role] },
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
  }
  return render(
    <AuthContext value={value}>
      <GroupAccommodationsSummary groupId={1} />
    </AuthContext>,
  )
}

describe("GroupAccommodationsSummary", () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  // Spec §7: the table shows the same for `teacher` as for school-wide roles —
  // the endpoint is aggregate-only (never names a student), so there is no extra
  // clinical gate. Proven by rendering the same data for a teacher and a
  // director and getting identical rows.
  it("shows the aggregated table identically for teacher and school-wide roles (no role gate)", async () => {
    vi.spyOn(trackingApi, "fetchGroupAccommodationsSummary").mockResolvedValue(summary())

    const teacherView = renderAs("teacher")
    expect(await screen.findByText("tiempo extra")).toBeInTheDocument()
    const teacherRow = screen.getByText("tiempo extra").closest("tr")!
    expect(within(teacherRow).getByText("Acceso")).toBeInTheDocument()
    expect(within(teacherRow).getByText("3")).toBeInTheDocument()
    teacherView.unmount()

    // A school-wide role sees the exact same content — nothing is hidden or
    // added by role.
    renderAs("director")
    expect(await screen.findByText("tiempo extra")).toBeInTheDocument()
    const directorRow = screen.getByText("tiempo extra").closest("tr")!
    expect(within(directorRow).getByText("Acceso")).toBeInTheDocument()
    expect(within(directorRow).getByText("3")).toBeInTheDocument()
    expect(screen.getByText("ortografía no puntúa")).toBeInTheDocument()
    expect(screen.getByText("Criterio")).toBeInTheDocument()
  })

  it("shows an empty-state message when the group has no active accommodations", async () => {
    vi.spyOn(trackingApi, "fetchGroupAccommodationsSummary").mockResolvedValue([])

    renderAs("teacher")

    expect(await screen.findByText("La clase no tiene ajustes activos.")).toBeInTheDocument()
    expect(screen.queryByRole("table")).not.toBeInTheDocument()
  })
})
