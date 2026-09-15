import { render, screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, describe, expect, it, vi } from "vitest"
import { CommentsPanel } from "./CommentsPanel"
import type { Comment } from "@/types"

/** Opens a SingleSelect (found by its label) and clicks the option by its text. */
async function choose(label: RegExp, optionName: string | RegExp) {
  await userEvent.click(screen.getByLabelText(label))
  await userEvent.click(await screen.findByRole("button", { name: optionName }))
}

const sampleComment: Comment = {
  id: 1,
  author_id: 7,
  commentable_type: "Student",
  commentable_id: 3,
  content: "Avanzó mucho este mes.",
  tone: "positive",
  visible_to: ["psychopedagogue", "director"],
  author_only: false,
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
    // A non-private comment shows no "Privado" badge.
    expect(screen.queryByText("Privado")).not.toBeInTheDocument()
  })

  it("shows a 'Privado' badge for author_only comments and hides the visibility line", () => {
    const privateComment: Comment = {
      ...sampleComment,
      id: 2,
      // The backend forces visible_to to null for author_only comments; even if
      // a stray value arrived, the "Visible para: …" line must not render.
      visible_to: ["director"],
      author_only: true,
    }
    render(<CommentsPanel comments={[privateComment]} onCreate={vi.fn()} canComment={false} />)

    expect(screen.getByText("Privado")).toBeInTheDocument()
    expect(screen.queryByText(/visible para:/i)).not.toBeInTheDocument()
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

  it("defaults the scope selector to 'Todos los que ven este registro'", () => {
    render(<CommentsPanel comments={[]} onCreate={vi.fn()} />)

    const trigger = screen.getByLabelText(/visible para/i)
    expect(within(trigger).getByText("Todos los que ven este registro")).toBeInTheDocument()
  })

  it("scope 'everyone' OMITS both visible_to and author_only", async () => {
    const onCreate = vi.fn().mockResolvedValue(undefined)
    render(<CommentsPanel comments={[]} onCreate={onCreate} />)

    await userEvent.type(screen.getByLabelText(/nuevo comentario/i), "Observación general")
    await userEvent.click(screen.getByRole("button", { name: /comentar/i }))

    expect(onCreate).toHaveBeenCalledTimes(1)
    const payload = onCreate.mock.calls[0][0]
    // The Session 8 rule (docs/prompts/08 §3): an empty selection must NOT
    // become visible_to: [] (which would hide the comment from everyone). The
    // field is simply absent — and so is author_only.
    expect(payload).not.toHaveProperty("visible_to")
    expect(payload).not.toHaveProperty("author_only")
    expect(payload).toEqual({ content: "Observación general", tone: null })
  })

  it("scope 'director' sends visible_to: ['director'] and the chosen tone", async () => {
    const onCreate = vi.fn().mockResolvedValue(undefined)
    render(<CommentsPanel comments={[]} onCreate={onCreate} />)

    await userEvent.type(screen.getByLabelText(/nuevo comentario/i), "Solo dirección")
    await choose(/tono/i, "Preocupante")
    await choose(/visible para/i, "Solo dirección")
    await userEvent.click(screen.getByRole("button", { name: /comentar/i }))

    expect(onCreate).toHaveBeenCalledWith({
      content: "Solo dirección",
      tone: "concerning",
      visible_to: ["director"],
    })
    expect(onCreate.mock.calls[0][0]).not.toHaveProperty("author_only")
  })

  it("scope 'psychopedagogue' sends visible_to: ['psychopedagogue']", async () => {
    const onCreate = vi.fn().mockResolvedValue(undefined)
    render(<CommentsPanel comments={[]} onCreate={onCreate} />)

    await userEvent.type(screen.getByLabelText(/nuevo comentario/i), "Solo psico")
    await choose(/visible para/i, "Solo psicopedagogía")
    await userEvent.click(screen.getByRole("button", { name: /comentar/i }))

    const payload = onCreate.mock.calls[0][0]
    expect(payload).toEqual({
      content: "Solo psico",
      tone: null,
      visible_to: ["psychopedagogue"],
    })
    expect(payload).not.toHaveProperty("author_only")
  })

  it("scope 'author_only' sends author_only: true and never visible_to", async () => {
    const onCreate = vi.fn().mockResolvedValue(undefined)
    render(<CommentsPanel comments={[]} onCreate={onCreate} />)

    await userEvent.type(screen.getByLabelText(/nuevo comentario/i), "Nota personal")
    await choose(/visible para/i, "Solo quien escribe")
    await userEvent.click(screen.getByRole("button", { name: /comentar/i }))

    const payload = onCreate.mock.calls[0][0]
    expect(payload).toEqual({
      content: "Nota personal",
      tone: null,
      author_only: true,
    })
    // Mutually exclusive: visible_to is never sent alongside author_only.
    expect(payload).not.toHaveProperty("visible_to")
  })

  it("resets the form after a successful submit", async () => {
    const onCreate = vi.fn().mockResolvedValue(undefined)
    render(<CommentsPanel comments={[]} onCreate={onCreate} />)

    const textarea = screen.getByLabelText<HTMLTextAreaElement>(/nuevo comentario/i)
    await userEvent.type(textarea, "Algo")
    await choose(/visible para/i, "Solo quien escribe")
    await userEvent.click(screen.getByRole("button", { name: /comentar/i }))

    expect(textarea.value).toBe("")
    // Scope resets to the default, not the last-used option.
    expect(
      within(screen.getByLabelText(/visible para/i)).getByText(
        "Todos los que ven este registro"
      )
    ).toBeInTheDocument()
  })

  it("hides the form when canComment is false", () => {
    render(<CommentsPanel comments={[]} onCreate={vi.fn()} canComment={false} />)

    expect(screen.queryByRole("button", { name: /comentar/i })).not.toBeInTheDocument()
  })
})
