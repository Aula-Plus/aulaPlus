import { useMemo, useState } from "react"
import { Plus, X } from "lucide-react"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { addDays, toDay } from "@/features/calendar/calendarDates"
import { getCurrentSchoolYear } from "@/lib/schoolYear"
import type { MeetingSheet, ObservationScope, PsychopedagogyMeeting } from "@/types"
import { saveSheet, sheetRejection } from "./psychopedagogySpaceApi"
import type { SheetFormOptions } from "./useSheetFormOptions"
import { OBSERVATION_SCOPES, observationScopeLabels } from "./spaceText"

const selectClass =
  "h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"

interface ActionRow {
  description: string
  owner_id: string
  due_on: string
}

interface FragmentDraft {
  content: string
  scope: ObservationScope
}

/**
 * «Nueva planilla» / «Editar antes» (P10-PLA-05): type from the school's fixed
 * list, date, group or individual, who was there, which students were
 * discussed, what was discussed, the actions (optional, each with owner and
 * deadline) and how the text is split — one fragment per student, each with
 * its scope. Saving keeps it a draft: nothing reaches a file until
 * «Confirmar el reparto». The split is written by hand for now; the AI
 * proposal is pending a product answer.
 */
export function SheetEditorDialog({
  options,
  sheet,
  fromMeeting,
  onClose,
  onSaved,
}: {
  options: SheetFormOptions
  sheet?: MeetingSheet
  fromMeeting?: PsychopedagogyMeeting
  onClose: () => void
  onSaved: (sheet: MeetingSheet) => void
}) {
  const year = getCurrentSchoolYear()
  const [typeId, setTypeId] = useState(
    String(sheet?.sheet_type.id ?? fromMeeting?.sheet_type?.id ?? options.types[0]?.id ?? ""),
  )
  const [heldOn, setHeldOn] = useState(sheet?.held_on ?? fromMeeting?.scheduled_on ?? toDay(new Date()))
  const [groupId, setGroupId] = useState(String(sheet?.group?.id ?? fromMeeting?.group?.id ?? ""))
  const [attendees, setAttendees] = useState<number[]>(
    sheet?.attendees.map((person) => person.id) ?? fromMeeting?.attendees.map((person) => person.id) ?? [],
  )
  const [external, setExternal] = useState(sheet?.external_attendees ?? fromMeeting?.external_attendees ?? "")
  const [studentIds, setStudentIds] = useState<number[]>(
    sheet?.students.map((student) => student.id) ?? (fromMeeting?.student ? [fromMeeting.student.id] : []),
  )
  const [content, setContent] = useState(sheet?.content ?? "")
  const [fragments, setFragments] = useState<Record<number, FragmentDraft>>(() =>
    Object.fromEntries(
      (sheet?.fragments ?? []).map((fragment) => [
        fragment.student_id,
        { content: fragment.content, scope: fragment.scope },
      ]),
    ),
  )
  const [actions, setActions] = useState<ActionRow[]>(
    sheet?.actions.map((action) => ({
      description: action.description,
      owner_id: String(action.owner?.id ?? ""),
      due_on: action.due_on,
    })) ?? [],
  )
  const [query, setQuery] = useState("")
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const candidates = useMemo(() => {
    const inGroup = groupId
      ? options.students.filter((student) =>
          student.groups.some((group) => String(group.id) === groupId && group.school_year === year),
        )
      : options.students
    const needle = query.trim().toLowerCase()
    return inGroup.filter(
      (student) => studentIds.includes(student.id) || !needle || student.full_name.toLowerCase().includes(needle),
    )
  }, [groupId, options.students, query, studentIds, year])

  const nameOf = (id: number) =>
    options.students.find((student) => student.id === id)?.full_name ??
    sheet?.students.find((student) => student.id === id)?.name ??
    `Alumno ${id}`

  function toggleStudent(id: number, checked: boolean) {
    setStudentIds((current) => (checked ? [...current, id] : current.filter((value) => value !== id)))
  }

  function fragmentFor(id: number): FragmentDraft {
    return fragments[id] ?? { content: "", scope: "psychopedagogue" }
  }

  async function save() {
    setError(null)
    if (!typeId || studentIds.length === 0 || !content.trim()) {
      setError("Elegí el tipo, al menos un alumno y escribí lo tratado.")
      return
    }
    const filledActions = actions.filter((action) => action.description.trim())
    if (filledActions.some((action) => !action.owner_id || !action.due_on)) {
      setError("Cada acción lleva responsable y plazo.")
      return
    }
    setSaving(true)
    try {
      const saved = await saveSheet(sheet?.id ?? null, {
        meeting_sheet_type_id: Number(typeId),
        psychopedagogy_meeting_id: sheet?.psychopedagogy_meeting_id ?? fromMeeting?.id ?? null,
        held_on: heldOn,
        group_id: groupId ? Number(groupId) : null,
        attendee_ids: attendees,
        external_attendees: external.trim() || null,
        student_ids: studentIds,
        content: content.trim(),
        fragments: studentIds
          .map((id) => ({ student_id: id, ...fragmentFor(id) }))
          .filter((fragment) => fragment.content.trim())
          .map((fragment) => ({ ...fragment, content: fragment.content.trim() })),
        actions: filledActions.map((action) => ({
          description: action.description.trim(),
          owner_id: Number(action.owner_id),
          due_on: action.due_on,
        })),
      })
      onSaved(saved)
    } catch (err) {
      setError(sheetRejection(err)?.message ?? "No pudimos guardar la planilla. Revisá los datos.")
      setSaving(false)
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-3xl">
        <DialogHeader>
          <DialogTitle>{sheet ? "Editar planilla" : "Nueva planilla"}</DialogTitle>
          <DialogDescription>
            Se completa una sola vez por reunión. Nada baja a las fichas hasta que confirmes el reparto.
          </DialogDescription>
        </DialogHeader>
        <div className="grid gap-4">
          <div className="grid gap-4 sm:grid-cols-3">
            <div className="grid gap-2">
              <Label htmlFor="sheet-type">Tipo</Label>
              <select id="sheet-type" className={selectClass} value={typeId} onChange={(e) => setTypeId(e.target.value)}>
                {options.types.map((type) => (
                  <option key={type.id} value={type.id}>
                    {type.name}
                  </option>
                ))}
              </select>
            </div>
            <div className="grid gap-2">
              <Label htmlFor="sheet-date">Fecha</Label>
              <Input id="sheet-date" type="date" value={heldOn} onChange={(e) => setHeldOn(e.target.value)} />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="sheet-group">Grupo</Label>
              <select id="sheet-group" className={selectClass} value={groupId} onChange={(e) => setGroupId(e.target.value)}>
                <option value="">Individual</option>
                {options.groups.map((group) => (
                  <option key={group.id} value={group.id}>
                    {group.name}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <fieldset className="grid gap-2">
            <legend className="mb-2 text-sm font-medium">Quiénes estaban</legend>
            <div className="grid max-h-40 gap-1 overflow-y-auto rounded-md border p-2 sm:grid-cols-2">
              {options.staff.map((person) => (
                <label key={person.id} className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={attendees.includes(person.id)}
                    onChange={(e) =>
                      setAttendees((current) =>
                        e.target.checked ? [...current, person.id] : current.filter((id) => id !== person.id),
                      )
                    }
                  />
                  {person.name}
                </label>
              ))}
            </div>
            <Input
              aria-label="Además estaban"
              placeholder="Además (opcional): familia, equipo externo…"
              value={external}
              onChange={(e) => setExternal(e.target.value)}
            />
          </fieldset>

          <fieldset className="grid gap-2">
            <legend className="mb-2 text-sm font-medium">De qué alumnos se habló</legend>
            <Input aria-label="Buscar alumno" placeholder="Buscar alumno" value={query} onChange={(e) => setQuery(e.target.value)} />
            <div className="grid max-h-40 gap-1 overflow-y-auto rounded-md border p-2 sm:grid-cols-2">
              {candidates.map((student) => (
                <label key={student.id} className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={studentIds.includes(student.id)}
                    onChange={(e) => toggleStudent(student.id, e.target.checked)}
                  />
                  {student.full_name}
                </label>
              ))}
              {candidates.length === 0 && <p className="text-sm text-muted-foreground">Sin alumnos para elegir.</p>}
            </div>
          </fieldset>

          <div className="grid gap-2">
            <Label htmlFor="sheet-content">Lo tratado</Label>
            <Textarea id="sheet-content" rows={4} value={content} onChange={(e) => setContent(e.target.value)} />
          </div>

          <fieldset className="grid gap-3">
            <legend className="mb-1 text-sm font-medium">Cómo se reparte en las fichas</legend>
            <p className="text-sm text-muted-foreground">
              Un fragmento por alumno, con quién lo ve en su ficha. Solo baja a la ficha el fragmento de cada
              alumno, nunca la planilla entera. Un alumno sin fragmento no recibe nada.
            </p>
            {studentIds.length === 0 && <p className="text-sm text-muted-foreground">Elegí primero los alumnos.</p>}
            {studentIds.map((id) => {
              const fragment = fragmentFor(id)
              return (
                <div key={id} className="grid gap-2 rounded-md border p-3">
                  <Label htmlFor={`fragment-${id}`}>Para la ficha de {nameOf(id)}</Label>
                  <Textarea
                    id={`fragment-${id}`}
                    rows={3}
                    value={fragment.content}
                    onChange={(e) => setFragments({ ...fragments, [id]: { ...fragment, content: e.target.value } })}
                  />
                  <Label htmlFor={`fragment-scope-${id}`}>Visible para</Label>
                  <select
                    id={`fragment-scope-${id}`}
                    className={selectClass}
                    value={fragment.scope}
                    onChange={(e) =>
                      setFragments({ ...fragments, [id]: { ...fragment, scope: e.target.value as ObservationScope } })
                    }
                  >
                    {OBSERVATION_SCOPES.map((option) => (
                      <option key={option} value={option}>
                        {observationScopeLabels[option]}
                      </option>
                    ))}
                  </select>
                </div>
              )
            })}
          </fieldset>

          <fieldset className="grid gap-3">
            <legend className="mb-1 text-sm font-medium">Acciones que salieron (opcional)</legend>
            {actions.map((action, index) => (
              <div key={index} className="grid gap-2 rounded-md border p-3 sm:grid-cols-[1fr_12rem_10rem_auto]">
                <Input
                  aria-label={`Qué hay que hacer ${index + 1}`}
                  placeholder="Qué hay que hacer"
                  value={action.description}
                  onChange={(e) =>
                    setActions(actions.map((row, i) => (i === index ? { ...row, description: e.target.value } : row)))
                  }
                />
                <select
                  aria-label={`Responsable ${index + 1}`}
                  className={selectClass}
                  value={action.owner_id}
                  onChange={(e) =>
                    setActions(actions.map((row, i) => (i === index ? { ...row, owner_id: e.target.value } : row)))
                  }
                >
                  <option value="">Responsable</option>
                  {options.staff.map((person) => (
                    <option key={person.id} value={person.id}>
                      {person.name}
                    </option>
                  ))}
                </select>
                <Input
                  aria-label={`Plazo ${index + 1}`}
                  type="date"
                  value={action.due_on}
                  onChange={(e) =>
                    setActions(actions.map((row, i) => (i === index ? { ...row, due_on: e.target.value } : row)))
                  }
                />
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  aria-label={`Quitar acción ${index + 1}`}
                  onClick={() => setActions(actions.filter((_, i) => i !== index))}
                >
                  <X className="size-4" />
                </Button>
              </div>
            ))}
            <div>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() =>
                  setActions([...actions, { description: "", owner_id: "", due_on: addDays(toDay(new Date()), 7) }])
                }
              >
                <Plus className="size-4" /> Agregar acción
              </Button>
            </div>
          </fieldset>
          {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>
            Cancelar
          </Button>
          <Button type="button" onClick={() => void save()} disabled={saving}>
            {saving ? "Guardando…" : "Guardar"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
