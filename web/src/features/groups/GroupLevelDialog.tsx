import { useState } from "react"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import type { EducationLevelOption, Group } from "@/types"
import { GroupLevelFields, type GroupLevelValue } from "./GroupLevelFields"
import * as groupsApi from "./groupsApi"

/**
 * Choose or change only the level and grade of a group — for psychopedagogy
 * when direction allowed it (direction also has the full group form). The
 * change applies forward: screenings already applied keep their level.
 */
export function GroupLevelDialog({
  group,
  levels,
  onClose,
  onSaved,
}: {
  group: Group
  levels: EducationLevelOption[]
  onClose: () => void
  onSaved: () => void
}) {
  const [value, setValue] = useState<GroupLevelValue>({
    levelId: group.education_level ? String(group.education_level.id) : "",
    gradeId: group.school_grade ? String(group.school_grade.id) : "",
  })
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  async function save() {
    if (!value.levelId) {
      setError("Elegí el nivel")
      return
    }
    setError(null)
    setSaving(true)
    try {
      await groupsApi.updateGroupLevel(group.id, {
        education_level_id: Number(value.levelId),
        school_grade_id: value.gradeId ? Number(value.gradeId) : null,
      })
      onSaved()
    } catch {
      setError("No pudimos guardar el nivel.")
      setSaving(false)
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>Nivel de {group.name}</DialogTitle>
        </DialogHeader>
        <div className="grid gap-4">
          <GroupLevelFields levels={levels} value={value} onChange={setValue} />
          <p className="text-xs text-muted-foreground">
            El cambio vale de acá en adelante: los screenings ya aplicados conservan el nivel que tenía
            el grupo cuando se aplicaron.
          </p>
          {error && (
            <p role="alert" className="text-sm text-destructive">
              {error}
            </p>
          )}
          <div className="flex gap-2">
            <Button type="button" onClick={save} disabled={saving}>
              {saving ? "Guardando…" : "Guardar"}
            </Button>
            <Button type="button" variant="outline" onClick={onClose}>
              Cancelar
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  )
}
