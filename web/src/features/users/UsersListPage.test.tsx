import { render, screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, beforeEach, expect, it, vi } from "vitest"
import { UsersListPage } from "./UsersListPage"
import * as usersApi from "./usersApi"

// Current user (id: 1) is a director; matches one of the seeded rows below
// so self-row action hiding can be exercised.
vi.mock("@/features/auth/AuthContext", () => ({
  useAuth: () => ({ user: { id: 1, name: "D", email: "d@e.com", roles: ["director"] } }),
}))

beforeEach(() => {
  vi.spyOn(usersApi, "fetchUsers").mockResolvedValue([
    { id: 1, name: "Yo Director", email: "yo@e.com", roles: ["director"], status: "active" },
    { id: 2, name: "Ana Docente", email: "ana@e.com", roles: ["teacher"], status: "pending" },
    { id: 3, name: "Beto Director", email: "beto@e.com", roles: ["director"], status: "active" },
  ])
})

afterEach(() => {
  vi.restoreAllMocks()
})

it("renders staff with their Spanish status label", async () => {
  render(
    <MemoryRouter>
      <UsersListPage />
    </MemoryRouter>,
  )
  expect(await screen.findByText("Ana Docente")).toBeInTheDocument()
  await waitFor(() => expect(screen.getByText("Pendiente")).toBeInTheDocument())
  expect(screen.getAllByText("Activo").length).toBeGreaterThan(0)
})

it("calls disableUser when clicking Desactivar on another user's row", async () => {
  const disableUser = vi.spyOn(usersApi, "disableUser").mockResolvedValue({
    id: 3,
    name: "Beto Director",
    email: "beto@e.com",
    roles: ["director"],
    status: "disabled",
  })

  render(
    <MemoryRouter>
      <UsersListPage />
    </MemoryRouter>,
  )

  const row = (await screen.findByText("Beto Director")).closest("tr") as HTMLElement
  await userEvent.click(within(row).getByRole("button", { name: "Desactivar" }))

  expect(disableUser).toHaveBeenCalledWith(3)
})

it("shows Reenviar invitación only for a pending row and calls resendInvitation", async () => {
  const resendInvitation = vi.spyOn(usersApi, "resendInvitation").mockResolvedValue(undefined)

  render(
    <MemoryRouter>
      <UsersListPage />
    </MemoryRouter>,
  )

  const pendingRow = (await screen.findByText("Ana Docente")).closest("tr") as HTMLElement
  const activeRow = (await screen.findByText("Beto Director")).closest("tr") as HTMLElement

  expect(within(pendingRow).getByRole("button", { name: "Reenviar invitación" })).toBeInTheDocument()
  expect(
    within(activeRow).queryByRole("button", { name: "Reenviar invitación" }),
  ).not.toBeInTheDocument()

  await userEvent.click(within(pendingRow).getByRole("button", { name: "Reenviar invitación" }))

  expect(resendInvitation).toHaveBeenCalledWith(2)
})

it("does not render the Desactivar action on the current user's own row", async () => {
  render(
    <MemoryRouter>
      <UsersListPage />
    </MemoryRouter>,
  )

  const ownRow = (await screen.findByText("Yo Director")).closest("tr") as HTMLElement
  expect(within(ownRow).queryByRole("button", { name: "Desactivar" })).not.toBeInTheDocument()
  expect(within(ownRow).queryByRole("button", { name: "Reactivar" })).not.toBeInTheDocument()
})
