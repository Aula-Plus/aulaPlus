import { useState } from "react"
import { MessageSquare } from "lucide-react"
import { Badge, type BadgeTone } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { EmptyState } from "@/components/ui/empty-state"
import { Label } from "@/components/ui/label"
import { SectionCard } from "@/components/ui/section-card"
import { SingleSelect } from "@/components/ui/single-select"
import { Textarea } from "@/components/ui/textarea"
import { formatShortDate } from "@/lib/utils"
import { commentToneLabels, roleLabels, type Comment, type CommentTone } from "@/types"
import type { CommentInput } from "./trackingApi"

const TONE_OPTIONS: CommentTone[] = ["positive", "neutral", "concerning"]

const toneBadge: Record<CommentTone, BadgeTone> = {
  positive: "success",
  neutral: "neutral",
  concerning: "danger",
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
    <SectionCard title={title}>
      <div className="grid gap-4">
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
            <SingleSelect
              id="comment-tone"
              value={tone}
              onChange={(value) => setTone(value as CommentTone | "")}
              options={[
                { value: "", label: "Sin especificar" },
                ...TONE_OPTIONS.map((option) => ({
                  value: option,
                  label: commentToneLabels[option],
                })),
              ]}
            />
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="comment-scope">Visible para</Label>
            <SingleSelect
              id="comment-scope"
              value={scope}
              onChange={(value) => setScope(value as CommentScopeOption)}
              options={COMMENT_SCOPE_OPTIONS.map((option) => ({
                value: option,
                label: COMMENT_SCOPE_LABELS[option],
              }))}
            />
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
        <EmptyState icon={MessageSquare} message="Todavía no hay comentarios." />
      ) : (
        <ul className="grid gap-3">
          {comments.map((comment) => (
            <li key={comment.id} className="rounded-md border p-3">
              <div className="flex items-center gap-2">
                {comment.tone && (
                  <Badge tone={toneBadge[comment.tone]}>{commentToneLabels[comment.tone]}</Badge>
                )}
                {comment.author_only && <Badge tone="neutral">Privado</Badge>}
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
      </div>
    </SectionCard>
  )
}
