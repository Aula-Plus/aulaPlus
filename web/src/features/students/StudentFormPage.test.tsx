import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { describe, expect, it, vi } from "vitest"
import { StudentFormPage } from "./StudentFormPage"
import * as studentsApi from "./studentsApi"
import * as groupsApi from "@/features/groups/groupsApi"

function renderCreate() {
  return render(
    <MemoryRouter initialEntries={["/alumnos/nuevo"]}>
      <Routes>
        <Route path="/alumnos/nuevo" element={<StudentFormPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

describe("StudentFormPage", () => {
  it("shows validation errors and does not submit when required fields are empty", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])
    const createStudent = vi.spyOn(studentsApi, "createStudent")

    renderCreate()

    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(await screen.findByText(/ingresá un nombre/i)).toBeInTheDocument()
    expect(createStudent).not.toHaveBeenCalled()
  })

  it("calls createStudent with the entered values on a valid submit", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])
    const createStudent = vi.spyOn(studentsApi, "createStudent").mockResolvedValue({
      id: 1,
      full_name: "Ana Gómez",
      photo_url: null,
      birth_date: null,
      enrollment_year: 2026,
      has_therapeutic_companion: false,
      groups: [],
    })

    renderCreate()

    await userEvent.type(screen.getByLabelText(/nombre completo/i), "Ana Gómez")
    await userEvent.type(screen.getByLabelText(/año de ingreso/i), "2026")
    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(createStudent).toHaveBeenCalledWith(
      expect.objectContaining({ full_name: "Ana Gómez", enrollment_year: 2026 }),
    )
  })

  it("only lists groups for the current school year", async () => {
    const currentYear = new Date().getFullYear()
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([
      {
        id: 1,
        name: "3° A (actual)",
        level: null,
        school_year: currentYear,
        group_profile: null,
        related_documents: null,
        teachers: [],
      },
      {
        id: 2,
        name: "2° A (pasado)",
        level: null,
        school_year: currentYear - 1,
        group_profile: null,
        related_documents: null,
        teachers: [],
      },
    ])

    renderCreate()

    expect(await screen.findByRole("option", { name: "3° A (actual)" })).toBeInTheDocument()
    expect(screen.queryByRole("option", { name: "2° A (pasado)" })).not.toBeInTheDocument()
  })
})
