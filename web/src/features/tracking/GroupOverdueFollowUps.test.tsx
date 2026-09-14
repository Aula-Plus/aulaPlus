import { render, screen } from "@testing-library/react"
import { MemoryRouter } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import type { ScheduledFollowUp } from "@/types"
import { GroupOverdueFollowUps } from "./GroupOverdueFollowUps"
import * as scheduledFollowUpsApi from "./scheduledFollowUpsApi"

function followUp(overrides: Partial<ScheduledFollowUp> = {}): ScheduledFollowUp {
  return {
    id: 1,
    student_id: 7,
    description: "Revisar barrera de lectura",
    due_date: "2026-03-01",
    created_by_id: 2,
    resolved: false,
    resolved_by_id: null,
    resolved_at: null,
    resolution_note: null,
    is_overdue: true,
    created_at: "2026-02-01T10:00:00+00:00",
    ...overrides,
  }
}

function renderPanel() {
  return render(
    <MemoryRouter>
      <GroupOverdueFollowUps groupId={1} />
    </MemoryRouter>,
  )
}

describe("GroupOverdueFollowUps", () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("requests only overdue follow-ups", async () => {
    const spy = vi
      .spyOn(scheduledFollowUpsApi, "fetchGroupScheduledFollowUps")
      .mockResolvedValue([])

    renderPanel()

    await screen.findByText("No hay seguimientos vencidos.")
    expect(spy).toHaveBeenCalledWith(1, true)
  })

  // Spec §7: each row links to the corresponding StudentTrackingPage.
  it("links each row to its student's tracking page", async () => {
    vi.spyOn(scheduledFollowUpsApi, "fetchGroupScheduledFollowUps").mockResolvedValue([
      followUp({ id: 1, student_id: 7, description: "Revisar barrera de lectura" }),
      followUp({ id: 2, student_id: 12, description: "Coordinar con familia" }),
    ])

    renderPanel()

    const firstRow = (await screen.findByText("Revisar barrera de lectura")).closest("li")!
    expect(firstRow.querySelector("a")).toHaveAttribute("href", "/alumnos/7/seguimiento")

    const secondRow = screen.getByText("Coordinar con familia").closest("li")!
    expect(secondRow.querySelector("a")).toHaveAttribute("href", "/alumnos/12/seguimiento")
  })

  it("shows an empty-state message when nothing is overdue", async () => {
    vi.spyOn(scheduledFollowUpsApi, "fetchGroupScheduledFollowUps").mockResolvedValue([])

    renderPanel()

    expect(await screen.findByText("No hay seguimientos vencidos.")).toBeInTheDocument()
  })
})
