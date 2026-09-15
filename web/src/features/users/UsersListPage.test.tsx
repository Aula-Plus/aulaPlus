import { render, screen, waitFor } from "@testing-library/react"
import { MemoryRouter } from "react-router-dom"
import { beforeEach, expect, it, vi } from "vitest"
import { UsersListPage } from "./UsersListPage"
import * as usersApi from "./usersApi"

vi.mock("@/features/auth/AuthContext", () => ({
  useAuth: () => ({ user: { id: 1, name: "D", email: "d@e.com", roles: ["director"] } }),
}))

beforeEach(() => {
  vi.spyOn(usersApi, "fetchUsers").mockResolvedValue([
    { id: 2, name: "Ana Docente", email: "ana@e.com", roles: ["teacher"], status: "pending" },
    { id: 3, name: "Beto Director", email: "beto@e.com", roles: ["director"], status: "active" },
  ])
})

it("renders staff with their Spanish status label", async () => {
  render(
    <MemoryRouter>
      <UsersListPage />
    </MemoryRouter>,
  )
  expect(await screen.findByText("Ana Docente")).toBeInTheDocument()
  await waitFor(() => expect(screen.getByText("Pendiente")).toBeInTheDocument())
  expect(screen.getByText("Activo")).toBeInTheDocument()
})
