import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, describe, expect, it, vi } from "vitest"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import type { Accommodation, Role } from "@/types"
import { AccommodationProposalsSection } from "./AccommodationProposalsSection"
import * as trackingApi from "./trackingApi"

function proposal(overrides: Partial<Accommodation> = {}): Accommodation {
  return {
    id: 40,
    student_id: 3,
    type: "Más tiempo en escritos",
    category: "access",
    starts_on: "2026-10-21",
    subjects: [],
    assessment_types: null,
    active: true,
    deactivated_on: null,
    description: "Media hora extra.",
    focus_area: "Escritura",
    origin: null,
    is_effective: false,
    review_status: "pending",
    proposed_by_id: 9,
    proposed_by_name: "Dora Directora",
    reviewed_by_id: null,
    reviewed_by_name: null,
    reviewed_at: null,
    review_note: null,
    created_by_id: 9,
    created_at: "2026-10-21T10:00:00+00:00",
    updated_at: "2026-10-21T10:00:00+00:00",
    ...overrides,
  }
}

function renderSection(role: Role, onConfirmed = vi.fn()) {
  const value: AuthContextValue = {
    user: { id: 1, name: "Ana", email: "ana@escuela.test", roles: [role] },
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
  }
  render(
    <AuthContext value={value}>
      <AccommodationProposalsSection
        studentId={3}
        refreshKey={0}
        onEdit={vi.fn()}
        onConfirmed={onConfirmed}
      />
    </AuthContext>,
  )
  return { onConfirmed }
}

