import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Outlet, Route, Routes } from "react-router-dom"
import { beforeEach, expect, it, vi } from "vitest"
import { UserFormPage } from "./UserFormPage"
import * as usersApi from "./usersApi"

beforeEach(() => {
  vi.spyOn(usersApi, "createUser").mockResolvedValue({
    id: 9,
    name: "Ana",
    email: "ana@e.com",
    roles: ["teacher"],
    status: "pending",
  })
  vi.spyOn(usersApi, "updateUser").mockResolvedValue({
    id: 5,
    name: "Beto Editado",
    email: "beto@e.com",
    roles: ["director"],
    status: "active",
  })
  vi.spyOn(usersApi, "fetchUser").mockResolvedValue({
    id: 5,
    name: "Beto",
    email: "beto@e.com",
    roles: ["director"],
    status: "active",
  })
})

function renderCreate() {
  return render(
    <MemoryRouter initialEntries={["/usuarios/nueva"]}>
      <Routes>
        <Route path="/usuarios" element={<Outlet />}>
          <Route path="nueva" element={<UserFormPage />} />
        </Route>
      </Routes>
    </MemoryRouter>,
  )
}

function renderEdit() {
  return render(
    <MemoryRouter initialEntries={["/usuarios/5"]}>
      <Routes>
        <Route path="/usuarios" element={<Outlet />}>
          <Route path=":id" element={<UserFormPage />} />
        </Route>
      </Routes>
    </MemoryRouter>,
  )
}

it("creates a user and calls createUser with the entered fields", async () => {
  renderCreate()
  await userEvent.type(screen.getByLabelText("Nombre"), "Ana Docente")
  await userEvent.type(screen.getByLabelText("Email"), "ana@e.com")
  await userEvent.click(screen.getByRole("button", { name: /invitar/i }))

  expect(usersApi.createUser).toHaveBeenCalledWith({
    name: "Ana Docente",
    email: "ana@e.com",
    role: "teacher",
  })
})

it("hydrates edit mode with the user's data and shows email as read-only", async () => {
  renderEdit()

  expect(await screen.findByDisplayValue("Beto")).toBeInTheDocument()
  const emailInput = screen.getByLabelText("Email") as HTMLInputElement
  expect(emailInput).toHaveValue("beto@e.com")
  expect(emailInput).toHaveAttribute("readonly")
  expect(screen.getByText("El email no se puede modificar.")).toBeInTheDocument()
})

it("submits edits with only name and role, not email", async () => {
  renderEdit()
  await screen.findByDisplayValue("Beto")

  const nameInput = screen.getByLabelText("Nombre")
  await userEvent.clear(nameInput)
  await userEvent.type(nameInput, "Beto Editado")
  await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

  expect(usersApi.updateUser).toHaveBeenCalledWith(5, {
    name: "Beto Editado",
    role: "director",
  })
})
