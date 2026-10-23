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

/** The "Visible para" radio whose label starts with `label` (the explanation follows it). */
function scopeRadio(label: string) {
  return screen.getByRole("radio", { name: new RegExp(`^${label}`) })
}

async function chooseScope(label: string) {
  await userEvent.click(scopeRadio(label))
}

/** Picks the signal for one category (ClickUp 86e3qkwpn). */
async function chooseSignal(category: string, signal: string) {
  const group = screen.getByRole("group", { name: `Señal en ${category}` })
  await userEvent.click(within(group).getByRole("radio", { name: signal }))
}

async function pickCategories(names: string[]) {
  await userEvent.click(screen.getByLabelText(/de qué trata/i))
  for (const name of names) {
    await userEvent.click(await screen.findByRole("button", { name }))
  }
  await userEvent.keyboard("{Escape}")
}

const signalCategories = [
  { id: 1, name: "Social", position: 0 },
  { id: 2, name: "Académico", position: 1 },
  { id: 3, name: "Emocional", position: 2 },
]

const sampleComment: Comment = {
  id: 1,
  author_id: 7,
  commentable_type: "Student",
  commentable_id: 3,
  content: "Avanzó mucho este mes.",
  categories: [{ id: 1, name: "Académico" }],
  visible_to: ["psychopedagogue", "director"],
  author_only: false,
  created_at: "2026-08-01T10:00:00+00:00",
}

