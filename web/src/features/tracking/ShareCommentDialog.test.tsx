import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, describe, expect, it, vi } from "vitest"
import { ShareCommentDialog } from "./ShareCommentDialog"
import * as scheduledFollowUpsApi from "./scheduledFollowUpsApi"
import * as trackingApi from "./trackingApi"
import type { Comment } from "@/types"

const comment: Comment = {
  id: 9,
  author_id: 2,
  commentable_type: "Student",
  commentable_id: 3,
  content: "Observación original reservada",
  categories: [],
  visible_to: ["psychopedagogue"],
  author_only: false,
  created_at: "2026-08-20T10:00:00+00:00",
}

function renderDialog(overrides: Partial<Parameters<typeof ShareCommentDialog>[0]> = {}) {
  const props = {
    studentId: 3,
    comment,
    onOpenChange: vi.fn(),
    onShared: vi.fn(),
    ...overrides,
  }
  render(<ShareCommentDialog {...props} />)
  return props
}

describe("ShareCommentDialog", () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("lists only direction and teachers who see the student, and never prefills the original", async () => {
    vi.spyOn(scheduledFollowUpsApi, "fetchFollowUpCandidates").mockResolvedValue([
      { id: 4, name: "Dora Directora", role: "director" },
      { id: 5, name: "Tomás Docente", role: "teacher" },
      { id: 6, name: "Paula Psico", role: "psychopedagogue" },
    ])
    renderDialog()

    expect(await screen.findByRole("checkbox", { name: /dora directora/i })).toBeInTheDocument()
    expect(screen.getByRole("checkbox", { name: /tomás docente/i })).toBeInTheDocument()
    expect(screen.queryByRole("checkbox", { name: /paula psico/i })).not.toBeInTheDocument()
    expect(screen.getByLabelText<HTMLTextAreaElement>("Extracto").value).toBe("")
    expect(screen.getByText(/ve solo el extracto, no la observación original/i)).toBeInTheDocument()
  })

  it("requires a recipient and an extract before sharing", async () => {
    vi.spyOn(scheduledFollowUpsApi, "fetchFollowUpCandidates").mockResolvedValue([
      { id: 4, name: "Dora Directora", role: "director" },
    ])
    const share = vi.spyOn(trackingApi, "shareComment")
    renderDialog()
    await screen.findByRole("checkbox", { name: /dora directora/i })

    await userEvent.click(screen.getByRole("button", { name: "Compartir extracto" }))
    expect(await screen.findByText("Elegí al menos una persona.")).toBeInTheDocument()

    await userEvent.click(screen.getByRole("checkbox", { name: /dora directora/i }))
    await userEvent.click(screen.getByRole("button", { name: "Compartir extracto" }))
    expect(await screen.findByText("Escribí el extracto que vas a compartir.")).toBeInTheDocument()
    expect(share).not.toHaveBeenCalled()
  })

  it("sends the extract with the chosen recipients and hands back the created share", async () => {
    vi.spyOn(scheduledFollowUpsApi, "fetchFollowUpCandidates").mockResolvedValue([
      { id: 4, name: "Dora Directora", role: "director" },
      { id: 5, name: "Tomás Docente", role: "teacher" },
    ])
    const created = { id: 1, student_id: 3, author_id: 1, content: "Extracto", created_at: null }
    const share = vi.spyOn(trackingApi, "shareComment").mockResolvedValue(created)
    const { onShared, onOpenChange } = renderDialog()

    await userEvent.click(await screen.findByRole("checkbox", { name: /dora directora/i }))
    await userEvent.click(screen.getByRole("checkbox", { name: /tomás docente/i }))
    await userEvent.type(screen.getByLabelText("Extracto"), "  Extracto  ")
    await userEvent.click(screen.getByRole("button", { name: "Compartir extracto" }))

    expect(share).toHaveBeenCalledWith(9, { content: "Extracto", recipient_ids: [4, 5] })
    expect(onShared).toHaveBeenCalledWith(created)
    expect(onOpenChange).toHaveBeenCalledWith(false)
  })

  it("shows an error when the server rejects the share", async () => {
    vi.spyOn(scheduledFollowUpsApi, "fetchFollowUpCandidates").mockResolvedValue([
      { id: 4, name: "Dora Directora", role: "director" },
    ])
    vi.spyOn(trackingApi, "shareComment").mockRejectedValue(new Error("422"))
    const { onShared } = renderDialog()

    await userEvent.click(await screen.findByRole("checkbox", { name: /dora directora/i }))
    await userEvent.type(screen.getByLabelText("Extracto"), "Extracto")
    await userEvent.click(screen.getByRole("button", { name: "Compartir extracto" }))

    expect(await screen.findByText("No pudimos compartir el extracto.")).toBeInTheDocument()
    expect(onShared).not.toHaveBeenCalled()
  })

  it("renders nothing while no comment is selected", () => {
    const fetch = vi.spyOn(scheduledFollowUpsApi, "fetchFollowUpCandidates").mockResolvedValue([])
    renderDialog({ comment: null })

    expect(screen.queryByText("Compartir con")).not.toBeInTheDocument()
    expect(fetch).not.toHaveBeenCalled()
  })

  it("copies the original into the extract with «Usar el texto completo», still editable", async () => {
    vi.spyOn(scheduledFollowUpsApi, "fetchFollowUpCandidates").mockResolvedValue([
      { id: 4, name: "Dora Directora", role: "director" },
    ])
    const share = vi.spyOn(trackingApi, "shareComment").mockResolvedValue({
      id: 1,
      student_id: 3,
      author_id: 2,
      content: "Observación original reservada. Editado",
      created_at: null,
    } as never)
    renderDialog()
    await userEvent.click(await screen.findByRole("checkbox", { name: /dora directora/i }))

    await userEvent.click(screen.getByRole("button", { name: /usar el texto completo/i }))
    const textarea = screen.getByLabelText<HTMLTextAreaElement>("Extracto")
    expect(textarea.value).toBe("Observación original reservada")

    await userEvent.type(textarea, ". Editado")
    await userEvent.click(screen.getByRole("button", { name: /compartir extracto/i }))

    expect(share).toHaveBeenCalledWith(9, {
      content: "Observación original reservada. Editado",
      recipient_ids: [4],
    })
  })
})
