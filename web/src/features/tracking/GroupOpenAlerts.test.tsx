import { render, screen } from "@testing-library/react"
import { MemoryRouter } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import { GroupOpenAlerts } from "./GroupOpenAlerts"
import * as trackingApi from "./trackingApi"
import type { Alert } from "@/types"

function alert(id: number, overrides: Partial<Alert> = {}): Alert {
  return {
    id,
    student_id: 3,
    type: "performance",
    severity: "medium",
    description: "x",
    resolved: false,
    resolved_by_id: null,
    resolved_at: null,
    created_at: "2026-09-12T10:00:00Z",
    ...overrides,
  }
}

function renderCard() {
  return render(
    <MemoryRouter>
      <GroupOpenAlerts groupId={1} studentNames={{ 3: "Juan Pérez" }} />
    </MemoryRouter>,
  )
}

describe("GroupOpenAlerts", () => {
  afterEach(() => vi.restoreAllMocks())

  it("lists open alerts with a link to the student and ignores resolved ones", async () => {
    vi.spyOn(trackingApi, "fetchGroupAlerts").mockResolvedValue([
      alert(1),
      alert(2, { resolved: true }),
    ])
    renderCard()

    expect(await screen.findByText("Alertas del grupo")).toBeInTheDocument()
    expect(screen.getAllByText("Juan Pérez")).toHaveLength(1)
    expect(screen.getByRole("link", { name: /ver alumno/i })).toHaveAttribute(
      "href",
      "/alumnos/3/seguimiento",
    )
  })

  it("shows five alerts and links 'Ver todas' to «Alertas y seguimientos»", async () => {
    vi.spyOn(trackingApi, "fetchGroupAlerts").mockResolvedValue(
      [1, 2, 3, 4, 5, 6, 7].map((id) => alert(id)),
    )
    renderCard()

    expect(await screen.findAllByText("Juan Pérez")).toHaveLength(5)
    expect(screen.getByRole("link", { name: /ver todas \(7\)/i })).toHaveAttribute("href", "/alertas")
  })

  it("offers no 'Ver todas' when every alert fits", async () => {
    vi.spyOn(trackingApi, "fetchGroupAlerts").mockResolvedValue([1, 2, 3, 4, 5].map((id) => alert(id)))
    renderCard()

    expect(await screen.findAllByText("Juan Pérez")).toHaveLength(5)
    expect(screen.queryByText(/ver todas/i)).not.toBeInTheDocument()
  })

  it("shows who has an alert and since when, once someone chose a way out", async () => {
    vi.spyOn(trackingApi, "fetchGroupAlerts").mockResolvedValue([
      alert(1, {
        outcome: "handed_off",
        assignee: { id: 3, name: "Galia Cohen" },
        outcome_at: "2026-09-13T10:00:00Z",
      }),
      alert(2, { outcome: "owned", assignee: { id: 1, name: "Mariana López" }, outcome_at: "2026-09-14T10:00:00Z" }),
      alert(3),
    ])
    renderCard()

    expect(await screen.findByText(/^La tiene Galia Cohen desde el/)).toBeInTheDocument()
    expect(screen.getByText(/^La tiene Mariana López desde el/)).toBeInTheDocument()
    // A new alert nobody took yet shows no holder.
    expect(screen.getAllByText(/^La tiene /)).toHaveLength(2)
  })

  it("renders nothing when there are no open alerts or the request is forbidden", async () => {
    const spy = vi.spyOn(trackingApi, "fetchGroupAlerts").mockResolvedValue([])
    const { container } = renderCard()
    await vi.waitFor(() => expect(spy).toHaveBeenCalled())
    expect(container).toBeEmptyDOMElement()
  })
})
