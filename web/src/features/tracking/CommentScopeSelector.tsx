/**
 * The four scopes a comment can be written with (ClickUp 86e3qkwnv). Purely a
 * UI state — never sent to the backend as-is; `buildScopeFields` in
 * CommentsPanel translates it at submit time. The server enforces who reads
 * what (`Comment::scopeVisibleToRole`); this selector is UX only.
 */
export type CommentScopeOption =
  | "everyone"
  | "direction_and_psychopedagogy"
  | "psychopedagogue"
  | "author_only"

const COMMENT_SCOPE_OPTIONS: CommentScopeOption[] = [
  "everyone",
  "direction_and_psychopedagogy",
  "psychopedagogue",
  "author_only",
]

const COMMENT_SCOPE_LABELS: Record<CommentScopeOption, string> = {
  everyone: "Todos los que ven al alumno",
  direction_and_psychopedagogy: "Dirección y Psicopedagogía",
  psychopedagogue: "Solo Psicopedagogía",
  author_only: "Solo quien escribe",
}

const COMMENT_SCOPE_EXPLANATIONS: Record<CommentScopeOption, string> = {
  everyone: "Docentes con acceso al alumno, Psicopedagogía y Dirección.",
  direction_and_psychopedagogy: "Dirección y Psicopedagogía. Los docentes no la ven.",
  psychopedagogue: "Solo Psicopedagogía. Ni Dirección ni los docentes la ven.",
  author_only: "Nadie más la lee ni suma a la tendencia de otros.",
}

export interface CommentScopeSelectorProps {
  value: CommentScopeOption
  onChange: (value: CommentScopeOption) => void
}

/**
 * "Visible para" as a radio group so each option can carry its explanation
 * right under it (a dropdown would hide it until opened).
 */
export function CommentScopeSelector({ value, onChange }: CommentScopeSelectorProps) {
  return (
    <fieldset className="grid gap-2">
      <legend className="mb-1.5 text-sm font-medium">Visible para</legend>
      {COMMENT_SCOPE_OPTIONS.map((option) => {
        const id = `comment-scope-${option}`
        return (
          <label
            key={option}
            htmlFor={id}
            className="flex cursor-pointer items-start gap-2 rounded-md border p-2 text-sm has-[:checked]:border-primary"
          >
            <input
              id={id}
              type="radio"
              name="comment-scope"
              value={option}
              checked={value === option}
              onChange={() => onChange(option)}
              aria-describedby={`${id}-explanation`}
              className="mt-0.5"
            />
            <span className="grid gap-0.5">
              <span className="font-medium">{COMMENT_SCOPE_LABELS[option]}</span>
              <span id={`${id}-explanation`} className="text-xs text-muted-foreground">
                {COMMENT_SCOPE_EXPLANATIONS[option]}
              </span>
            </span>
          </label>
        )
      })}
    </fieldset>
  )
}
