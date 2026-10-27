import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { GroupFormPage } from "./GroupFormPage"
import * as groupsApi from "./groupsApi"

// The form reads the current user (to gate the assignments panel) and mounts
// <GroupTeacherAssignments> when editing. Teacher membership is managed there,
// per subject — the form itself no longer touches teachers. Stub both; the panel
// has its own test in features/subjects.
vi.mock("@/features/auth/AuthContext", () => ({
  useAuth: () => ({ user: { id: 1, name: "Dir", email: "d@x.com", roles: ["director"] } }),
}))
vi.mock("@/features/subjects/GroupTeacherAssignments", () => ({
  GroupTeacherAssignments: () => null,
}))

function renderCreate() {
  return render(
    <MemoryRouter initialEntries={["/clases/nueva"]}>
      <Routes>
        <Route path="/clases/nueva" element={<GroupFormPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

function renderEdit(id = "1") {
  return render(
    <MemoryRouter initialEntries={[`/clases/${id}`]}>
      <Routes>
        <Route path="/clases/:id" element={<GroupFormPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

const levels = {
  data: [
    { id: 2, key: "primary", label: "Primaria", grades: [{ id: 6, key: "primary_3", label: "3° de Primaria" }] },
    { id: 3, key: "lower_secondary", label: "Ciclo Básico", grades: [{ id: 11, key: "grade_8", label: "8°" }] },
    { id: 4, key: "upper_secondary", label: "Bachillerato", grades: [] },
  ],
  groups_without_level: 0,
}

describe("GroupFormPage", () => {
  beforeEach(() => {
    vi.spyOn(groupsApi, "fetchEducationLevels").mockResolvedValue(levels)
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("shows a validation error and does not submit when name is empty", async () => {
    const createGroup = vi.spyOn(groupsApi, "createGroup")

    renderCreate()

    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(await screen.findByText(/ingresá un nombre/i)).toBeInTheDocument()
    expect(createGroup).not.toHaveBeenCalled()
  })

  it("suggests the level from the grade, lets it be changed, and sends both apart", async () => {
    const createGroup = vi.spyOn(groupsApi, "createGroup").mockResolvedValue({
      id: 1,
      name: "3° A",
      level: null,
      education_level: null,
      school_grade: null,
      school_year: 2026,
      group_profile: null,
      related_documents: null,
      teachers: [],
    })

    renderCreate()

    await userEvent.type(screen.getByLabelText(/nombre/i), "8° A")
    await screen.findByRole("option", { name: "8°" })
    await userEvent.selectOptions(screen.getByLabelText(/grado/i), "8°")
    expect(screen.getByLabelText(/^nivel$/i)).toHaveValue("3")

    // The suggestion is only a help: direction picks another level.
    await userEvent.selectOptions(screen.getByLabelText(/^nivel$/i), "Bachillerato")
    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    // The form no longer sends teacher_ids — teacher membership is per-subject
    // and handled by the assignment panel (backend Sesión 2).
    expect(createGroup).toHaveBeenCalledWith({
      name: "8° A",
      education_level_id: 4,
      school_grade_id: 11,
      school_year: expect.any(Number),
    })
  })

  it("requires a level but not a grade", async () => {
    const createGroup = vi.spyOn(groupsApi, "createGroup").mockResolvedValue({} as never)

    renderCreate()

    await userEvent.type(screen.getByLabelText(/nombre/i), "Taller")
    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))
    expect(await screen.findByText("Elegí el nivel", { selector: "p" })).toBeInTheDocument()
    expect(createGroup).not.toHaveBeenCalled()

    await screen.findByRole("option", { name: "Ciclo Básico" })
    await userEvent.selectOptions(screen.getByLabelText(/^nivel$/i), "Ciclo Básico")
    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))
    expect(createGroup).toHaveBeenCalledWith(
      expect.objectContaining({ education_level_id: 3, school_grade_id: null }),
    )
  })

  it("does not show a delete button when creating a new group", () => {
    renderCreate()

    expect(screen.queryByRole("button", { name: /eliminar grupo/i })).not.toBeInTheDocument()
  })

  it("does not render a teacher multi-select (membership is managed per subject)", () => {
    renderCreate()

    expect(screen.queryByLabelText(/docentes/i)).not.toBeInTheDocument()
  })

  describe("editing an existing group", () => {
    beforeEach(() => {
      vi.spyOn(groupsApi, "fetchGroup").mockResolvedValue({
        id: 1,
        name: "3° A",
        level: "Primaria",
        school_year: 2026,
        group_profile: null,
        related_documents: null,
        education_level: null,
        school_grade: null,
        teachers: [{ id: 5, name: "Ana Ruiz" }],
      })
    })

    it("preloads the group's fields", async () => {
      renderEdit()

      expect(await screen.findByDisplayValue("3° A")).toBeInTheDocument()
    })

    it("shows a delete button and calls deleteGroup after confirming", async () => {
      const deleteGroup = vi.spyOn(groupsApi, "deleteGroup").mockResolvedValue(undefined)

      renderEdit()

      const deleteButton = await screen.findByRole("button", { name: /eliminar grupo/i })
      await userEvent.click(deleteButton)
      await userEvent.click(await screen.findByRole("button", { name: "Eliminar" }))

      expect(deleteGroup).toHaveBeenCalledWith(1)
    })

    it("does not call deleteGroup when the confirmation is cancelled", async () => {
      const deleteGroup = vi.spyOn(groupsApi, "deleteGroup")

      renderEdit()

      const deleteButton = await screen.findByRole("button", { name: /eliminar grupo/i })
      await userEvent.click(deleteButton)
      await userEvent.click(await screen.findByRole("button", { name: "Cancelar" }))

      expect(deleteGroup).not.toHaveBeenCalled()
    })
  })
})
