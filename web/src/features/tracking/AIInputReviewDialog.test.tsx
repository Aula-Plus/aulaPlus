import { render, screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import type { AIInputLine, AIInputReview } from "@/types"
import { AIInputReviewDialog } from "./AIInputReviewDialog"
import * as aiInputApi from "./aiInputApi"

function line(overrides: Partial<AIInputLine> = {}): AIInputLine {
  return {
    key: "a".repeat(64),
    action: "Dar 15 minutos más en escritos",
    category: "access",
    subjects: ["Matemática"],
    assessment_types: ["written"],
    sent: true,
    mark: null,
    origins: [{ accommodation_id: 7, student_id: 3, student_name: "Lucía Pereyra" }],
    ...overrides,
  }
}

function review(overrides: Partial<AIInputReview> = {}): AIInputReview {
  return {
    subject: null,
    subjects: [
      { id: 1, name: "Matemática" },
      { id: 2, name: "Biología" },
    ],
    lines: [line()],
    can: { mark: true },
    ...overrides,
  }
}

function renderDialog() {
  return render(
    <MemoryRouter>
      <AIInputReviewDialog groupId={5} open onOpenChange={vi.fn()} />
    </MemoryRouter>,
  )
}

describe("AIInputReviewDialog", () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("shows each line as the AI receives it, with access to its origin adjustment", async () => {
    vi.spyOn(aiInputApi, "fetchAIInputReview").mockResolvedValue(review())

    renderDialog()

    expect(await screen.findByText("Dar 15 minutos más en escritos")).toBeInTheDocument()
    expect(screen.getByText("· Matemática")).toBeInTheDocument()
    expect(screen.getByText("· Escrita")).toBeInTheDocument()
    expect(screen.getByText("Acceso")).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "Lucía Pereyra" })).toHaveAttribute("href", "/alumnos/3/seguimiento")
  })

  it("reloads for the chosen subject, which is optional", async () => {
    const fetch = vi.spyOn(aiInputApi, "fetchAIInputReview").mockResolvedValue(review())
    const user = userEvent.setup()

    renderDialog()
    await screen.findByText("Dar 15 minutos más en escritos")
    expect(fetch).toHaveBeenLastCalledWith(5, null)

    await user.selectOptions(screen.getByLabelText("Materia (opcional)"), "2")

    await waitFor(() => expect(fetch).toHaveBeenLastCalledWith(5, 2))
  })

  it("lets Psicopedagogía mark a line, warning not to write names or health data", async () => {
    vi.spyOn(aiInputApi, "fetchAIInputReview").mockResolvedValue(review())
    const mark = vi.spyOn(aiInputApi, "markAIInputLine").mockResolvedValue()
    const user = userEvent.setup()

    renderDialog()
    await user.click(await screen.findByRole("button", { name: "La IA lo entendió mal" }))

    expect(screen.getByRole("note")).toHaveTextContent("No escribas nombres de alumnos ni datos de salud.")
    await user.type(screen.getByLabelText("Comentario (opcional)"), "Lo tomó para todo el curso")
    await user.click(screen.getByRole("button", { name: "Marcar la línea" }))

    expect(mark).toHaveBeenCalledWith(5, {
      line_key: "a".repeat(64),
      subject_id: null,
      comment: "Lo tomó para todo el curso",
    })
    expect(await screen.findByRole("status")).toHaveTextContent("no se envía a la IA")
  })

  it("offers «El ajuste está bien» on a marked line, which is flagged as not sent", async () => {
    const marked = line({
      sent: false,
      mark: { id: 9, comment: "Mal leído", subject_name: null, marked_by: { id: 1, name: "Laura Ferrando" }, created_at: "2026-11-10T10:00:00Z" },
    })
    vi.spyOn(aiInputApi, "fetchAIInputReview").mockResolvedValue(review({ lines: [marked] }))
    const confirm = vi.spyOn(aiInputApi, "confirmAIInputAdjustment").mockResolvedValue()
    const user = userEvent.setup()

    renderDialog()

    expect(await screen.findByText("No se envía mientras la marca esté pendiente")).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "La IA lo entendió mal" })).not.toBeInTheDocument()
    await user.click(screen.getByRole("button", { name: "El ajuste está bien" }))
    await user.click(screen.getByRole("button", { name: "Confirmar que el ajuste está bien" }))

    expect(confirm).toHaveBeenCalledWith(9, "")
  })

  it("shows the server's validation message", async () => {
    vi.spyOn(aiInputApi, "fetchAIInputReview").mockResolvedValue(review())
    vi.spyOn(aiInputApi, "markAIInputLine").mockRejectedValue(new Error("boom"))
    vi.spyOn(aiInputApi, "validationMessage").mockReturnValue("No escribas nombres de alumnos en el comentario.")
    const user = userEvent.setup()

    renderDialog()
    await user.click(await screen.findByRole("button", { name: "La IA lo entendió mal" }))
    await user.click(screen.getByRole("button", { name: "Marcar la línea" }))

    expect(await screen.findByText("No escribas nombres de alumnos en el comentario.")).toBeInTheDocument()
  })

  it("is read only for Dirección: no mark actions", async () => {
    vi.spyOn(aiInputApi, "fetchAIInputReview").mockResolvedValue(review({ can: { mark: false } }))

    renderDialog()

    const item = (await screen.findByText("Dar 15 minutos más en escritos")).closest("li")!
    expect(within(item).queryByRole("button")).not.toBeInTheDocument()
    expect(screen.getByText("Dirección la abre solo para leer: no marca líneas.")).toBeInTheDocument()
  })
})
