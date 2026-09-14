import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { describe, expect, it, vi } from "vitest"
import { ScreeningTestDesignCard } from "./ScreeningTestDesignCard"
import type { ScreeningTestDesign } from "@/types"

function design(approved: boolean | null): ScreeningTestDesign {
  return {
    id: 5,
    screening_test_type_id: 1,
    cutoff_low: 4,
    cutoff_high: 7,
    meaning_red: "Necesita apoyo",
    meaning_yellow: "En proceso",
    meaning_green: "Logrado",
    approved,
    approved_by_id: null,
    created_by_id: 2,
  }
}

function renderCard({
  approved,
  canApprove,
}: {
  approved: boolean | null
  canApprove: boolean
}) {
  const onApprove = vi.fn()
  const onReject = vi.fn()
  render(
    <ScreeningTestDesignCard
      design={design(approved)}
      canApprove={canApprove}
      onApprove={onApprove}
      onReject={onReject}
    />,
  )
  return { onApprove, onReject }
}

describe("ScreeningTestDesignCard", () => {
  it("shows the Aprobar/Rechazar buttons only when pending AND the viewer may approve", () => {
    renderCard({ approved: null, canApprove: true })

    expect(screen.getByText("Pendiente de aprobación")).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Aprobar" })).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Rechazar" })).toBeInTheDocument()
  })

  it("hides the buttons for a pending design when the viewer cannot approve", () => {
    renderCard({ approved: null, canApprove: false })

    expect(screen.getByText("Pendiente de aprobación")).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Aprobar" })).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Rechazar" })).not.toBeInTheDocument()
  })

  it("hides the buttons for an already-approved design even for a director", () => {
    renderCard({ approved: true, canApprove: true })

    expect(screen.getByText("Aprobado (vigente)")).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Aprobar" })).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Rechazar" })).not.toBeInTheDocument()
  })

  it("hides the buttons for an already-rejected design even for a director", () => {
    renderCard({ approved: false, canApprove: true })

    expect(screen.getByText("Rechazado")).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Aprobar" })).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Rechazar" })).not.toBeInTheDocument()
  })

  it("invokes the callbacks with the design when a director acts", async () => {
    const { onApprove, onReject } = renderCard({ approved: null, canApprove: true })

    await userEvent.click(screen.getByRole("button", { name: "Aprobar" }))
    expect(onApprove).toHaveBeenCalledWith(design(null))

    await userEvent.click(screen.getByRole("button", { name: "Rechazar" }))
    expect(onReject).toHaveBeenCalledWith(design(null))
  })
})
