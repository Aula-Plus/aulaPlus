import { render, screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import type { FollowUpStage, ScheduledFollowUpDetail } from "@/types"
import { FollowUpDetailDialog } from "./FollowUpDetailDialog"
import { FollowUpsSection } from "./FollowUpsSection"
import * as detailApi from "./followUpDetailApi"
import * as scheduledFollowUpsApi from "./scheduledFollowUpsApi"

const psico = { id: 7, name: "Paula Psico", role: "psychopedagogue" as const }
const director = { id: 5, name: "Diana Directora", role: "director" as const }

function stage(overrides: Partial<FollowUpStage> = {}): FollowUpStage {
  return {
    id: 11,
    parent_stage_id: null,
    description: "Observar en clase",
    responsible: psico,
    due_date: "2026-10-30",
    status: "pending",
    conclusion: null,
    closed_by: null,
    closed_at: null,
    created_by_id: 5,
    created_at: "2026-10-18T10:00:00+00:00",
    notes: [],
    can: { update: true, split: true, close: true, add_note: true },
    ...overrides,
  }
}

function detail(overrides: Partial<ScheduledFollowUpDetail> = {}): ScheduledFollowUpDetail {
  return {
    id: 1,
    student_id: 3,
    student: { id: 3, full_name: "Juan Pérez" },
    title: "Ajuste de lectura",
    description: "Revisar si el ajuste sigue haciendo falta",
    due_date: "2026-10-30",
    created_by_id: 5,
    assigned_to_id: 7,
    assigned_to: psico,
    shared_with: [director],
    general_responsibles: [psico],
    resolved: false,
    resolved_by_id: null,
    resolved_at: null,
    resolution_note: null,
    is_overdue: false,
    created_at: "2026-10-18T10:00:00+00:00",
    participants: [director],
    members: [
      { user: psico, role: "general_responsible", active: true, has_pending_stage: true, withdrawn_at: null, withdrawal_reason: null, removed_at: null, removed_by_id: null, removal_reason: null },
      { user: director, role: "participant", active: true, has_pending_stage: false, withdrawn_at: null, withdrawal_reason: null, removed_at: null, removed_by_id: null, removal_reason: null },
    ],
    stages: [stage()],
    events: [
      { id: 1, type: "created", actor: director, subject_user: null, stage_id: null, data: null, note: null, created_at: "2026-10-18T10:00:00+00:00" },
    ],
    viewer: { user_id: 7, is_member: true, active: true, role: "general_responsible", left_at: null, hidden: false, is_general_responsible: true },
    can: {
      add_stage: true,
      add_participant: true,
      manage_general_responsibles: true,
      withdraw: false,
      withdraw_requires_successor: true,
      resolve: false,
      hide: false,
    },
    ...overrides,
  }
}

function renderDialog() {
  return render(
    <MemoryRouter>
      <FollowUpDetailDialog followUpId={1} onClose={() => {}} />
    </MemoryRouter>,
  )
}

describe("FollowUpDetailDialog", () => {
  beforeEach(() => {
    vi.spyOn(scheduledFollowUpsApi, "fetchFollowUpCandidates").mockResolvedValue([psico, director])
  })
  afterEach(() => vi.restoreAllMocks())

  it("opens from the Mis seguimientos section with the title first and the stages", async () => {
    vi.spyOn(scheduledFollowUpsApi, "fetchMyFollowUps").mockResolvedValue([detail()])
    vi.spyOn(scheduledFollowUpsApi, "fetchNotifications").mockResolvedValue([])
    const fetchDetail = vi.spyOn(detailApi, "fetchFollowUpDetail").mockResolvedValue(detail())

    render(
      <MemoryRouter>
        <FollowUpsSection />
      </MemoryRouter>,
    )

    expect(await screen.findByText("Ajuste de lectura")).toBeInTheDocument()
    await userEvent.click(screen.getByRole("button", { name: /ver detalle/i }))

    const dialog = await screen.findByRole("dialog")
    expect(fetchDetail).toHaveBeenCalledWith(1)
    expect(within(dialog).getByRole("heading", { name: "Ajuste de lectura" })).toBeInTheDocument()
    expect(within(dialog).getByText("Observar en clase")).toBeInTheDocument()
    expect(within(dialog).getByRole("link", { name: /ver ficha del alumno/i })).toHaveAttribute(
      "href",
      "/alumnos/3/seguimiento",
    )
  })

  it("adds a stage and shows the record the server returns", async () => {
    vi.spyOn(detailApi, "fetchFollowUpDetail").mockResolvedValue(detail())
    const addStage = vi.spyOn(detailApi, "addStage").mockResolvedValue(
      detail({
        due_date: "2026-11-15",
        stages: [stage(), stage({ id: 12, description: "Reunión con la familia", responsible: director, due_date: "2026-11-15" })],
      }),
    )

    renderDialog()
    await userEvent.click(await screen.findByRole("button", { name: /agregar etapa/i }))
    await userEvent.type(screen.getByLabelText(/qué hay que hacer/i), "Reunión con la familia")
    await userEvent.click(screen.getByLabelText(/responsable de la etapa/i))
    await userEvent.click(await screen.findByRole("button", { name: "Diana Directora (Director)" }))
    await userEvent.type(screen.getByLabelText(/fecha de la etapa/i), "2026-11-15")
    await userEvent.click(screen.getByRole("button", { name: /guardar etapa/i }))

    expect(addStage).toHaveBeenCalledWith(1, {
      description: "Reunión con la familia",
      responsible_id: 5,
      due_date: "2026-11-15",
    })
    expect(await screen.findByText("Reunión con la familia")).toBeInTheDocument()
    expect(screen.getAllByRole("listitem", { name: /etapa/i })).toHaveLength(2)
  })

  it("keeps 'Resolver seguimiento' disabled while a stage is pending", async () => {
    vi.spyOn(detailApi, "fetchFollowUpDetail").mockResolvedValue(detail())

    renderDialog()
    await userEvent.type(await screen.findByLabelText(/conclusión general/i), "Se mantiene el ajuste")

    expect(screen.getByRole("button", { name: /resolver seguimiento/i })).toBeDisabled()
    expect(screen.getByText(/cerrá todas las etapas/i)).toBeInTheDocument()
  })

  it("resolves with the general conclusion once every stage is closed", async () => {
    vi.spyOn(detailApi, "fetchFollowUpDetail").mockResolvedValue(
      detail({
        stages: [stage({ status: "done", conclusion: "Observado", can: { update: false, split: false, close: false, add_note: true } })],
        can: { ...detail().can, resolve: true },
      }),
    )
    const resolve = vi.spyOn(detailApi, "resolve").mockResolvedValue(
      detail({ resolved: true, resolution_note: "Se mantiene el ajuste", resolved_at: "2026-10-20T10:00:00+00:00" }),
    )

    renderDialog()
    await userEvent.type(await screen.findByLabelText(/conclusión general/i), "Se mantiene el ajuste")
    await userEvent.click(screen.getByRole("button", { name: /resolver seguimiento/i }))

    expect(resolve).toHaveBeenCalledWith(1, "Se mantiene el ajuste")
    expect(await screen.findByText("Resuelto")).toBeInTheDocument()
  })

  it("hides stage actions the viewer may not take", async () => {
    vi.spyOn(detailApi, "fetchFollowUpDetail").mockResolvedValue(
      detail({
        stages: [stage({ can: { update: false, split: false, close: false, add_note: false } })],
        viewer: { user_id: 5, is_member: true, active: true, role: "participant", left_at: null, hidden: false, is_general_responsible: false },
        can: { ...detail().can, add_stage: false, add_participant: false, manage_general_responsibles: false, withdraw: true },
      }),
    )

    renderDialog()
    const item = await screen.findByRole("listitem", { name: /etapa 1/i })

    expect(within(item).queryByRole("button", { name: /editar|subdividir|cerrar etapa/i })).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /agregar etapa/i })).not.toBeInTheDocument()
    expect(screen.queryByLabelText(/conclusión general/i)).not.toBeInTheDocument()
    expect(screen.getByRole("button", { name: /retirarme/i })).toBeInTheDocument()
  })
})