describe("AccommodationProposalsSection", () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("says it is loading until the list arrives, then shows the proposal", async () => {
    let resolve: (data: Accommodation[]) => void = () => {}
    vi.spyOn(trackingApi, "fetchAccommodationProposals").mockReturnValue(
      new Promise((done) => {
        resolve = done
      }),
    )
    renderSection("psychopedagogue")

    expect(screen.getByText("Propuestas de ajuste")).toBeInTheDocument()
    expect(screen.getByRole("status")).toHaveTextContent("Cargando…")

    resolve([proposal()])
    expect(await screen.findByText("Pendiente de confirmación de Psicopedagogía")).toBeInTheDocument()
    expect(screen.queryByText("Cargando…")).not.toBeInTheDocument()
  })

  it("says there are no proposals once the empty list arrives", async () => {
    vi.spyOn(trackingApi, "fetchAccommodationProposals").mockResolvedValue([])
    renderSection("psychopedagogue")
    expect(await screen.findByText("No hay propuestas de ajuste.")).toBeInTheDocument()
    expect(screen.queryByText("Cargando…")).not.toBeInTheDocument()
  })

  it("shows an error, not the empty text, when the list fails to load", async () => {
    vi.spyOn(trackingApi, "fetchAccommodationProposals").mockRejectedValue(new Error("boom"))
    renderSection("director")
    expect(
      await screen.findByText("No pudimos cargar las propuestas de ajuste."),
    ).toBeInTheDocument()
    expect(screen.queryByText("No hay propuestas de ajuste.")).not.toBeInTheDocument()
    expect(screen.queryByText("Cargando…")).not.toBeInTheDocument()
  })

  it("keeps the previous list visible while it refetches", async () => {
    vi.spyOn(trackingApi, "fetchAccommodationProposals")
      .mockResolvedValueOnce([proposal()])
      .mockReturnValueOnce(new Promise(() => {}))
    const value: AuthContextValue = {
      user: { id: 1, name: "Ana", email: "ana@escuela.test", roles: ["psychopedagogue"] },
      loading: false,
      login: vi.fn(),
      logout: vi.fn(),
    }
    const section = (refreshKey: number) => (
      <AuthContext value={value}>
        <AccommodationProposalsSection
          studentId={3}
          refreshKey={refreshKey}
          onEdit={vi.fn()}
          onConfirmed={vi.fn()}
        />
      </AuthContext>
    )
    const { rerender } = render(section(0))
    expect(await screen.findByText("Pendiente de confirmación de Psicopedagogía")).toBeInTheDocument()

    rerender(section(1))

    await vi.waitFor(() =>
      expect(trackingApi.fetchAccommodationProposals).toHaveBeenCalledTimes(2),
    )
    expect(screen.getByText("Pendiente de confirmación de Psicopedagogía")).toBeInTheDocument()
    expect(screen.queryByText("Cargando…")).not.toBeInTheDocument()
  })

  it("lets psychopedagogy confirm a pending proposal with «Vigente desde» and «Origen»", async () => {
    vi.spyOn(trackingApi, "fetchAccommodationProposals")
      .mockResolvedValueOnce([proposal()])
      .mockResolvedValueOnce([])
    const review = vi
      .spyOn(trackingApi, "reviewAccommodationProposal")
      .mockResolvedValue(proposal({ review_status: "confirmed", is_effective: true }))
    const { onConfirmed } = renderSection("psychopedagogue")

    expect(await screen.findByText("Pendiente de confirmación de Psicopedagogía")).toBeInTheDocument()
    // Direction's own steps are not offered to psychopedagogy.
    expect(screen.queryByRole("button", { name: /retirar propuesta/i })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole("button", { name: /^confirmar$/i }))
    const startsOn = screen.getByLabelText(/vigente desde/i)
    await userEvent.clear(startsOn)
    await userEvent.type(startsOn, "2026-11-01")
    // «Origen» comes prefilled with who proposed it.
    expect(screen.getByLabelText(/^origen$/i)).toHaveValue("Propuesta de Dora Directora")
    await userEvent.click(screen.getByRole("button", { name: /confirmar ajuste/i }))

    expect(review).toHaveBeenCalledWith(40, "confirm", {
      starts_on: "2026-11-01",
      origin: "Propuesta de Dora Directora",
    })
    await vi.waitFor(() => expect(onConfirmed).toHaveBeenCalled())
  })

  it("requires a comment to return a proposal to direction", async () => {
    vi.spyOn(trackingApi, "fetchAccommodationProposals").mockResolvedValue([proposal()])
    const review = vi
      .spyOn(trackingApi, "reviewAccommodationProposal")
      .mockResolvedValue(proposal({ review_status: "returned", review_note: "¿En qué materias?" }))
    renderSection("psychopedagogue")

    await userEvent.click(await screen.findByRole("button", { name: /devolver para corregir/i }))
    await userEvent.click(screen.getByRole("button", { name: /devolver para corregir/i }))
    expect(screen.getByRole("alert")).toHaveTextContent("Completá este campo.")
    expect(review).not.toHaveBeenCalled()

    await userEvent.type(screen.getByLabelText(/comentario o contrapropuesta/i), "¿En qué materias?")
    await userEvent.click(screen.getByRole("button", { name: /devolver para corregir/i }))
    expect(review).toHaveBeenCalledWith(40, "return", { note: "¿En qué materias?" })
  })

  it("shows direction the comment of a returned proposal and lets it resubmit or withdraw", async () => {
    vi.spyOn(trackingApi, "fetchAccommodationProposals").mockResolvedValue([
      proposal({ review_status: "returned", review_note: "¿En qué materias?" }),
    ])
    const review = vi
      .spyOn(trackingApi, "reviewAccommodationProposal")
      .mockResolvedValue(proposal({ review_status: "withdrawn", review_note: "Ya no hace falta" }))
    renderSection("director")

    expect(await screen.findByText("Devuelta para corregir")).toBeInTheDocument()
    expect(screen.getByText(/¿en qué materias\?/i)).toBeInTheDocument()
    expect(screen.getByText(/le toca responder a dirección/i)).toBeInTheDocument()
    expect(screen.getByRole("button", { name: /reenviar a revisión/i })).toBeInTheDocument()
    // Psychopedagogy's steps are not offered to direction.
    expect(screen.queryByRole("button", { name: /^confirmar$/i })).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /^rechazar$/i })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole("button", { name: /retirar propuesta/i }))
    await userEvent.type(screen.getByLabelText(/motivo del retiro/i), "Ya no hace falta")
    await userEvent.click(screen.getByRole("button", { name: /retirar propuesta/i }))
    expect(review).toHaveBeenCalledWith(40, "withdraw", { reason: "Ya no hace falta" })
  })

  it("shows the history of a closed proposal, with no further steps", async () => {
    vi.spyOn(trackingApi, "fetchAccommodationProposals").mockResolvedValue([
      proposal({ review_status: "rejected", review_note: "No corresponde" }),
    ])
    vi.spyOn(trackingApi, "fetchAccommodationProposalHistory").mockResolvedValue([
      {
        id: 1,
        action: "submitted",
        note: null,
        changed_fields: null,
        user: { id: 9, name: "Directora", role: "director" },
        created_at: "2026-10-20T10:00:00+00:00",
      },
      {
        id: 2,
        action: "edited",
        note: null,
        changed_fields: ["description", "subject_ids"],
        user: { id: 9, name: "Directora", role: "director" },
        created_at: "2026-10-20T11:00:00+00:00",
      },
      {
        id: 3,
        action: "rejected",
        note: "No corresponde",
        changed_fields: null,
        user: { id: 1, name: "Ana", role: "psychopedagogue" },
        created_at: "2026-10-21T10:00:00+00:00",
      },
    ])
    renderSection("director")

    expect(await screen.findByText("Rechazada por Psicopedagogía")).toBeInTheDocument()
    expect(screen.getByText(/motivo del rechazo/i)).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /retirar propuesta/i })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole("button", { name: /ver historial/i }))
    expect(await screen.findByText(/directora \(director\): envió la propuesta a revisión/i)).toBeInTheDocument()
    expect(screen.getByText(/la rechazó/i)).toBeInTheDocument()
    // Who changed what: the fields an edit touched, never their content.
    expect(screen.getByText(/editó la propuesta \(descripción, materias donde aplica\)/i)).toBeInTheDocument()
  })

  it("refetches the list when someone else already moved the proposal", async () => {
    const fetch = vi
      .spyOn(trackingApi, "fetchAccommodationProposals")
      .mockResolvedValueOnce([proposal()])
      .mockResolvedValueOnce([proposal({ review_status: "withdrawn", review_note: "Ya no hace falta" })])
    vi.spyOn(trackingApi, "reviewAccommodationProposal").mockRejectedValue({
      isAxiosError: true,
      response: { status: 422, data: { errors: { accommodation: ["Not in this state."] } } },
    })
    renderSection("psychopedagogue")

    await userEvent.click(await screen.findByRole("button", { name: /^rechazar$/i }))
    await userEvent.type(screen.getByLabelText(/motivo del rechazo/i), "No corresponde")
    await userEvent.click(screen.getByRole("button", { name: /rechazar propuesta/i }))

    // It shows where the proposal stands now, with no step left to take.
    expect(await screen.findByText("Retirada por Dirección")).toBeInTheDocument()
    expect(fetch).toHaveBeenCalledTimes(2)
    expect(screen.queryByRole("button", { name: /^rechazar$/i })).not.toBeInTheDocument()
  })
})
