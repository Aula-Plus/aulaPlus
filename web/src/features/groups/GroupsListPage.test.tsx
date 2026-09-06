import { render, screen } from "@testing-library/react"
import { MemoryRouter } from "react-router-dom"
import { describe, expect, it, vi } from "vitest"
import { GroupsListPage } from "./GroupsListPage"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import * as groupsApi from "./groupsApi"

function renderList(role: "director" | "teacher") {
  const value: AuthContextValue = {
    user: { id: 1, name: "Ana", email: "ana@escuela.test", roles: [role] },
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
  }

  return render(
    <AuthContext value={value}>
      <MemoryRouter>
        <GroupsListPage />
      </MemoryRouter>
    </AuthContext>,
  )
}

describe("GroupsListPage", () => {
  it("renders groups returned by the API and shows the create link for a director", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([
      { id: 1, name: "3° A", level: "Primaria", year: "2026", teacher_id: null, teacher: null },
    ])

    renderList("director")

    expect(await screen.findByText("3° A")).toBeInTheDocument()
    expect(screen.getByRole("link", { name: /nueva clase/i })).toBeInTheDocument()
  })

  it("hides the create link for a teacher", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])

    renderList("teacher")

    expect(await screen.findByText(/todavía no hay clases/i)).toBeInTheDocument()
    expect(screen.queryByRole("link", { name: /nueva clase/i })).not.toBeInTheDocument()
  })
})
