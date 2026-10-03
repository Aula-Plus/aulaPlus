import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import { MyFollowUpsPage } from "./MyFollowUpsPage"
import * as api from "./scheduledFollowUpsApi"

describe("MyFollowUpsPage", () => {
  afterEach(() => vi.restoreAllMocks())

  it("lists pending follow-ups and lets the user dismiss an assignment notice", async () => {
    vi.spyOn(api, "fetchMyFollowUps").mockResolvedValue([
      {
        id: 1,
        student_id: 3,
        description: "Revisar tiempo extendido",
        due_date: "2026-10-30",
        created_by_id: 5,
        assigned_to_id: 7,
        assigned_to: { id: 7, name: "Paula Psico", role: "psychopedagogue" },
        shared_with: [],
        resolved: false,
        resolved_by_id: null,
        resolved_at: null,
        resolution_note: null,
        is_overdue: false,
        created_at: null,
      },
    ])
    vi.spyOn(api, "fetchNotifications").mockResolvedValue([
      {
        id: "n1",
        data: { type: "follow_up_assigned", follow_up_id: 1, student_id: 3, due_date: "2026-10-30" },
        created_at: null,
      },
    ])
    const markRead = vi.spyOn(api, "markNotificationRead").mockResolvedValue()

    render(
      <MemoryRouter>
        <MyFollowUpsPage />
      </MemoryRouter>,
    )

    expect(await screen.findByText("Revisar tiempo extendido")).toBeInTheDocument()
    expect(screen.getByText(/te asignaron un seguimiento/i)).toBeInTheDocument()

    await userEvent.click(screen.getByRole("button", { name: /entendido/i }))

    expect(markRead).toHaveBeenCalledWith("n1")
    expect(screen.queryByText(/te asignaron un seguimiento/i)).not.toBeInTheDocument()
  })
})
