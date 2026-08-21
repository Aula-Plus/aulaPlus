import { render, screen } from "@testing-library/react"
import { MemoryRouter } from "react-router-dom"
import { describe, expect, it, vi } from "vitest"
import { StudentsListPage } from "./StudentsListPage"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import * as studentsApi from "./studentsApi"

function renderList(role: "director" | "teacher" | "psychopedagogue") {
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
    const currentYear = new Date().getFullYear()
    vi.spyOn(studentsApi, "fetchStudents").mockResolvedValue([
      {
        id: 1,
        full_name: "Ana Gómez",
        photo_url: null,
        birth_date: null,
        enrollment_year: 2024,
        has_therapeutic_companion: false,
        groups: [{ id: 1, name: "3° A", school_year: currentYear }],
      },
    ])

    renderList("director")

    expect(await screen.findByText("Ana Gómez")).toBeInTheDocument()
    expect(screen.getByText("3° A")).toBeInTheDocument()
    expect(screen.getByRole("link", { name: /nuevo alumno/i })).toBeInTheDocument()
  })

  it("shows the create link for a psychopedagogue too", async () => {
    vi.spyOn(studentsApi, "fetchStudents").mockResolvedValue([])

    renderList("psychopedagogue")

    expect(await screen.findByText(/todavía no hay alumnos/i)).toBeInTheDocument()
    expect(screen.getByRole("link", { name: /nuevo alumno/i })).toBeInTheDocument()
  })

  it("shows a dash when the student has no group for the current school year", async () => {
    vi.spyOn(studentsApi, "fetchStudents").mockResolvedValue([
      {
        id: 1,
        full_name: "Ana Gómez",
        photo_url: null,
        birth_date: null,
        enrollment_year: 2024,
        has_therapeutic_companion: false,
        groups: [{ id: 1, name: "3° A", school_year: 2020 }],
      },
    ])

    renderList("director")

    const row = (await screen.findByText("Ana Gómez")).closest("tr")
    expect(row).not.toBeNull()
    expect(row!.textContent).toContain("—")
  })

  it("hides the create link for a teacher", async () => {
    vi.spyOn(studentsApi, "fetchStudents").mockResolvedValue([])

    renderList("teacher")

    expect(await screen.findByText(/todavía no hay alumnos/i)).toBeInTheDocument()
    expect(screen.queryByRole("link", { name: /nuevo alumno/i })).not.toBeInTheDocument()
  })
})
