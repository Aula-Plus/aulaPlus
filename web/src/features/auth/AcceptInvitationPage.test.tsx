import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, beforeEach, expect, it, vi } from "vitest"
import { AcceptInvitationPage } from "./AcceptInvitationPage"
import * as usersApi from "@/features/users/usersApi"

const navigateMock = vi.fn()

vi.mock("react-router-dom", async () => {
  const actual = await vi.importActual<typeof import("react-router-dom")>("react-router-dom")
  return { ...actual, useNavigate: () => navigateMock }
})

beforeEach(() => {
  vi.spyOn(usersApi, "fetchInvitation").mockResolvedValue({
    email: "ana@e.com",
    school_name: "Colegio Uno",
  })
})

afterEach(() => {
  navigateMock.mockClear()
  vi.restoreAllMocks()
})

it("shows the invitee email and school for a valid token", async () => {
  render(
    <MemoryRouter initialEntries={["/aceptar-invitacion?token=abc"]}>
      <AcceptInvitationPage />
    </MemoryRouter>,
  )
  expect(await screen.findByText("ana@e.com")).toBeInTheDocument()
  expect(screen.getByText(/Colegio Uno/)).toBeInTheDocument()
})

it("submits a valid password, activates the account, and navigates to login with a success message", async () => {
  const acceptInvitation = vi.spyOn(usersApi, "acceptInvitation").mockResolvedValue(undefined)

  render(
    <MemoryRouter initialEntries={["/aceptar-invitacion?token=abc"]}>
      <AcceptInvitationPage />
    </MemoryRouter>,
  )

  await screen.findByText("ana@e.com")
  await userEvent.type(screen.getByLabelText("Contraseña"), "letras123")
  await userEvent.type(screen.getByLabelText("Repetir contraseña"), "letras123")
  await userEvent.click(screen.getByRole("button", { name: /activar cuenta/i }))

  expect(acceptInvitation).toHaveBeenCalledWith("abc", "letras123")
  expect(navigateMock).toHaveBeenCalledWith("/login", {
    replace: true,
    state: { message: "Tu cuenta fue activada. Ya podés iniciar sesión." },
  })
})

it("shows the invalid/expired state and hides the password form when the invitation fails to load", async () => {
  vi.spyOn(usersApi, "fetchInvitation").mockRejectedValue({
    isAxiosError: true,
    response: { status: 410, data: { message: "Invitation expired" } },
  })

  render(
    <MemoryRouter initialEntries={["/aceptar-invitacion?token=expired"]}>
      <AcceptInvitationPage />
    </MemoryRouter>,
  )

  expect(await screen.findByText("Invitación no válida")).toBeInTheDocument()
  expect(screen.queryByLabelText("Contraseña")).not.toBeInTheDocument()
})

it("shows the invalid state when there is no token at all", async () => {
  render(
    <MemoryRouter initialEntries={["/aceptar-invitacion"]}>
      <AcceptInvitationPage />
    </MemoryRouter>,
  )

  expect(await screen.findByText("Invitación no válida")).toBeInTheDocument()
  expect(screen.queryByLabelText("Contraseña")).not.toBeInTheDocument()
})
