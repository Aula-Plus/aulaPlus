import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { describe, expect, it, vi } from "vitest"
import { GroupsListPage } from "./GroupsListPage"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import * as groupsApi from "./groupsApi"
import type { Group, Role } from "@/types"

function renderList(role: Role) {
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
      {
        id: 1,
        name: "3° A",
        level: "Primaria",
        school_year: 2026,
        group_profile: null,
        related_documents: null,
        education_level: null,
        school_grade: null,
        teachers: [{ id: 5, name: "Ana Ruiz" }],
        active_tracking_count: 3,
      },
    ])

    renderList("director")

    expect(await screen.findByText("3° A")).toBeInTheDocument()
    expect(screen.getByText("Ana Ruiz")).toBeInTheDocument()
    expect(screen.getByRole("link", { name: /nuevo grupo/i })).toBeInTheDocument()
  })

  it("shows a dash when a group has no teachers assigned", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([
      {
        id: 1,
        name: "3° A",
        level: null,
        school_year: 2026,
        group_profile: null,
        related_documents: null,
        education_level: null,
        school_grade: null,
        teachers: [],
        active_tracking_count: 0,
      },
    ])

    renderList("director")

    const row = (await screen.findByText("3° A")).closest("tr")
    expect(row).not.toBeNull()
    expect(row!.textContent).toContain("—")
  })

  it("shows the active tracking count for a teacher (no role gate)", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([
      {
        id: 1,
        name: "3° A",
        level: "Primaria",
        school_year: 2026,
        group_profile: null,
        related_documents: null,
        education_level: null,
        school_grade: null,
        teachers: [{ id: 5, name: "Ana Ruiz" }],
        active_tracking_count: 4,
      },
    ])

    renderList("teacher")

    expect(await screen.findByText("Seguimiento activo")).toBeInTheDocument()
    const row = (await screen.findByText("3° A")).closest("tr")
    expect(row).not.toBeNull()
    // Column order: Nombre, Nivel, Año, Docentes, Seguimiento activo, acciones.
    const trackingCell = row!.querySelectorAll("td")[4]
    expect(trackingCell).toHaveTextContent("4")
  })

  it("shows 0 for a group with no active tracking, not an empty cell", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([
      {
        id: 1,
        name: "3° A",
        level: "Primaria",
        school_year: 2026,
        group_profile: null,
        related_documents: null,
        education_level: null,
        school_grade: null,
        teachers: [{ id: 5, name: "Ana Ruiz" }],
        active_tracking_count: 0,
      },
    ])

    renderList("teacher")

    const row = (await screen.findByText("3° A")).closest("tr")
    expect(row).not.toBeNull()
    const trackingCell = row!.querySelectorAll("td")[4]
    expect(trackingCell).not.toBeNull()
    expect(trackingCell).toHaveTextContent("0")
  })

  it("hides the create link for a teacher", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])

    renderList("teacher")

    expect(await screen.findByText(/todavía no hay grupos/i)).toBeInTheDocument()
    expect(screen.queryByRole("link", { name: /nuevo grupo/i })).not.toBeInTheDocument()
  })

  it("shows the screening link only to psychopedagogy (not a teacher)", async () => {
    const groups = [
      {
        id: 1,
        name: "3° A",
        level: "Primaria",
        school_year: 2026,
        group_profile: null,
        related_documents: null,
        education_level: null,
        school_grade: null,
        teachers: [{ id: 5, name: "Ana Ruiz" }],
        active_tracking_count: 0,
      },
    ]
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue(groups)

    const { unmount } = renderList("teacher")
    expect(await screen.findByText("3° A")).toBeInTheDocument()
    expect(screen.queryByRole("link", { name: "Screenings" })).not.toBeInTheDocument()
    unmount()

    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue(groups)
    renderList("psychopedagogue")
    expect(await screen.findByText("3° A")).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "Screenings" })).toHaveAttribute(
      "href",
      "/clases/1/pruebas-de-sondeo",
    )
  })

  describe("group levels", () => {
    const levels = {
      data: [
        { id: 2, key: "primary", label: "Primaria", grades: [{ id: 6, key: "primary_3", label: "3° de Primaria" }] },
        { id: 3, key: "lower_secondary", label: "Ciclo Básico", grades: [] },
      ],
      groups_without_level: 1,
    }
    const group = (overrides: Partial<Group>): Group => ({
      id: 1,
      name: "3° A",
      level: "Primaria",
      school_year: 2026,
      group_profile: null,
      related_documents: null,
      education_level: null,
      school_grade: null,
      teachers: [],
      active_tracking_count: 0,
      ...overrides,
    })

    it("shows the level, marks groups without one, and lets direction delegate to psychopedagogy", async () => {
      vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([
        group({ id: 1, name: "3° A" }),
        group({
          id: 2,
          name: "8° B",
          education_level: { id: 3, key: "lower_secondary", label: "Ciclo Básico" },
        }),
      ])
      vi.spyOn(groupsApi, "fetchEducationLevels").mockResolvedValue(levels)
      vi.spyOn(groupsApi, "fetchGroupLevelSetting").mockResolvedValue({
        psychopedagogue_can_set_group_level: false,
        can_set_level: true,
      })
      const update = vi.spyOn(groupsApi, "updateGroupLevelSetting").mockResolvedValue({
        psychopedagogue_can_set_group_level: true,
        can_set_level: true,
      })

      renderList("director")

      expect(await screen.findByText("Sin nivel")).toBeInTheDocument()
      expect(screen.getByText("Ciclo Básico")).toBeInTheDocument()
      expect(screen.getByRole("note")).toHaveTextContent("1 grupo sin nivel")
      // The old free text is never shown as the level.
      expect(screen.queryByText("Primaria")).not.toBeInTheDocument()

      await userEvent.click(
        await screen.findByRole("checkbox", { name: /psicopedagogía también puede elegir el nivel/i }),
      )
      expect(update).toHaveBeenCalledWith(true)
      // Direction uses the full form, not the level-only dialog.
      expect(screen.queryByRole("button", { name: "Elegir nivel" })).not.toBeInTheDocument()
    })

    it("lets psychopedagogy choose a level only when direction allowed it", async () => {
      vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([group({})])
      vi.spyOn(groupsApi, "fetchEducationLevels").mockResolvedValue(levels)
      vi.spyOn(groupsApi, "fetchGroupLevelSetting").mockResolvedValue({
        psychopedagogue_can_set_group_level: true,
        can_set_level: true,
      })
      const updateLevel = vi
        .spyOn(groupsApi, "updateGroupLevel")
        .mockResolvedValue(group({ education_level: { id: 2, key: "primary", label: "Primaria" } }))

      renderList("psychopedagogue")

      await userEvent.click(await screen.findByRole("button", { name: "Elegir nivel" }))
      await userEvent.selectOptions(screen.getByLabelText(/grado/i), "3° de Primaria")
      await userEvent.click(screen.getByRole("button", { name: "Guardar" }))

      expect(updateLevel).toHaveBeenCalledWith(1, { education_level_id: 2, school_grade_id: 6 })
      expect(screen.queryByRole("checkbox")).not.toBeInTheDocument()
    })

    it("gives psychopedagogy no level control without direction's permission", async () => {
      vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([group({})])
      vi.spyOn(groupsApi, "fetchEducationLevels").mockResolvedValue(levels)
      vi.spyOn(groupsApi, "fetchGroupLevelSetting").mockResolvedValue({
        psychopedagogue_can_set_group_level: false,
        can_set_level: false,
      })

      renderList("psychopedagogue")

      expect(await screen.findByText("Sin nivel")).toBeInTheDocument()
      expect(screen.queryByRole("button", { name: "Elegir nivel" })).not.toBeInTheDocument()
    })
  })
})
