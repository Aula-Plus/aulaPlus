import { useEffect, useState, type FormEvent } from "react"
import { Clock } from "lucide-react"
import { Button } from "@/components/ui/button"
import { EmptyState } from "@/components/ui/empty-state"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { SectionCard } from "@/components/ui/section-card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { weekdayLabels, type TeachingAssignment, type TimetableSlot } from "@/types"
import { createTimetableSlot, deleteTimetableSlot, fetchTimetable } from "./calendarApi"
import { calendarErrorMessage } from "./calendarErrors"

const selectClass =
  "h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"

/**
 * «Mis horarios» (ClickUp 1dfd3mc4bgh): the teacher's own weekly timetable —
 * per group-subject they teach, which weekdays, at what time, in which room.
 * Private (nobody else reads it, TimetableSlotPolicy) and only a default: it
 * pre-fills the time and room of a class; it never creates one, and changing
 * it never touches the classes already created.
 */
export function TimetablePanel() {
  const [slots, setSlots] = useState<TimetableSlot[] | null>(null)
  const [assignments, setAssignments] = useState<TeachingAssignment[]>([])
  const [error, setError] = useState<string | null>(null)

  const [assignment, setAssignment] = useState("")
  const [weekday, setWeekday] = useState("1")
  const [time, setTime] = useState("")
  const [room, setRoom] = useState("")
  const [formError, setFormError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  function load() {
    fetchTimetable()
      .then(({ slots, assignments }) => {
        setSlots(slots)
        setAssignments(assignments)
        setAssignment((current) =>
          current || (assignments[0] ? `${assignments[0].group.id}:${assignments[0].subject.id}` : ""),
        )
      })
      .catch(() => setError("No pudimos cargar tus horarios."))
  }

  useEffect(load, [])

  async function onAdd(event: FormEvent) {
    event.preventDefault()
    setFormError(null)
    const [groupId, subjectId] = assignment.split(":").map(Number)
    if (!groupId || !subjectId) return setFormError("Elegí el grupo y la materia.")
    if (!/^\d{2}:\d{2}$/.test(time)) return setFormError("Elegí la hora.")
    setSaving(true)
    try {
      await createTimetableSlot({
        group_id: groupId,
        subject_id: subjectId,
        weekday: Number(weekday),
        start_time: time,
        room: room.trim() || null,
      })
      setTime("")
      load()
    } catch (err) {
      setFormError(calendarErrorMessage(err, "No pudimos guardar el horario."))
    } finally {
      setSaving(false)
    }
  }

  async function onRemove(slot: TimetableSlot) {
    try {
      await deleteTimetableSlot(slot.id)
      load()
    } catch {
      setError("No pudimos quitar el horario.")
    }
  }

  return (
    <div className="grid gap-6">
      <p className="text-sm text-muted-foreground">
        Lo cargás vos y solo lo ves vos. No crea clases: cuando creás o editás una clase de ese grupo y materia,
        la hora y el salón aparecen completados y los podés cambiar. Si lo modificás, las clases ya creadas no
        cambian.
      </p>
      {error && <p className="text-sm text-destructive">{error}</p>}

      <SectionCard title="Agregar un horario" headingLevel={3}>
        {assignments.length === 0 && slots !== null ? (
          <p className="text-sm text-muted-foreground">Todavía no tenés grupos y materias asignados.</p>
        ) : (
          <form onSubmit={onAdd} className="grid gap-4 sm:grid-cols-[2fr_1fr_1fr_1fr_auto] sm:items-end" noValidate>
            <div className="grid gap-2">
              <Label htmlFor="slot-assignment">Grupo y materia</Label>
              <select
                id="slot-assignment"
                className={selectClass}
                value={assignment}
                onChange={(event) => setAssignment(event.target.value)}
              >
                {assignments.map((a) => (
                  <option key={`${a.group.id}:${a.subject.id}`} value={`${a.group.id}:${a.subject.id}`}>
                    {a.subject.name ?? "Materia"} · {a.group.name}
                  </option>
                ))}
              </select>
            </div>
            <div className="grid gap-2">
              <Label htmlFor="slot-weekday">Día</Label>
              <select
                id="slot-weekday"
                className={selectClass}
                value={weekday}
                onChange={(event) => setWeekday(event.target.value)}
              >
                {[1, 2, 3, 4, 5, 6].map((day) => (
                  <option key={day} value={day}>
                    {weekdayLabels[day]}
                  </option>
                ))}
              </select>
            </div>
            <div className="grid gap-2">
              <Label htmlFor="slot-time">Hora</Label>
              <Input id="slot-time" type="time" value={time} onChange={(event) => setTime(event.target.value)} />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="slot-room">Salón (opcional)</Label>
              <Input id="slot-room" value={room} maxLength={60} onChange={(event) => setRoom(event.target.value)} />
            </div>
            <Button type="submit" disabled={saving}>
              {saving ? "Guardando…" : "Agregar"}
            </Button>
            {formError && <p className="text-sm text-destructive sm:col-span-5">{formError}</p>}
          </form>
        )}
      </SectionCard>

      <SectionCard title="Tus horarios" headingLevel={3} bare>
        {slots === null ? (
          <p className="px-6 text-muted-foreground">Cargando…</p>
        ) : slots.length === 0 ? (
          <div className="px-6">
            <EmptyState icon={Clock} message="Todavía no cargaste horarios." />
          </div>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-6">Día</TableHead>
                <TableHead>Hora</TableHead>
                <TableHead>Materia · Grupo</TableHead>
                <TableHead>Salón</TableHead>
                <TableHead className="pr-6">
                  <span className="sr-only">Acciones</span>
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {slots.map((slot) => (
                <TableRow key={slot.id}>
                  <TableCell className="pl-6">{weekdayLabels[slot.weekday]}</TableCell>
                  <TableCell className="tabular-nums">{slot.start_time}</TableCell>
                  <TableCell>
                    {slot.subject.name} · {slot.group.name}
                  </TableCell>
                  <TableCell>{slot.room ?? "—"}</TableCell>
                  <TableCell className="pr-6 text-right">
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => void onRemove(slot)}
                      aria-label={`Quitar ${weekdayLabels[slot.weekday]} ${slot.start_time} ${slot.subject.name} ${slot.group.name}`}
                    >
                      Quitar
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </SectionCard>
    </div>
  )
}
