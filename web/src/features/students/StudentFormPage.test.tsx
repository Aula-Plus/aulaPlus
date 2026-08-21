import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import { StudentFormPage } from "./StudentFormPage"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import * as studentsApi from "./studentsApi"
import * as groupsApi from "@/features/groups/groupsApi"
import type { Role } from "@/types"

function renderCreate(role: Role = "director") {
  const value: AuthContextValue = {
    user: { id: 1, name: "Ana", email: "ana@escuela.test", roles: [role] },
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
  }

  return render(
    <AuthContext value={value}>
      <MemoryRouter initialEntries={["/alumnos/nuevo"]}>
        <Routes>
          <Route path="/alumnos/nuevo" element={<StudentFormPage />} />
        </Routes>
      </MemoryRouter>
    </AuthContext>,
  )
}

function renderEdit(role: Role = "director", id = "1") {
  const value: AuthContextValue = {
    user: { id: 1, name: "Ana", email: "ana@escuela.test", roles: [role] },
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
  }

  return render(
    <AuthContext value={value}>
      <MemoryRouter initialEntries={[`/alumnos/${id}`]}>
        <Routes>
          <Route path="/alumnos/:id" element={<StudentFormPage />} />
        </Routes>
      </MemoryRouter>
    </AuthContext>,
  )
}

describe("StudentFormPage", () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

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

  it("shows the clinical profile section for a director", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])

    renderCreate("director")

    expect(await screen.findByLabelText(/perfil de aprendizaje/i)).toBeInTheDocument()
  })

  it("shows the clinical profile section for a psychopedagogue", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])

    renderCreate("psychopedagogue")

    expect(await screen.findByLabelText(/perfil de aprendizaje/i)).toBeInTheDocument()
  })

  it("hides the clinical profile section for a teacher", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])

    renderCreate("teacher")

    await screen.findByLabelText(/nombre completo/i)
    expect(screen.queryByLabelText(/perfil de aprendizaje/i)).not.toBeInTheDocument()
  })

  it("rejects invalid JSON in a clinical field without submitting", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])
    const createStudent = vi.spyOn(studentsApi, "createStudent")

    renderCreate("director")

    await userEvent.type(screen.getByLabelText(/nombre completo/i), "Ana Gómez")
    await userEvent.type(screen.getByLabelText(/año de ingreso/i), "2026")
    await userEvent.type(screen.getByLabelText(/perfil de aprendizaje/i), "no es json")
    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(await screen.findByText(/ingresá un json válido/i)).toBeInTheDocument()
    expect(createStudent).not.toHaveBeenCalled()
  })

  it("sends parsed JSON clinical fields on a valid submit", async () => {
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

    renderCreate("director")

    await userEvent.type(screen.getByLabelText(/nombre completo/i), "Ana Gómez")
    await userEvent.type(screen.getByLabelText(/año de ingreso/i), "2026")
    await userEvent.type(screen.getByLabelText(/perfil de aprendizaje/i), '{{"style":"visual"}')
    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(createStudent).toHaveBeenCalledWith(
      expect.objectContaining({ learning_profile: { style: "visual" } }),
    )
  })

  describe("editing an existing student", () => {
    it("preselects the student's current-year group and prefills the clinical profile", async () => {
      const currentYear = new Date().getFullYear()
      vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([
        {
          id: 1,
          name: "3° A",
          level: null,
          school_year: currentYear,
          group_profile: null,
          related_documents: null,
          teachers: [],
        },
      ])
      vi.spyOn(studentsApi, "fetchStudent").mockResolvedValue({
        id: 1,
        full_name: "Ana Gómez",
        photo_url: null,
        birth_date: null,
        enrollment_year: 2024,
        has_therapeutic_companion: false,
        groups: [{ id: 1, name: "3° A", school_year: currentYear }],
        learning_profile: { style: "visual" },
        tracking_notes: "Progresa bien.",
        individual_profile: null,
        related_documents: null,
      })

      renderEdit("director")

      const select = (await screen.findByLabelText(/clase/i)) as HTMLSelectElement
      expect(select.value).toBe("1")
      expect(await screen.findByLabelText(/notas de seguimiento/i)).toHaveValue("Progresa bien.")
    })

    it("shows a delete button and calls deleteStudent after confirming", async () => {
      vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])
      vi.spyOn(studentsApi, "fetchStudent").mockResolvedValue({
        id: 1,
        full_name: "Ana Gómez",
        photo_url: null,
        birth_date: null,
        enrollment_year: 2024,
        has_therapeutic_companion: false,
        groups: [],
      })
      const deleteStudent = vi.spyOn(studentsApi, "deleteStudent").mockResolvedValue(undefined)

      renderEdit("director")

      const deleteButton = await screen.findByRole("button", { name: /eliminar alumno/i })
      await userEvent.click(deleteButton)
      await userEvent.click(await screen.findByRole("button", { name: "Eliminar" }))

      expect(deleteStudent).toHaveBeenCalledWith(1)
    })
  })
})
