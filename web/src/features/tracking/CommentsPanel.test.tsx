import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, describe, expect, it, vi } from "vitest"
import { CommentsPanel } from "./CommentsPanel"
import type { Comment } from "@/types"

const sampleComment: Comment = {
  id: 1,
  author_id: 7,
  commentable_type: "Student",
  commentable_id: 3,
  content: "Avanzó mucho este mes.",
  tone: "positive",
  visible_to: ["psychopedagogue", "director"],
  created_at: "2026-08-01T10:00:00+00:00",
}

describe("CommentsPanel", () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("renders existing comments with tone and visibility", () => {
    // canComment={false} so the tone <option>s in the form don't collide with
    // the badge text we assert on here — this test is about the list.
    render(<CommentsPanel comments={[sampleComment]} onCreate={vi.fn()} canComment={false} />)

    expect(screen.getByText("Avanzó mucho este mes.")).toBeInTheDocument()
    expect(screen.getByText("Positivo")).toBeInTheDocument()
    expect(screen.getByText(/visible para: psicopedagogo, director/i)).toBeInTheDocument()
  })

  it("shows an empty state when there are no comments", () => {
    render(<CommentsPanel comments={[]} onCreate={vi.fn()} />)

    expect(screen.getByText(/todavía no hay comentarios/i)).toBeInTheDocument()
  })

  it("does not submit and shows an error when the content is empty", async () => {
    const onCreate = vi.fn().mockResolvedValue(undefined)
    render(<CommentsPanel comments={[]} onCreate={onCreate} />)

    await userEvent.click(screen.getByRole("button", { name: /comentar/i }))

    expect(await screen.findByText(/escribí un comentario/i)).toBeInTheDocument()
    expect(onCreate).not.toHaveBeenCalled()
  })

  it("OMITS visible_to (never sends []) when no role is selected", async () => {
    const onCreate = vi.fn().mockResolvedValue(undefined)
    render(<CommentsPanel comments={[]} onCreate={onCreate} />)

    await userEvent.type(screen.getByLabelText(/nuevo comentario/i), "Observación general")
    await userEvent.click(screen.getByRole("button", { name: /comentar/i }))

    expect(onCreate).toHaveBeenCalledTimes(1)
    const payload = onCreate.mock.calls[0][0]
    // The whole point of docs/prompts/08 §3: an empty selection must NOT
    // become visible_to: [] (which would hide the comment from everyone,
    // author included). The field is simply absent.
    expect(payload).not.toHaveProperty("visible_to")
    expect(payload).toEqual({ content: "Observación general", tone: null })
  })

  it("sends visible_to with the selected roles and the chosen tone", async () => {
    const onCreate = vi.fn().mockResolvedValue(undefined)
    render(<CommentsPanel comments={[]} onCreate={onCreate} />)

    await userEvent.type(screen.getByLabelText(/nuevo comentario/i), "Comentario reservado")
    await userEvent.selectOptions(screen.getByLabelText(/tono/i), "concerning")
    await userEvent.click(screen.getByRole("checkbox", { name: /psicopedagogo/i }))
    await userEvent.click(screen.getByRole("checkbox", { name: /director/i }))
    await userEvent.click(screen.getByRole("button", { name: /comentar/i }))

    expect(onCreate).toHaveBeenCalledWith({
      content: "Comentario reservado",
      tone: "concerning",
      visible_to: ["psychopedagogue", "director"],
    })
  })

  it("resets the form after a successful submit", async () => {
    const onCreate = vi.fn().mockResolvedValue(undefined)
    render(<CommentsPanel comments={[]} onCreate={onCreate} />)

    const textarea = screen.getByLabelText<HTMLTextAreaElement>(/nuevo comentario/i)
    await userEvent.type(textarea, "Algo")
    await userEvent.click(screen.getByRole("button", { name: /comentar/i }))

    expect(textarea.value).toBe("")
  })

  it("hides the form when canComment is false", () => {
    render(<CommentsPanel comments={[]} onCreate={vi.fn()} canComment={false} />)

    expect(screen.queryByRole("button", { name: /comentar/i })).not.toBeInTheDocument()
  })
})
