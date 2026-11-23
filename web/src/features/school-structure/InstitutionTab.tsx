import { useEffect, useState } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { SectionCard } from "@/components/ui/section-card"
import {
  createMeetingSheetType,
  fetchMeetingSheetTypes,
  renameMeetingSheetType,
} from "@/features/psychopedagogy-space/psychopedagogySpaceApi"
import type { MeetingSheetType } from "@/types"

/**
 * «Institución» of Colegio y estructura (screen 23). For now only the meeting
 * sheet types of the «Espacio de psicopedagogía» (P23-DEC-61, P10-DEC-01):
 * Dirección renames and adds them once for the whole school, so nobody
 * invents their own per sheet. There is no delete: past sheets keep their
 * type. The read-only «Régimen ANEP por nivel» (SchoolRegimeCard) sits above
 * it in the same tab. Datos del colegio and membrete come later.
 */
export function InstitutionTab() {
  const [types, setTypes] = useState<MeetingSheetType[] | null>(null)
  const [names, setNames] = useState<Record<number, string>>({})
  const [newName, setNewName] = useState("")
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  function show(list: MeetingSheetType[]) {
    setTypes(list)
    setNames(Object.fromEntries(list.map((type) => [type.id, type.name])))
  }

  useEffect(() => {
    fetchMeetingSheetTypes().then(show, () => setError("No pudimos cargar los tipos de planilla."))
  }, [])

  async function rename(type: MeetingSheetType) {
    const name = (names[type.id] ?? "").trim()
    setError(null)
    setMessage(null)
    if (!name || name === type.name) return
    try {
      const saved = await renameMeetingSheetType(type.id, name)
      show((types ?? []).map((item) => (item.id === saved.id ? saved : item)))
      setMessage("Tipo renombrado.")
    } catch {
      setError("No pudimos renombrarlo. ¿Ya existe un tipo con ese nombre?")
    }
  }

  async function add() {
    const name = newName.trim()
    setError(null)
    setMessage(null)
    if (!name) return
    try {
      const saved = await createMeetingSheetType(name)
      show([...(types ?? []), saved])
      setNewName("")
      setMessage("Tipo agregado.")
    } catch {
      setError("No pudimos agregarlo. ¿Ya existe un tipo con ese nombre?")
    }
  }

  return (
    <SectionCard title="Tipos de planilla">
      <p className="mb-4 text-sm text-muted-foreground">
        Los usa el Espacio de psicopedagogía para cada reunión. Se definen una vez para todo el colegio: si la
        misma reunión se llamara distinto cada vez, no se podría cruzar nada después.
      </p>
      {types === null && !error && <p className="text-sm text-muted-foreground">Cargando…</p>}
      <div className="grid gap-3">
        {types?.map((type, index) => (
          <div key={type.id} className="flex flex-wrap items-end gap-2">
            <div className="grid flex-1 gap-1">
              <Label htmlFor={`sheet-type-${type.id}`}>Tipo {index + 1}</Label>
              <Input
                id={`sheet-type-${type.id}`}
                value={names[type.id] ?? ""}
                maxLength={80}
                onChange={(e) => setNames({ ...names, [type.id]: e.target.value })}
              />
            </div>
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={!(names[type.id] ?? "").trim() || names[type.id]?.trim() === type.name}
              onClick={() => void rename(type)}
            >
              Renombrar
            </Button>
          </div>
        ))}
        <div className="flex flex-wrap items-end gap-2">
          <div className="grid flex-1 gap-1">
            <Label htmlFor="new-sheet-type">Tipo nuevo</Label>
            <Input id="new-sheet-type" value={newName} maxLength={80} onChange={(e) => setNewName(e.target.value)} />
          </div>
          <Button type="button" size="sm" disabled={!newName.trim()} onClick={() => void add()}>
            Agregar tipo
          </Button>
        </div>
        {message && <p className="text-sm text-muted-foreground">{message}</p>}
        {error && <p className="text-sm text-destructive">{error}</p>}
      </div>
    </SectionCard>
  )
}
