import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { describe, expect, it, vi } from "vitest"
import { StudentFormPage } from "./StudentFormPage"
import * as studentsApi from "./studentsApi"
import * as groupsApi from "@/features/groups/groupsApi"

describe("StudentFormPage", () => {
  it("shows validation errors and does not submit when required fields are empty", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])
    const createStudent = vi.spyOn(studentsApi, "createStudent")

    render(
      <MemoryRouter initialEntries={["/alumnos/nuevo"]}>
        <Routes>
          <Route path="/alumnos/nuevo" element={<StudentFormPage />} />
        </Routes>
      </MemoryRouter>,
    )

    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(await screen.findByText(/ingresá un nombre/i)).toBeInTheDocument()
    expect(createStudent).not.toHaveBeenCalled()
  })

  it("calls createStudent with the entered values on a valid submit", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([
      { id: 1, name: "3° A", level: null, year: null, teacher_id: null, teacher: null },
    ])
    const createStudent = vi.spyOn(studentsApi, "createStudent").mockResolvedValue({
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
    })

    render(
      <MemoryRouter initialEntries={["/alumnos/nuevo"]}>
        <Routes>
          <Route path="/alumnos/nuevo" element={<StudentFormPage />} />
        </Routes>
      </MemoryRouter>,
    )

    await userEvent.type(screen.getByLabelText(/nombre/i), "Ana")
    await userEvent.type(screen.getByLabelText(/apellido/i), "Gómez")
    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(createStudent).toHaveBeenCalledWith(
      expect.objectContaining({ first_name: "Ana", last_name: "Gómez", status: "active" }),
    )
  })
})
