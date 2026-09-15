import { render, screen } from "@testing-library/react"
import { MemoryRouter } from "react-router-dom"
import { beforeEach, expect, it, vi } from "vitest"
import { AcceptInvitationPage } from "./AcceptInvitationPage"
import * as usersApi from "@/features/users/usersApi"

beforeEach(() => {
  vi.spyOn(usersApi, "fetchInvitation").mockResolvedValue({
    email: "ana@e.com",
    school_name: "Colegio Uno",
  })
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
