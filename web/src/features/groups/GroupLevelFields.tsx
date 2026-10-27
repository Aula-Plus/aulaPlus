import { Label } from "@/components/ui/label"
import type { EducationLevelOption } from "@/types"

const selectClass = "h-9 rounded-md border bg-background px-3 text-sm"

export interface GroupLevelValue {
  /** "" while no level is chosen ("Sin nivel"). */
  levelId: string
  /** "" = no grade (optional). */
  gradeId: string
}

/**
 * Grade (optional) and level (mandatory) of a group. Choosing a grade fills in
 * the level it suggests, which can still be changed: the two are stored apart
 * and the grade never forces the level.
 */
export function GroupLevelFields({
  levels,
  value,
  onChange,
  levelError,
}: {
  levels: EducationLevelOption[]
  value: GroupLevelValue
  onChange: (value: GroupLevelValue) => void
  levelError?: string
}) {
  function handleGrade(gradeId: string) {
    const suggested = levels.find((level) => level.grades.some((grade) => String(grade.id) === gradeId))
    onChange({ gradeId, levelId: suggested ? String(suggested.id) : value.levelId })
  }

  return (
    <>
      <div className="grid gap-2">
        <Label htmlFor="school_grade_id">Grado (opcional)</Label>
        <select
          id="school_grade_id"
          className={selectClass}
          value={value.gradeId}
          onChange={(event) => handleGrade(event.target.value)}
        >
          <option value="">Sin grado (por ejemplo, un taller o un grupo mixto)</option>
          {levels.map((level) => (
            <optgroup key={level.id} label={level.label}>
              {level.grades.map((grade) => (
                <option key={grade.id} value={grade.id}>
                  {grade.label}
                </option>
              ))}
            </optgroup>
          ))}
        </select>
      </div>
      <div className="grid gap-2">
        <Label htmlFor="education_level_id">Nivel</Label>
        <select
          id="education_level_id"
          className={selectClass}
          value={value.levelId}
          onChange={(event) => onChange({ ...value, levelId: event.target.value })}
          aria-describedby="education_level_hint"
        >
          <option value="" disabled>
            Elegí el nivel
          </option>
          {levels.map((level) => (
            <option key={level.id} value={level.id}>
              {level.label}
            </option>
          ))}
        </select>
        <p id="education_level_hint" className="text-xs text-muted-foreground">
          Al elegir el grado se completa el nivel; si no encaja, podés cambiarlo.
        </p>
        {levelError && <p className="text-sm text-destructive">{levelError}</p>}
      </div>
    </>
  )
}