describe("CommentsPanel", () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("renders existing comments with categories and visibility", () => {
    // canComment={false} so the form's controls don't collide with the badge
    // text we assert on here — this test is about the list.
    render(<CommentsPanel comments={[sampleComment]} onCreate={vi.fn()} canComment={false} />)

    expect(screen.getByText("Avanzó mucho este mes.")).toBeInTheDocument()
    expect(screen.getByText("Académico")).toBeInTheDocument()
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

  it("defaults the scope selector to 'Todos los que ven al alumno'", () => {
    render(<CommentsPanel comments={[]} onCreate={vi.fn()} />)

    expect(scopeRadio("Todos los que ven al alumno")).toBeChecked()
  })

  it("offers exactly the four scopes, each with its explanation under it", () => {
    render(<CommentsPanel comments={[]} onCreate={vi.fn()} />)

    const group = screen.getByRole("group", { name: /visible para/i })
    expect(within(group).getAllByRole("radio")).toHaveLength(4)
    expect(scopeRadio("Todos los que ven al alumno")).toHaveAccessibleDescription(
      "Docentes con acceso al alumno, Psicopedagogía y Dirección.",
    )
    expect(scopeRadio("Dirección y Psicopedagogía")).toBeInTheDocument()
    expect(scopeRadio("Solo Psicopedagogía")).toBeInTheDocument()
    expect(scopeRadio("Solo quien escribe")).toHaveAccessibleDescription(
      "Nadie más la lee ni suma a la tendencia de otros.",
    )
    // The old option is gone.
    expect(screen.queryByText("Solo dirección")).not.toBeInTheDocument()
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
    expect(payload).toEqual({ content: "Observación general" })
  })

  it("scope 'Dirección y Psicopedagogía' sends both roles and the chosen categories", async () => {
    const onCreate = vi.fn().mockResolvedValue(undefined)
    render(
      <CommentsPanel
        comments={[]}
        onCreate={onCreate}
        categories={[
          { id: 1, name: "Emocional", position: 0 },
          { id: 2, name: "Académico", position: 1 },
        ]}
      />,
    )

    await userEvent.type(screen.getByLabelText(/nuevo comentario/i), "Dirección y psico")
    await userEvent.click(screen.getByLabelText(/de qué trata/i))
    await userEvent.click(await screen.findByRole("button", { name: "Emocional" }))
    await userEvent.click(await screen.findByRole("button", { name: "Académico" }))
    await userEvent.keyboard("{Escape}")
    await chooseSignal("Emocional", "Señal de dificultad")
    await chooseSignal("Académico", "Señal de mejora")
    await chooseScope("Dirección y Psicopedagogía")
    await userEvent.click(screen.getByRole("button", { name: /comentar/i }))

    expect(onCreate).toHaveBeenCalledWith({
      content: "Dirección y psico",
      category_ids: [1, 2],
      category_signals: { 1: "difficulty", 2: "improvement" },
      visible_to: ["director", "psychopedagogue"],
    })
    expect(onCreate.mock.calls[0][0]).not.toHaveProperty("author_only")
  })

  it("scope 'psychopedagogue' sends visible_to: ['psychopedagogue']", async () => {
    const onCreate = vi.fn().mockResolvedValue(undefined)
    render(<CommentsPanel comments={[]} onCreate={onCreate} />)

    await userEvent.type(screen.getByLabelText(/nuevo comentario/i), "Solo psico")
    await chooseScope("Solo Psicopedagogía")
    await userEvent.click(screen.getByRole("button", { name: /comentar/i }))

    const payload = onCreate.mock.calls[0][0]
    expect(payload).toEqual({
      content: "Solo psico",
      visible_to: ["psychopedagogue"],
    })
    expect(payload).not.toHaveProperty("author_only")
  })

  it("scope 'author_only' sends author_only: true and never visible_to", async () => {
    const onCreate = vi.fn().mockResolvedValue(undefined)
    render(<CommentsPanel comments={[]} onCreate={onCreate} />)

    await userEvent.type(screen.getByLabelText(/nuevo comentario/i), "Nota personal")
    await chooseScope("Solo quien escribe")
    await userEvent.click(screen.getByRole("button", { name: /comentar/i }))

    const payload = onCreate.mock.calls[0][0]
    expect(payload).toEqual({
      content: "Nota personal",
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
    await chooseScope("Solo quien escribe")
    await userEvent.click(screen.getByRole("button", { name: /comentar/i }))

    expect(textarea.value).toBe("")
    // Scope resets to the default, not the last-used option.
    expect(scopeRadio("Todos los que ven al alumno")).toBeChecked()
  })

  it("renders per-comment actions and content before the list when given", () => {
    render(
      <CommentsPanel
        comments={[sampleComment]}
        onCreate={vi.fn()}
        canComment={false}
        beforeList={<p>Antes de la lista</p>}
        renderCommentActions={(comment) => <button type="button">Acción {comment.id}</button>}
      />,
    )

    expect(screen.getByText("Antes de la lista")).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Acción 1" })).toBeInTheDocument()
  })

  it("hides the form when canComment is false", () => {
    render(<CommentsPanel comments={[]} onCreate={vi.fn()} canComment={false} />)

    expect(screen.queryByRole("button", { name: /comentar/i })).not.toBeInTheDocument()
  })
})

describe("CommentsPanel observations bank", () => {
  const mine: Comment = {
    ...sampleComment,
    id: 10,
    author_id: 7,
    author: { id: 7, name: "Mariana", role: "teacher" },
    categories: [{ id: 1, name: "Académico" }],
  }
  const other: Comment = {
    ...sampleComment,
    id: 11,
    content: "Trabaja mejor en grupo.",
    author_id: 8,
    author: { id: 8, name: "Lucía", role: "psychopedagogue" },
    categories: [],
  }

  it("shows author name and role, and 'Vos' for the viewer's own comments", () => {
    render(
      <CommentsPanel comments={[mine, other]} onCreate={vi.fn()} canComment={false} showAuthors currentUserId={7} />,
    )

    expect(screen.getByText("Vos")).toBeInTheDocument()
    expect(screen.getByText("Lucía")).toBeInTheDocument()
    expect(screen.getByText(/^·\s*Psicopedagogo$/)).toBeInTheDocument()
  })

  it("filters by author", async () => {
    render(
      <CommentsPanel comments={[mine, other]} onCreate={vi.fn()} canComment={false} showAuthors currentUserId={7} />,
    )

    await choose(/filtrar por autor/i, "Lucía · Psicopedagogo")

    expect(screen.getByText("Trabaja mejor en grupo.")).toBeInTheDocument()
    expect(screen.queryByText("Avanzó mucho este mes.")).not.toBeInTheDocument()
  })

  it("labels the viewer as 'Vos' in the author filter and tells same-name authors apart by role", async () => {
    const namesake: Comment = {
      ...sampleComment,
      id: 12,
      content: "Mejoró la convivencia.",
      author_id: 9,
      author: { id: 9, name: "Lucía", role: "director" },
      categories: [],
    }
    render(
      <CommentsPanel
        comments={[mine, other, namesake]}
        onCreate={vi.fn()}
        canComment={false}
        showAuthors
        currentUserId={7}
      />,
    )

    await userEvent.click(screen.getByLabelText(/filtrar por autor/i))
    expect(await screen.findByRole("button", { name: "Vos" })).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /mariana/i })).not.toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Lucía · Psicopedagogo" })).toBeInTheDocument()

    await userEvent.click(screen.getByRole("button", { name: "Lucía · Director" }))

    expect(screen.getByText("Mejoró la convivencia.")).toBeInTheDocument()
    expect(screen.queryByText("Trabaja mejor en grupo.")).not.toBeInTheDocument()
    expect(screen.queryByText("Avanzó mucho este mes.")).not.toBeInTheDocument()
  })

  it("filters by category, including comments without a category", async () => {
    const categories = [
      { id: 1, name: "Académico", position: 1 },
      { id: 2, name: "Conducta", position: 2 },
    ]
    render(
      <CommentsPanel
        comments={[mine, other]}
        onCreate={vi.fn()}
        canComment={false}
        categories={categories}
        showAuthors
        currentUserId={7}
      />,
    )

    await choose(/filtrar por categoría/i, "Académico")
    expect(screen.getByText("Avanzó mucho este mes.")).toBeInTheDocument()
    expect(screen.queryByText("Trabaja mejor en grupo.")).not.toBeInTheDocument()

    await choose(/filtrar por categoría/i, "Sin categoría")
    expect(screen.getByText("Trabaja mejor en grupo.")).toBeInTheDocument()
    expect(screen.queryByText("Avanzó mucho este mes.")).not.toBeInTheDocument()

    await choose(/filtrar por categoría/i, "Conducta")
    expect(screen.getByText(/ninguna observación coincide con el filtro/i)).toBeInTheDocument()
  })

  it("shows the total only when showCount is set", () => {
    const { rerender } = render(
      <CommentsPanel comments={[mine, other]} onCreate={vi.fn()} canComment={false} title="Observaciones" showAuthors />,
    )
    expect(screen.queryByText(/observaciones \(2\)/i)).not.toBeInTheDocument()

    rerender(
      <CommentsPanel comments={[mine, other]} onCreate={vi.fn()} canComment={false} title="Observaciones" showAuthors showCount />,
    )
    expect(screen.getByText(/observaciones \(2\)/i)).toBeInTheDocument()
  })

  it("narrows a role to specific people with «Elegir personas» and sends only the picked roles", async () => {
    const onCreate = vi.fn().mockResolvedValue(undefined)
    render(
      <CommentsPanel
        comments={[]}
        onCreate={onCreate}
        currentUserId={1}
        scopeCandidates={[
          { id: 1, name: "Yo Misma", role: "teacher" },
          { id: 4, name: "Dora Directora", role: "director" },
          { id: 5, name: "Tomás Docente", role: "teacher" },
          { id: 6, name: "Paula Psico", role: "psychopedagogue" },
        ]}
      />,
    )

    await chooseScope("Dirección y Psicopedagogía")
    await userEvent.click(screen.getByRole("button", { name: /elegir personas/i }))
    const picker = screen.getByRole("group", { name: /elegir personas/i })
    // Only the roles of the chosen option, and never the author.
    expect(within(picker).queryByRole("checkbox", { name: /tomás docente/i })).not.toBeInTheDocument()
    expect(within(picker).queryByRole("checkbox", { name: /yo misma/i })).not.toBeInTheDocument()
    await userEvent.click(within(picker).getByRole("checkbox", { name: /dora directora/i }))

    await userEvent.type(screen.getByLabelText(/nuevo comentario/i), "Para Dora")
    await userEvent.click(screen.getByRole("button", { name: /comentar/i }))

    // Psicopedagogía, with nobody picked, still sees it in full: warn first.
    expect(onCreate).not.toHaveBeenCalled()
    expect(screen.getByRole("alert")).toHaveTextContent(
      /en psicopedagogía no elegiste a nadie, así que la van a ver todos los de ese rol/i,
    )
    await userEvent.click(screen.getByRole("button", { name: /publicar igual/i }))

    expect(onCreate).toHaveBeenCalledWith({
      content: "Para Dora",
      visible_to: ["director", "psychopedagogue"],
      visible_to_users: { director: [4] },
    })
  })

  it("lets the author go back and pick people in the role left whole before publishing", async () => {
    const onCreate = vi.fn().mockResolvedValue(undefined)
    render(
      <CommentsPanel
        comments={[]}
        onCreate={onCreate}
        scopeCandidates={[
          { id: 4, name: "Dora Directora", role: "director" },
          { id: 6, name: "Paula Psico", role: "psychopedagogue" },
        ]}
      />,
    )

    await chooseScope("Dirección y Psicopedagogía")
    await userEvent.click(screen.getByRole("button", { name: /elegir personas/i }))
    await userEvent.click(screen.getByRole("checkbox", { name: /dora directora/i }))
    await userEvent.type(screen.getByLabelText(/nuevo comentario/i), "Para Dora y Paula")
    await userEvent.click(screen.getByRole("button", { name: /comentar/i }))

    await userEvent.click(screen.getByRole("button", { name: /volver a elegir personas/i }))
    expect(screen.queryByRole("alert")).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole("checkbox", { name: /paula psico/i }))
    await userEvent.click(screen.getByRole("button", { name: /comentar/i }))

    // Every role narrowed: no warning, published at once.
    expect(onCreate).toHaveBeenCalledWith({
      content: "Para Dora y Paula",
      visible_to: ["director", "psychopedagogue"],
      visible_to_users: { director: [4], psychopedagogue: [6] },
    })
  })

  it("offers no «Elegir personas» for «Solo quien escribe» and clears picks when the option changes", async () => {
    const onCreate = vi.fn().mockResolvedValue(undefined)
    render(
      <CommentsPanel
        comments={[]}
        onCreate={onCreate}
        scopeCandidates={[{ id: 5, name: "Tomás Docente", role: "teacher" }]}
      />,
    )

    await userEvent.click(screen.getByRole("button", { name: /elegir personas/i }))
    await userEvent.click(screen.getByRole("checkbox", { name: /tomás docente/i }))
    await chooseScope("Solo quien escribe")
    expect(screen.queryByRole("button", { name: /elegir personas/i })).not.toBeInTheDocument()

    await userEvent.type(screen.getByLabelText(/nuevo comentario/i), "Privada")
    await userEvent.click(screen.getByRole("button", { name: /comentar/i }))

    expect(onCreate).toHaveBeenCalledWith({ content: "Privada", author_only: true })
  })

  describe("signal per category (ClickUp 86e3qkwpn)", () => {
    it("shows one signal selector per marked category and none without categories", async () => {
      render(<CommentsPanel comments={[]} onCreate={vi.fn()} categories={signalCategories} />)
      expect(screen.queryByRole("group", { name: /^Señal en/ })).not.toBeInTheDocument()

      await pickCategories(["Social"])
      expect(screen.getAllByRole("group", { name: /^Señal en/ })).toHaveLength(1)
      const social = screen.getByRole("group", { name: "Señal en Social" })
      for (const label of ["Señal de mejora", "Señal de dificultad", "Sin cambio observado"]) {
        expect(within(social).getByRole("radio", { name: label })).not.toBeChecked()
      }

      await pickCategories(["Académico"])
      expect(screen.getAllByRole("group", { name: /^Señal en/ })).toHaveLength(2)
    })

    it("requires a signal for every category before publishing", async () => {
      const onCreate = vi.fn().mockResolvedValue(undefined)
      render(<CommentsPanel comments={[]} onCreate={onCreate} categories={signalCategories} />)

      await userEvent.type(screen.getByLabelText(/nuevo comentario/i), "Observación")
      await pickCategories(["Social", "Académico"])
      await chooseSignal("Social", "Señal de dificultad")
      await userEvent.click(screen.getByRole("button", { name: /comentar/i }))

      expect(screen.getByText("Elegí una señal para cada categoría.")).toBeInTheDocument()
      expect(onCreate).not.toHaveBeenCalled()

      await chooseSignal("Académico", "Sin cambio observado")
      await userEvent.click(screen.getByRole("button", { name: /comentar/i }))
      expect(onCreate).toHaveBeenCalledWith({
        content: "Observación",
        category_ids: [1, 2],
        category_signals: { 1: "difficulty", 2: "no_change" },
      })
    })

    it("warns that «Sin cambio observado» won't count toward trends in that category", async () => {
      render(<CommentsPanel comments={[]} onCreate={vi.fn()} categories={signalCategories} />)
      await pickCategories(["Social", "Emocional"])

      await chooseSignal("Emocional", "Sin cambio observado")
      expect(
        screen.getByText("Esta observación no va a contar para posibles tendencias en Emocional."),
      ).toBeInTheDocument()
      expect(screen.queryByText(/posibles tendencias en Social/)).not.toBeInTheDocument()

      await chooseSignal("Emocional", "Señal de mejora")
      expect(screen.queryByText(/no va a contar para posibles tendencias/)).not.toBeInTheDocument()
    })

    it("sends no signals for an observation without category, and drops those of unmarked categories", async () => {
      const onCreate = vi.fn().mockResolvedValue(undefined)
      render(<CommentsPanel comments={[]} onCreate={onCreate} categories={signalCategories} />)

      await userEvent.type(screen.getByLabelText(/nuevo comentario/i), "Con y sin")
      await pickCategories(["Social", "Académico"])
      await chooseSignal("Social", "Señal de mejora")
      await chooseSignal("Académico", "Señal de dificultad")
      // Unmark Académico: its signal is not sent.
      await pickCategories(["Académico"])
      await userEvent.click(screen.getByRole("button", { name: /comentar/i }))
      expect(onCreate).toHaveBeenLastCalledWith({
        content: "Con y sin",
        category_ids: [1],
        category_signals: { 1: "improvement" },
      })

      // After the reset, an observation without category sends neither field.
      await userEvent.type(screen.getByLabelText(/nuevo comentario/i), "Sin categoría")
      await userEvent.click(screen.getByRole("button", { name: /comentar/i }))
      const last = onCreate.mock.calls.at(-1)?.[0]
      expect(last).not.toHaveProperty("category_ids")
      expect(last).not.toHaveProperty("category_signals")
    })

    it("shows the signal next to each category of a listed observation", () => {
      render(
        <CommentsPanel
          comments={[{ ...sampleComment, categories: [{ id: 1, name: "Social", signal: "difficulty" }] }]}
          onCreate={vi.fn()}
          canComment={false}
        />,
      )
      expect(screen.getByText("Social · Señal de dificultad")).toBeInTheDocument()
    })
  })
})
