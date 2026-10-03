import { render, screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { ScheduledFollowUpsPanel } from "./ScheduledFollowUpsPanel"
import * as scheduledFollowUpsApi from "./scheduledFollowUpsApi"
import type { ScheduledFollowUp } from "@/types"

function followUp(overrides: Partial<ScheduledFollowUp> = {}): ScheduledFollowUp {
  return {
    id: 1,
    student_id: 3,
    description: "Llamar a la familia",
    due_date: "2026-09-20",
    created_by_id: 5,
    resolved: false,
    resolved_by_id: null,
    resolved_at: null,
    resolution_note: null,
    is_overdue: false,
    created_at: "2026-09-01T10:00:00+00:00",
    ...overrides,
  }
}

describe("ScheduledFollowUpsPanel", () => {
  beforeEach(() => {
    vi.spyOn(scheduledFollowUpsApi, "fetchScheduledFollowUps").mockResolvedValue([])
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("creates a follow-up sending { description, due_date } and adds the returned row without a refetch", async () => {
    const fetchSpy = scheduledFollowUpsApi.fetchScheduledFollowUps as ReturnType<typeof vi.fn>
    const create = vi
      .spyOn(scheduledFollowUpsApi, "createScheduledFollowUp")
      .mockResolvedValue(followUp({ id: 42, description: "Revisar tarea", due_date: "2026-10-01" }))

    render(<ScheduledFollowUpsPanel studentId={3} />)

    await screen.findByText(/no hay seguimientos pendientes/i)

    await userEvent.type(screen.getByLabelText(/nuevo seguimiento/i), "Revisar tarea")
    await userEvent.type(screen.getByLabelText(/fecha límite/i), "2026-10-01")
    await userEvent.click(screen.getByRole("button", { name: /programar seguimiento/i }))

    expect(create).toHaveBeenCalledWith(3, {
      description: "Revisar tarea",
      due_date: "2026-10-01",
    })
    expect(await screen.findByText("Revisar tarea")).toBeInTheDocument()
    // Only the mount fetch — the returned row is spliced in, never refetched.
    expect(fetchSpy).toHaveBeenCalledTimes(1)
  })

  it("does not submit and shows an error when the description is empty", async () => {
    const create = vi.spyOn(scheduledFollowUpsApi, "createScheduledFollowUp")

    render(<ScheduledFollowUpsPanel studentId={3} />)
    await screen.findByText(/no hay seguimientos pendientes/i)

    await userEvent.click(screen.getByRole("button", { name: /programar seguimiento/i }))

    expect(await screen.findByText(/escribí una descripción/i)).toBeInTheDocument()
    expect(create).not.toHaveBeenCalled()
  })

  it("toggling 'Mostrar resueltos' swaps resolved=false for no query param", async () => {
    const fetchSpy = scheduledFollowUpsApi.fetchScheduledFollowUps as ReturnType<typeof vi.fn>

    render(<ScheduledFollowUpsPanel studentId={3} />)
    await screen.findByText(/no hay seguimientos pendientes/i)

    // Mount: only pending → resolved=false.
    expect(fetchSpy).toHaveBeenNthCalledWith(1, 3, false)

    await userEvent.click(screen.getByLabelText(/mostrar resueltos/i))

    // Toggle on: every follow-up → no query param (undefined).
    expect(fetchSpy).toHaveBeenNthCalledWith(2, 3, undefined)
  })

  it("paints the 'Vencido' badge from is_overdue and never computes dates itself", async () => {
    vi.spyOn(scheduledFollowUpsApi, "fetchScheduledFollowUps").mockResolvedValue([
      followUp({ id: 1, description: "Atrasado", is_overdue: true }),
      followUp({ id: 2, description: "A tiempo", is_overdue: false }),
    ])

    render(<ScheduledFollowUpsPanel studentId={3} />)

    const overdue = (await screen.findByText("Atrasado")).closest("li") as HTMLElement
    const onTime = screen.getByText("A tiempo").closest("li") as HTMLElement

    expect(within(overdue).getByText("Vencido")).toBeInTheDocument()
    expect(within(onTime).queryByText("Vencido")).not.toBeInTheDocument()
  })

  it("resolves with resolution_note when the field is filled", async () => {
    vi.spyOn(scheduledFollowUpsApi, "fetchScheduledFollowUps").mockResolvedValue([
      followUp({ id: 7 }),
    ])
    const resolve = vi
      .spyOn(scheduledFollowUpsApi, "resolveScheduledFollowUp")
      .mockResolvedValue(followUp({ id: 7, resolved: true }))

    render(<ScheduledFollowUpsPanel studentId={3} />)

    await userEvent.click(await screen.findByRole("button", { name: /resolver/i }))
    await userEvent.type(screen.getByLabelText(/nota de resolución/i), "Hablé con la madre")
    await userEvent.click(screen.getByRole("button", { name: /confirmar/i }))

    expect(resolve).toHaveBeenCalledWith(7, "Hablé con la madre")
  })

  it("resolves WITHOUT resolution_note when the field is left empty (never sends an empty string)", async () => {
    vi.spyOn(scheduledFollowUpsApi, "fetchScheduledFollowUps").mockResolvedValue([
      followUp({ id: 7 }),
    ])
    const resolve = vi
      .spyOn(scheduledFollowUpsApi, "resolveScheduledFollowUp")
      .mockResolvedValue(followUp({ id: 7, resolved: true }))

    render(<ScheduledFollowUpsPanel studentId={3} />)

    await userEvent.click(await screen.findByRole("button", { name: /resolver/i }))
    await userEvent.click(screen.getByRole("button", { name: /confirmar/i }))

    expect(resolve).toHaveBeenCalledWith(7, undefined)
  })

  it("does not show a 'Resolver' button on an already-resolved follow-up", async () => {
    vi.spyOn(scheduledFollowUpsApi, "fetchScheduledFollowUps").mockResolvedValue([
      followUp({
        id: 9,
        description: "Ya resuelto",
        resolved: true,
        resolved_by_id: 12,
        resolved_at: "2026-09-05T10:00:00+00:00",
        resolution_note: "Cerrado",
      }),
    ])

    render(<ScheduledFollowUpsPanel studentId={3} />)

    const row = (await screen.findByText("Ya resuelto")).closest("li") as HTMLElement
    expect(within(row).queryByRole("button", { name: /resolver/i })).not.toBeInTheDocument()
    expect(within(row).getByText(/usuario #12/i)).toBeInTheDocument()
    expect(within(row).getByText(/nota: cerrado/i)).toBeInTheDocument()
  })
})
