import { useState } from "react"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { Select } from "@/components/ui/select"
import { Textarea } from "@/components/ui/textarea"
import { formatShortDate } from "@/lib/utils"
import { commentToneLabels, roleLabels, type Comment, type CommentTone } from "@/types"
import type { CommentInput } from "./trackingApi"

const TONE_OPTIONS: CommentTone[] = ["positive", "neutral", "concerning"]

const toneBadgeClass: Record<CommentTone, string> = {
  positive: "bg-green-100 text-green-800",
  neutral: "bg-muted text-muted-foreground",
  concerning: "bg-red-100 text-red-800",
}

/**
 * The four preset scopes the product thinks a comment's visibility in
 * (docs/prompts/19-comentarios-alcance.md §3). Purely a UI state — never sent
 * to the backend as-is; {@link buildScopeFields} translates it at submit time.
 * The backend's fourth scope, `author_only`, is orthogonal to `visible_to` and
 * mutually exclusive with it, so free role combinations are not offered.
 */
type CommentScopeOption = "everyone" | "director" | "psychopedagogue" | "author_only"

const COMMENT_SCOPE_OPTIONS: CommentScopeOption[] = [
  "everyone",
  "director",
  "psychopedagogue",
  "author_only",
]

const COMMENT_SCOPE_LABELS: Record<CommentScopeOption, string> = {
  everyone: "Todos los que ven este registro",
  director: "Solo dirección",
  psychopedagogue: "Solo psicopedagogía",
  author_only: "Solo quien escribe",
}

/**
 * Translate the UI-only scope choice into the real payload fields. `everyone`
 * omits both, keeping the Session 8 rule that "nothing selected" means visible
 * to everyone (never an empty array); `author_only` sends only `author_only`,
 * never alongside `visible_to`.
 */
function buildScopeFields(
  option: CommentScopeOption,
): Pick<CommentInput, "visible_to" | "author_only"> {
  switch (option) {
    case "everyone":
      return {}
    case "director":
      return { visible_to: ["director"] }
    case "psychopedagogue":
      return { visible_to: ["psychopedagogue"] }
    case "author_only":
      return { author_only: true }
  }
}

export interface CommentsPanelProps {
  comments: Comment[]
  onCreate: (input: CommentInput) => Promise<void>
  /**
   * Whether to render the "new comment" form. Defaults to true — the server is
   * the real authorization boundary, so we only hide the form when the caller
   * knows the action would be pointless. Record-level checks (a teacher may
   * only comment on students they teach) live on the server.
   */
  canComment?: boolean
  /** Heading for the panel, e.g. "Comentarios del alumno". */
  title?: string
}

export function CommentsPanel({
  comments,
  onCreate,
  canComment = true,
  title = "Comentarios",
}: CommentsPanelProps) {
  const [content, setContent] = useState("")
  const [tone, setTone] = useState<CommentTone | "">("")
  const [scope, setScope] = useState<CommentScopeOption>("everyone")
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault()
    setError(null)

    const trimmed = content.trim()
    if (!trimmed) {
      setError("Escribí un comentario.")
      return
    }

    // The scope selector maps to the payload here — `visible_to` and
    // `author_only` are never sent together, and "everyone" omits both
    // (docs/prompts/19-comentarios-alcance.md §3, keeping the Session 8 rule
    // that "nothing selected" means visible to everyone, never `[]`).
    const input: CommentInput = {
      content: trimmed,
      tone: tone === "" ? null : tone,
      ...buildScopeFields(scope),
    }

    setSubmitting(true)
    try {
      await onCreate(input)
      setContent("")
      setTone("")
      setScope("everyone")
    } catch {
      setError("No pudimos guardar el comentario.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <section className="grid gap-4">
      <h2 className="text-lg font-semibold">{title}</h2>

      {canComment && (
        <form onSubmit={handleSubmit} className="grid gap-3 rounded-md border p-4">
          <div className="grid gap-1.5">
            <Label htmlFor="comment-content">Nuevo comentario</Label>
            <Textarea
              id="comment-content"
              value={content}
              onChange={(event) => setContent(event.target.value)}
              placeholder="Escribí una observación…"
            />
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="comment-tone">Tono</Label>
            <Select
              id="comment-tone"
              value={tone}
              onChange={(event) => setTone(event.target.value as CommentTone | "")}
            >
              <option value="">Sin especificar</option>
              {TONE_OPTIONS.map((option) => (
                <option key={option} value={option}>
                  {commentToneLabels[option]}
                </option>
              ))}
            </Select>
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="comment-scope">Visible para</Label>
            <Select
              id="comment-scope"
              value={scope}
              onChange={(event) => setScope(event.target.value as CommentScopeOption)}
            >
              {COMMENT_SCOPE_OPTIONS.map((option) => (
                <option key={option} value={option}>
                  {COMMENT_SCOPE_LABELS[option]}
                </option>
              ))}
            </Select>
          </div>

          {error && <p className="text-sm text-destructive">{error}</p>}

          <div>
            <Button type="submit" disabled={submitting}>
              {submitting ? "Guardando…" : "Comentar"}
            </Button>
          </div>
        </form>
      )}

      {comments.length === 0 ? (
        <p className="text-muted-foreground">Todavía no hay comentarios.</p>
      ) : (
        <ul className="grid gap-3">
          {comments.map((comment) => (
            <li key={comment.id} className="rounded-md border p-3">
              <div className="flex items-center gap-2">
                {comment.tone && (
                  <span
                    className={`rounded px-2 py-0.5 text-xs font-medium ${toneBadgeClass[comment.tone]}`}
                  >
                    {commentToneLabels[comment.tone]}
                  </span>
                )}
                {comment.author_only && (
                  <span className="rounded bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                    Privado
                  </span>
                )}
                <span className="text-xs text-muted-foreground">
                  {formatShortDate(comment.created_at)}
                </span>
              </div>
              <p className="mt-1 text-sm whitespace-pre-wrap">{comment.content}</p>
              {/*
                `author_only` and `visible_to` are mutually exclusive scopes;
                showing both lines would be contradictory. The "Privado" badge
                above already conveys the author-only scope.
              */}
              {!comment.author_only && comment.visible_to && comment.visible_to.length > 0 && (
                <p className="mt-1 text-xs text-muted-foreground">
                  Visible para: {comment.visible_to.map((role) => roleLabels[role]).join(", ")}
                </p>
              )}
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
