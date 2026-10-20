import { useState } from "react"
import { Button } from "@/components/ui/button"
import { roleLabels, type Role, type StaffMember } from "@/types"

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

/** The roles each scope reaches — where «Elegir personas» can pick from. */
export const COMMENT_SCOPE_ROLES: Record<CommentScopeOption, Role[]> = {
  everyone: ["teacher", "psychopedagogue", "director"],
  direction_and_psychopedagogy: ["director", "psychopedagogue"],
  psychopedagogue: ["psychopedagogue"],
  author_only: [],
}

/** Per role, the specific people picked; a role left out keeps its whole audience. */
export type PickedPeople = Partial<Record<Role, number[]>>

export interface CommentScopeSelectorProps {
  value: CommentScopeOption
  onChange: (value: CommentScopeOption) => void
  /**
   * People who see the student. When given, each option (but «Solo quien
   * escribe») offers «Elegir personas» among those of its roles.
   */
  people?: StaffMember[]
  pickedPeople?: PickedPeople
  onPickedPeopleChange?: (picked: PickedPeople) => void
}

/**
 * "Visible para" as a radio group so each option can carry its explanation
 * right under it (a dropdown would hide it until opened).
 */
export function CommentScopeSelector({
  value,
  onChange,
  people,
  pickedPeople = {},
  onPickedPeopleChange,
}: CommentScopeSelectorProps) {
  const [pickerOpen, setPickerOpen] = useState(false)

  function choose(option: CommentScopeOption) {
    if (option === value) return
    onChange(option)
    // Picks belong to one option's roles: start over on a new option.
    setPickerOpen(false)
    onPickedPeopleChange?.({})
  }

  function toggle(role: Role, id: number) {
    const current = pickedPeople[role] ?? []
    const next = current.includes(id) ? current.filter((value) => value !== id) : [...current, id]
    onPickedPeopleChange?.({ ...pickedPeople, [role]: next })
  }

  return (
    <fieldset className="grid gap-2">
      <legend className="mb-1.5 text-sm font-medium">Visible para</legend>
      {COMMENT_SCOPE_OPTIONS.map((option) => {
        const id = `comment-scope-${option}`
        return (
          <div key={option} className="grid gap-1">
            <label
              htmlFor={id}
              className="flex cursor-pointer items-start gap-2 rounded-md border p-2 text-sm has-[:checked]:border-primary"
            >
              <input
                id={id}
                type="radio"
                name="comment-scope"
                value={option}
                checked={value === option}
                onChange={() => choose(option)}
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
            {value === option && people && COMMENT_SCOPE_ROLES[option].length > 0 && (
              <div className="pl-7">
                <PeoplePicker
                  roles={COMMENT_SCOPE_ROLES[option]}
                  people={people}
                  picked={pickedPeople}
                  open={pickerOpen}
                  onOpenChange={setPickerOpen}
                  onToggle={toggle}
                />
              </div>
            )}
          </div>
        )
      })}
    </fieldset>
  )
}

interface PeoplePickerProps {
  roles: Role[]
  people: StaffMember[]
  picked: PickedPeople
  open: boolean
  onOpenChange: (open: boolean) => void
  onToggle: (role: Role, id: number) => void
}

/**
 * «Elegir personas» (ClickUp 86e3qkwnv): within each role of the chosen
 * option, narrow the audience to specific people. Nobody picked in a role
 * means the whole role. The server re-checks every pick.
 */
function PeoplePicker({ roles, people, picked, open, onOpenChange, onToggle }: PeoplePickerProps) {
  if (!open) {
    return (
      <div>
        <Button
          type="button"
          size="sm"
          variant="link"
          className="h-auto p-0"
          onClick={() => onOpenChange(true)}
        >
          Elegir personas
        </Button>
      </div>
    )
  }

  return (
    <div className="grid gap-2" role="group" aria-label="Elegir personas">
      <p className="text-xs text-muted-foreground">
        En cada rol, si no elegís a nadie la ven todos los de ese rol.
      </p>
      {roles.map((role) => {
        const ofRole = people.filter((person) => person.role === role)
        return (
          <div key={role} className="grid gap-1">
            <p className="text-xs font-medium">{roleLabels[role]}</p>
            {ofRole.length === 0 ? (
              <p className="text-xs text-muted-foreground">No hay personas de este rol con acceso.</p>
            ) : (
              ofRole.map((person) => (
                <label key={person.id} className="flex items-center gap-2 text-sm font-normal">
                  <input
                    type="checkbox"
                    checked={(picked[role] ?? []).includes(person.id)}
                    onChange={() => onToggle(role, person.id)}
                  />
                  {person.name}
                </label>
              ))
            )}
          </div>
        )
      })}
    </div>
  )
}
