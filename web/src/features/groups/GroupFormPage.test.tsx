import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { describe, expect, it, vi } from "vitest"
import { GroupFormPage } from "./GroupFormPage"
import * as groupsApi from "./groupsApi"

describe("GroupFormPage", () => {
  it("shows a validation error and does not submit when name is empty", async () => {
    const createGroup = vi.spyOn(groupsApi, "createGroup")

    render(
      <MemoryRouter initialEntries={["/clases/nueva"]}>
        <Routes>
          <Route path="/clases/nueva" element={<GroupFormPage />} />
        </Routes>
      </MemoryRouter>,
    )

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

    render(
      <MemoryRouter initialEntries={["/clases/nueva"]}>
        <Routes>
          <Route path="/clases/nueva" element={<GroupFormPage />} />
        </Routes>
      </MemoryRouter>,
    )

    await userEvent.type(screen.getByLabelText(/nombre/i), "3° A")
    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(createGroup).toHaveBeenCalledWith({ name: "3° A", level: "", year: "" })
  })
})
