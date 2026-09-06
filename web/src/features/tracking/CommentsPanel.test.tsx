import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, describe, expect, it, vi } from "vitest"
import { CommentsPanel } from "./CommentsPanel"
import * as trackingApi from "./trackingApi"
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
    render(
      <CommentsPanel
        subject={{ type: "student", id: 3 }}
        comments={[sampleComment]}
        onCommentAdded={vi.fn()}
      />,
    )

    expect(screen.getByText("Avanzó mucho este mes.")).toBeInTheDocument()
    expect(screen.getByText(/visible para: psicopedagogo, director/i)).toBeInTheDocument()
  })

  it("does not submit and shows an error when the content is empty", async () => {
    const post = vi.spyOn(trackingApi, "postStudentComment").mockResolvedValue(sampleComment)
    render(
      <CommentsPanel subject={{ type: "student", id: 3 }} comments={[]} onCommentAdded={vi.fn()} />,
    )

    await userEvent.click(screen.getByRole("button", { name: /comentar/i }))

    expect(await screen.findByText(/escribí un comentario/i)).toBeInTheDocument()
    expect(post).not.toHaveBeenCalled()
  })

  it("OMITS visible_to (never sends []) when no role is selected", async () => {
    const post = vi.spyOn(trackingApi, "postStudentComment").mockResolvedValue(sampleComment)
    const onCommentAdded = vi.fn()
    render(
      <CommentsPanel
        subject={{ type: "student", id: 3 }}
        comments={[]}
        onCommentAdded={onCommentAdded}
      />,
    )

    await userEvent.type(screen.getByLabelText(/nuevo comentario/i), "Observación general")
    await userEvent.click(screen.getByRole("button", { name: /comentar/i }))

    expect(post).toHaveBeenCalledTimes(1)
    const [studentId, body] = post.mock.calls[0]
    expect(studentId).toBe(3)
    // The whole point of §3: an empty selection must NOT become visible_to: []
    // (which would hide the comment from everyone, author included).
    expect(body).not.toHaveProperty("visible_to")
    expect(body).toEqual({ content: "Observación general", tone: null })
    expect(onCommentAdded).toHaveBeenCalled()
  })

  it("sends visible_to with the selected roles", async () => {
    const post = vi.spyOn(trackingApi, "postStudentComment").mockResolvedValue(sampleComment)
    render(
      <CommentsPanel subject={{ type: "student", id: 3 }} comments={[]} onCommentAdded={vi.fn()} />,
    )

    await userEvent.type(screen.getByLabelText(/nuevo comentario/i), "Comentario reservado")
    await userEvent.click(screen.getByTestId("multi-select-trigger"))
    await userEvent.click(screen.getByRole("button", { name: /psicopedagogo/i }))
    await userEvent.click(screen.getByRole("button", { name: /^director$/i }))
    await userEvent.click(screen.getByRole("button", { name: /comentar/i }))

    expect(post).toHaveBeenCalledWith(3, {
      content: "Comentario reservado",
      tone: null,
      visible_to: ["psychopedagogue", "director"],
    })
  })

  it("posts to the group endpoint when the subject is a group", async () => {
    const post = vi.spyOn(trackingApi, "postGroupComment").mockResolvedValue(sampleComment)
    render(
      <CommentsPanel subject={{ type: "group", id: 9 }} comments={[]} onCommentAdded={vi.fn()} />,
    )

    await userEvent.type(screen.getByLabelText(/nuevo comentario/i), "Comentario de clase")
    await userEvent.click(screen.getByRole("button", { name: /comentar/i }))

    expect(post).toHaveBeenCalledWith(9, { content: "Comentario de clase", tone: null })
  })
})
