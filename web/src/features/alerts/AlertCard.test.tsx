import { render, screen } from "@testing-library/react"
import { MemoryRouter } from "react-router-dom"
import { describe, expect, it, vi } from "vitest"
import type { Alert } from "@/types"
import { AlertCard } from "./AlertCard"

const markAlert: Alert = {
  id: 30,
  student_id: null,
  subject_id: null,
  ai_input_mark: { id: 4, group_id: 12, group_name: "2°EMS-A", action: "Dar 15 minutos más en escritos" },
  type: "ai_input_mark",
  severity: "medium",
  description: "Marcaste una línea de «Lo que recibe la IA» como mal interpretada el 09/11 y sigue pendiente.",
  condition_met_on: "2026-11-16",
  recipients: [],
  outcome: null,
  assignee: null,
  outcome_at: null,
  due_on: null,
  is_overdue: false,
  actions: [],
  can: { act: true, resolve: false, link_follow_up: false },
  resolved: false,
  resolved_by_id: null,
  resolved_at: null,
  created_at: "2026-11-16T10:00:00Z",
}

describe("AlertCard · «Lo que recibe la IA»", () => {
  it("leads to the group instead of a student and keeps the usual ways out", () => {
    render(
      <MemoryRouter>
        <AlertCard alert={markAlert} onChange={vi.fn()} />
      </MemoryRouter>,
    )

    expect(screen.getByRole("link", { name: "2°EMS-A" })).toHaveAttribute("href", "/clases/12/seguimiento")
    expect(screen.getByText(/Lo que recibe la IA: marca pendiente — «Dar 15 minutos más en escritos»/)).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Me ocupo yo" })).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Crear seguimiento vinculado" })).not.toBeInTheDocument()
  })
})
