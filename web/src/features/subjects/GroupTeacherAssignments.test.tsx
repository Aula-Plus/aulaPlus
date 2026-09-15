import { render, screen } from "@testing-library/react"
import { describe, expect, it, vi, beforeEach } from "vitest"
import { GroupTeacherAssignments } from "./GroupTeacherAssignments"
import * as subjectsApi from "./subjectsApi"

vi.mock("./subjectsApi")

describe("GroupTeacherAssignments", () => {
  beforeEach(() => {
    vi.mocked(subjectsApi.fetchGroupAssignments).mockResolvedValue([
      { teacher_id: 5, teacher_name: "Ana", subject_id: 1, subject_name: "Matemática" },
    ])
    vi.mocked(subjectsApi.fetchSubjects).mockResolvedValue([
      { id: 1, name: "Matemática", short_code: null, color: null },
    ])
    vi.mocked(subjectsApi.fetchTeachers).mockResolvedValue([{ id: 5, name: "Ana" }])
    vi.mocked(subjectsApi.createGroupAssignment).mockResolvedValue()
  })

  it("lists current assignments", async () => {
    render(<GroupTeacherAssignments groupId={10} canManage />)
    expect(await screen.findByText(/Ana/)).toBeInTheDocument()
    expect(screen.getByText(/Matemática/)).toBeInTheDocument()
  })
})
