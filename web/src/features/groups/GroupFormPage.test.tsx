import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { GroupFormPage } from "./GroupFormPage"
import * as groupsApi from "./groupsApi"

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

describe("GroupFormPage", () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  beforeEach(() => {
    vi.spyOn(groupsApi, "fetchTeachers").mockResolvedValue([])
  })

  it("shows a validation error and does not submit when name is empty", async () => {
    const createGroup = vi.spyOn(groupsApi, "createGroup")

    renderCreate()

    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(await screen.findByText(/ingresá un nombre/i)).toBeInTheDocument()
    expect(createGroup).not.toHaveBeenCalled()
  })

  it("calls createGroup with the entered values on a valid submit", async () => {
    const createGroup = vi.spyOn(groupsApi, "createGroup").mockResolvedValue({
      id: 1,
      name: "3° A",
      level: "",
      school_year: 2026,
      group_profile: null,
      related_documents: null,
      teachers: [],
    })

    renderCreate()

    await userEvent.type(screen.getByLabelText(/nombre/i), "3° A")
    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(createGroup).toHaveBeenCalledWith({
      name: "3° A",
      level: "",
      school_year: expect.any(Number),
      teacher_ids: [],
    })
  })

  it("does not show a delete button when creating a new group", () => {
    renderCreate()

    expect(screen.queryByRole("button", { name: /eliminar clase/i })).not.toBeInTheDocument()
  })

  it("selects teachers via the multi-select and submits their ids", async () => {
    vi.spyOn(groupsApi, "fetchTeachers").mockResolvedValue([
      { id: 5, name: "Ana Ruiz" },
      { id: 9, name: "Zoe Diaz" },
    ])
    const createGroup = vi.spyOn(groupsApi, "createGroup").mockResolvedValue({
      id: 1,
      name: "3° A",
      level: "",
      school_year: 2026,
      group_profile: null,
      related_documents: null,
      teachers: [{ id: 5, name: "Ana Ruiz" }],
    })

    renderCreate()

    await userEvent.type(screen.getByLabelText(/nombre/i), "3° A")
    await userEvent.click(screen.getByLabelText(/docentes/i))
    await userEvent.click(await screen.findByRole("button", { name: "Ana Ruiz" }))
    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(createGroup).toHaveBeenCalledWith(
      expect.objectContaining({ name: "3° A", teacher_ids: [5] }),
    )
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
        teachers: [{ id: 5, name: "Ana Ruiz" }],
      })
      vi.spyOn(groupsApi, "fetchTeachers").mockResolvedValue([
        { id: 5, name: "Ana Ruiz" },
        { id: 9, name: "Zoe Diaz" },
      ])
    })

    it("preselects the group's current teachers", async () => {
      renderEdit()

      expect(await screen.findByText("Ana Ruiz")).toBeInTheDocument()
    })

    it("shows a delete button and calls deleteGroup after confirming", async () => {
      const deleteGroup = vi.spyOn(groupsApi, "deleteGroup").mockResolvedValue(undefined)

      renderEdit()

      const deleteButton = await screen.findByRole("button", { name: /eliminar clase/i })
      await userEvent.click(deleteButton)
      await userEvent.click(await screen.findByRole("button", { name: "Eliminar" }))

      expect(deleteGroup).toHaveBeenCalledWith(1)
    })

    it("does not call deleteGroup when the confirmation is cancelled", async () => {
      const deleteGroup = vi.spyOn(groupsApi, "deleteGroup")

      renderEdit()

      const deleteButton = await screen.findByRole("button", { name: /eliminar clase/i })
      await userEvent.click(deleteButton)
      await userEvent.click(await screen.findByRole("button", { name: "Cancelar" }))

      expect(deleteGroup).not.toHaveBeenCalled()
    })
  })
})
