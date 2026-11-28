import { render, screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { describe, expect, it, vi } from "vitest"
import type { Alert } from "@/types"
import { AlertCard } from "./AlertCard"
import * as alertsApi from "./alertsApi"

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

const handedOffAlert: Alert = {
  ...markAlert,
  id: 31,
  student_id: 7,
  student_name: "Alumno de prueba",
  ai_input_mark: null,
  type: "performance",
  outcome: "handed_off",
  assignee: { id: 2, name: "Psicopedagoga" },
  due_on: "2026-12-03",
  is_in_charge: false,
  can: { act: false, resolve: false, note: false, link_follow_up: false },
  deadline_notice: { enabled: false },
}

describe("AlertCard · «Avisarme a mí también cuando llegue el plazo»", () => {
  it("shows the box off to whoever handed it off and saves the change", async () => {
    const updated = { ...handedOffAlert, deadline_notice: { enabled: true } }
    const spy = vi.spyOn(alertsApi, "setAlertDeadlineNotice").mockResolvedValue(updated)
    const onChange = vi.fn()
    render(
      <MemoryRouter>
        <AlertCard alert={handedOffAlert} onChange={onChange} />
      </MemoryRouter>,
    )

    const box = screen.getByRole("checkbox", { name: "Avisarme a mí también cuando llegue el plazo" })
    expect(box).not.toBeChecked()

    await userEvent.click(box)

    expect(spy).toHaveBeenCalledWith(31, true)
    await waitFor(() => expect(onChange).toHaveBeenCalledWith(updated))
  })

  it("does not show the box when the server does not offer it", () => {
    render(
      <MemoryRouter>
        <AlertCard alert={{ ...handedOffAlert, deadline_notice: null }} onChange={vi.fn()} />
      </MemoryRouter>,
    )

    expect(screen.queryByRole("checkbox")).not.toBeInTheDocument()
  })
})
