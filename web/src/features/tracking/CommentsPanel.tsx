import { useState, type ReactNode } from "react"
import { MessageSquare } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { EmptyState } from "@/components/ui/empty-state"
import { Label } from "@/components/ui/label"
import { SectionCard } from "@/components/ui/section-card"
import { MultiSelect } from "@/components/ui/multi-select"
import { SingleSelect } from "@/components/ui/single-select"
import { Textarea } from "@/components/ui/textarea"
import { formatShortDate } from "@/lib/utils"
import { roleLabels, type Comment, type CommentCategory, type StaffMember } from "@/types"
import type { CommentInput } from "./trackingApi"
import { CommentScopeSelector, type CommentScopeOption, type PickedPeople } from "./CommentScopeSelector"

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
    case "direction_and_psychopedagogy":
      return { visible_to: ["director", "psychopedagogue"] }
    case "psychopedagogue":
      return { visible_to: ["psychopedagogue"] }
    case "author_only":
      return { author_only: true }
  }
}

/** Only the roles where someone was picked; nothing at all for «Solo quien escribe». */
function buildPeopleField(
  option: CommentScopeOption,
  picked: PickedPeople,
): Pick<CommentInput, "visible_to_users"> {
  if (option === "author_only") return {}
  const entries = Object.entries(picked).filter(([, ids]) => ids && ids.length > 0)
  return entries.length > 0 ? { visible_to_users: Object.fromEntries(entries) } : {}
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
  /** The school's categories for the optional "De qué trata" picker. */
  categories?: CommentCategory[]
  /** Per-comment actions, e.g. psychopedagogy's «Compartir con» button. */
  renderCommentActions?: (comment: Comment) => ReactNode
  /** Rendered above the comment list, e.g. the extracts shared with the viewer. */
  beforeList?: ReactNode
  /**
   * Observations bank mode (student profile): shows who wrote each comment and
   * adds author/category filters. Off for group comments.
   */
  showAuthors?: boolean
  /** Used to label the viewer's own comments "Vos". */
  currentUserId?: number
  /** Only psychopedagogy/direction see the total; teachers never do. */
  showCount?: boolean
  /**
   * People who see the student: enables «Elegir personas» in the scope
   * selector (student observations only).
   */
  scopeCandidates?: StaffMember[]
}

const ALL = "all"

export function CommentsPanel({
  comments,
  onCreate,
  canComment = true,
  title = "Comentarios",
  categories = [],
  renderCommentActions,
  beforeList,
  showAuthors = false,
  currentUserId,
  showCount = false,
  scopeCandidates,
}: CommentsPanelProps) {
  const [authorFilter, setAuthorFilter] = useState<string>(ALL)
  const [categoryFilter, setCategoryFilter] = useState<string>(ALL)
  const [content, setContent] = useState("")
  const [categoryIds, setCategoryIds] = useState<number[]>([])
  const [scope, setScope] = useState<CommentScopeOption>("everyone")
  const [pickedPeople, setPickedPeople] = useState<PickedPeople>({})
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
      ...(categoryIds.length > 0 ? { category_ids: categoryIds } : {}),
      ...buildScopeFields(scope),
      ...buildPeopleField(scope, pickedPeople),
    }

    setSubmitting(true)
    try {
      await onCreate(input)
      setContent("")
      setCategoryIds([])
      setScope("everyone")
      setPickedPeople({})
    } catch {
      setError("No pudimos guardar el comentario.")
    } finally {
      setSubmitting(false)
    }
  }

  // Filter labels match the list ("Vos" for the viewer) and carry the role so
  // two colleagues with the same name can be told apart.
  const authors = new Map<number, string>()
  for (const { author } of comments) {
    if (!author) continue
    const label =
      author.id === currentUserId
        ? "Vos"
        : author.role
          ? `${author.name} · ${roleLabels[author.role]}`
          : author.name
    authors.set(author.id, label)
  }
  const visibleComments = comments.filter((comment) => {
    if (authorFilter !== ALL && String(comment.author_id) !== authorFilter) return false
    if (categoryFilter === "none") return (comment.categories ?? []).length === 0
    if (categoryFilter !== ALL) {
      return (comment.categories ?? []).some((category) => String(category.id) === categoryFilter)
    }
    return true
  })

  return (
    <SectionCard title={showCount ? `${title} (${comments.length})` : title}>
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

          {categories.length > 0 && (
            <div className="grid gap-1.5">
              <Label htmlFor="comment-categories">De qué trata (opcional)</Label>
              <MultiSelect
                id="comment-categories"
                options={categories.map((category) => ({ id: category.id, label: category.name }))}
                selected={categoryIds}
                onChange={setCategoryIds}
                placeholder="Sin categoría"
              />
            </div>
          )}

          <CommentScopeSelector
            value={scope}
            onChange={setScope}
            people={scopeCandidates?.filter((person) => person.id !== currentUserId)}
            pickedPeople={pickedPeople}
            onPickedPeopleChange={setPickedPeople}
          />

          {error && <p className="text-sm text-destructive">{error}</p>}

          <div>
            <Button type="submit" disabled={submitting}>
              {submitting ? "Guardando…" : "Comentar"}
            </Button>
          </div>
        </form>
      )}

      {beforeList}
      {showAuthors && comments.length > 0 && (
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="grid gap-1.5">
            <Label htmlFor="comment-filter-author">Filtrar por autor</Label>
            <SingleSelect
              id="comment-filter-author"
              value={authorFilter}
              onChange={setAuthorFilter}
              options={[
                { value: ALL, label: "Todos" },
                ...[...authors].map(([id, label]) => ({ value: String(id), label })),
              ]}
            />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="comment-filter-category">Filtrar por categoría</Label>
            <SingleSelect
              id="comment-filter-category"
              value={categoryFilter}
              onChange={setCategoryFilter}
              options={[
                { value: ALL, label: "Todas" },
                ...categories.map((category) => ({ value: String(category.id), label: category.name })),
                { value: "none", label: "Sin categoría" },
              ]}
            />
          </div>
        </div>
      )}

      {comments.length === 0 ? (
        <EmptyState icon={MessageSquare} message="Todavía no hay comentarios." />
      ) : visibleComments.length === 0 ? (
        <EmptyState icon={MessageSquare} message="Ninguna observación coincide con el filtro." />
      ) : (
        <ul className="grid gap-3">
          {visibleComments.map((comment) => (
            <li key={comment.id} className="rounded-md border p-3">
              <div className="flex flex-wrap items-center gap-2">
                {showAuthors && comment.author && (
                  <span className="text-sm font-medium">
                    {comment.author_id === currentUserId ? "Vos" : comment.author.name}
                    {comment.author.role && (
                      <span className="font-normal text-muted-foreground">
                        {" · "}
                        {roleLabels[comment.author.role]}
                      </span>
                    )}
                  </span>
                )}
                {(comment.categories ?? []).map((category) => (
                  <Badge key={category.id} tone="neutral">
                    {category.name}
                  </Badge>
                ))}
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
              {renderCommentActions?.(comment)}
            </li>
          ))}
        </ul>
      )}
      </div>
    </SectionCard>
  )
}
