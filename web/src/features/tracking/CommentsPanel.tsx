import { useState } from "react"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { Select } from "@/components/ui/select"
import { Textarea } from "@/components/ui/textarea"
import { formatShortDate } from "@/lib/utils"
import { commentToneLabels, roleLabels, type Comment, type CommentTone, type Role } from "@/types"
import type { CommentInput } from "./trackingApi"

const TONE_OPTIONS: CommentTone[] = ["positive", "neutral", "concerning"]
const ROLE_OPTIONS: Role[] = ["teacher", "psychopedagogue", "director"]

const toneBadgeClass: Record<CommentTone, string> = {
  positive: "bg-green-100 text-green-800",
  neutral: "bg-muted text-muted-foreground",
  concerning: "bg-red-100 text-red-800",
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
  const [roles, setRoles] = useState<Role[]>([])
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  function toggleRole(role: Role) {
    setRoles((prev) => (prev.includes(role) ? prev.filter((r) => r !== role) : [...prev, role]))
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault()
    setError(null)

    const trimmed = content.trim()
    if (!trimmed) {
      setError("Escribí un comentario.")
      return
    }

    // visible_to rule (docs/prompts/08 §3): when no role is selected the
    // comment is visible to everyone who can see the record, so we OMIT the
    // field entirely. Sending [] would hide it from everybody, the author
    // included — so we never build an empty array here.
    const input: CommentInput = {
      content: trimmed,
      tone: tone === "" ? null : tone,
      ...(roles.length > 0 ? { visible_to: roles } : {}),
    }

    setSubmitting(true)
    try {
      await onCreate(input)
      setContent("")
      setTone("")
      setRoles([])
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

          <fieldset className="grid gap-1.5">
            <legend className="text-sm font-medium">Visible para</legend>
            <p className="text-xs text-muted-foreground">
              Si no seleccionás ningún rol, el comentario es visible para todos los que pueden ver
              este registro.
            </p>
            <div className="flex flex-wrap gap-3">
              {ROLE_OPTIONS.map((role) => (
                <label key={role} className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={roles.includes(role)}
                    onChange={() => toggleRole(role)}
                  />
                  {roleLabels[role]}
                </label>
              ))}
            </div>
          </fieldset>

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
                <span className="text-xs text-muted-foreground">
                  {formatShortDate(comment.created_at)}
                </span>
              </div>
              <p className="mt-1 text-sm whitespace-pre-wrap">{comment.content}</p>
              {comment.visible_to && comment.visible_to.length > 0 && (
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
