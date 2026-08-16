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
      year: "",
      teacher_id: null,
      teacher: null,
    })

    renderCreate()

    await userEvent.type(screen.getByLabelText(/nombre/i), "3° A")
    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(createGroup).toHaveBeenCalledWith({ name: "3° A", level: "", year: "" })
  })

  it("does not show a delete button when creating a new group", () => {
    renderCreate()

    expect(screen.queryByRole("button", { name: /eliminar clase/i })).not.toBeInTheDocument()
  })

  describe("editing an existing group", () => {
    beforeEach(() => {
      vi.spyOn(groupsApi, "fetchGroup").mockResolvedValue({
        id: 1,
        name: "3° A",
        level: "Primaria",
        year: "2026",
        teacher_id: null,
        teacher: null,
      })
    })

    it("shows a delete button and calls deleteGroup after confirming", async () => {
      const deleteGroup = vi.spyOn(groupsApi, "deleteGroup").mockResolvedValue(undefined)
      vi.stubGlobal("confirm", vi.fn(() => true))

      renderEdit()

      const deleteButton = await screen.findByRole("button", { name: /eliminar clase/i })
      await userEvent.click(deleteButton)

      expect(deleteGroup).toHaveBeenCalledWith(1)

      vi.unstubAllGlobals()
    })

    it("does not call deleteGroup when the confirmation is cancelled", async () => {
      const deleteGroup = vi.spyOn(groupsApi, "deleteGroup")
      vi.stubGlobal("confirm", vi.fn(() => false))

      renderEdit()

      const deleteButton = await screen.findByRole("button", { name: /eliminar clase/i })
      await userEvent.click(deleteButton)

      expect(deleteGroup).not.toHaveBeenCalled()

      vi.unstubAllGlobals()
    })
  })
})
