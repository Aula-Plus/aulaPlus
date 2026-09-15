import { render, screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { describe, expect, it, vi, beforeEach } from "vitest"
import { SubjectsPage } from "./SubjectsPage"
import * as subjectsApi from "./subjectsApi"

vi.mock("./subjectsApi")
// `User.roles` is an array (see src/types.ts), so the mocked director carries
// `roles: ["director"]` — a bare `role` string would not satisfy isDirector.
vi.mock("@/features/auth/AuthContext", () => ({
  useAuth: () => ({ user: { id: 1, name: "Dir", email: "d@x.com", roles: ["director"] } }),
}))

describe("SubjectsPage", () => {
  beforeEach(() => {
    vi.mocked(subjectsApi.fetchSubjects).mockResolvedValue([
      { id: 1, name: "Matemática", short_code: "MAT", color: null },
    ])
    vi.mocked(subjectsApi.createSubject).mockResolvedValue({
      id: 2,
      name: "Inglés",
      short_code: null,
      color: null,
    })
  })

  it("lists existing subjects", async () => {
    render(<SubjectsPage />)
    expect(await screen.findByText("Matemática")).toBeInTheDocument()
  })

  it("creates a subject", async () => {
    render(<SubjectsPage />)
    await screen.findByText("Matemática")

    await userEvent.type(screen.getByLabelText("Nombre"), "Inglés")
    await userEvent.click(screen.getByRole("button", { name: /crear materia/i }))

    await waitFor(() =>
      expect(subjectsApi.createSubject).toHaveBeenCalledWith(
        expect.objectContaining({ name: "Inglés" }),
      ),
    )
  })
})
