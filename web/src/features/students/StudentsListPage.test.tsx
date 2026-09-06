import { render, screen } from "@testing-library/react"
import { MemoryRouter } from "react-router-dom"
import { describe, expect, it, vi } from "vitest"
import { StudentsListPage } from "./StudentsListPage"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import * as studentsApi from "./studentsApi"

function renderList(role: "director" | "teacher") {
  const value: AuthContextValue = {
    user: { id: 1, name: "Ana", email: "ana@escuela.test", roles: [role] },
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
  }

  return render(
    <AuthContext value={value}>
      <MemoryRouter>
        <StudentsListPage />
      </MemoryRouter>
    </AuthContext>,
  )
}

describe("StudentsListPage", () => {
  it("renders students returned by the API and shows the create link for a director", async () => {
    vi.spyOn(studentsApi, "fetchStudents").mockResolvedValue([
      {
        id: 1,
        first_name: "Ana",
        last_name: "Gómez",
        full_name: "Ana Gómez",
        birth_date: null,
        status: "active",
        family_contact_name: null,
        family_contact_phone: null,
        family_contact_email: null,
        pedagogical_notes: null,
        group_id: null,
        group: null,
      },
    ])

    renderList("director")

    expect(await screen.findByText("Ana Gómez")).toBeInTheDocument()
    expect(screen.getByText("Activo")).toBeInTheDocument()
    expect(screen.getByRole("link", { name: /nuevo alumno/i })).toBeInTheDocument()
  })

  it("hides the create link for a teacher", async () => {
    vi.spyOn(studentsApi, "fetchStudents").mockResolvedValue([])

    renderList("teacher")

    expect(await screen.findByText(/todavía no hay alumnos/i)).toBeInTheDocument()
    expect(screen.queryByRole("link", { name: /nuevo alumno/i })).not.toBeInTheDocument()
  })
})
