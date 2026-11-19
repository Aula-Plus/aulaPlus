import { useCallback, useEffect, useRef, useState } from "react"
import { Plus } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { EmptyState } from "@/components/ui/empty-state"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { SectionCard } from "@/components/ui/section-card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { Textarea } from "@/components/ui/textarea"
import { addDays, dayMonth, mondayOf, toDay, weekdayDayMonth } from "@/features/calendar/calendarDates"
import type {
  Group,
  MeetingSheetType,
  ObservationScope,
  PsychopedagogyMeeting,
  PsychopedagogyMeetingKind,
  PsychopedagogyOverview,
  StaffMember,
  Student,
} from "@/types"
import { completeMeeting, createMeeting, fetchMeetings } from "./psychopedagogySpaceApi"
import { SheetEditorDialog } from "./SheetEditorDialog"
import { useSheetFormOptions } from "./useSheetFormOptions"
import { OBSERVATION_SCOPES, observationScopeLabels } from "./spaceText"

const selectClass =
  "h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"

/**
 * «Agenda» (P10-AGE-02/03). Psicopedagogía schedules, marks done and writes
 * the session note; Dirección only sees the week's workload by type —
 * «para gestionar tiempos del equipo, no para saber de quién se habló».
 */
export function AgendaTab({
  works,
  overview,
  onChanged,
}: {
  works: boolean
  overview: PsychopedagogyOverview | null
  onChanged: () => void
}) {
  if (!works) return <WorkloadCard overview={overview} />
  return <TeamAgenda onChanged={onChanged} />
}

function WorkloadCard({ overview }: { overview: PsychopedagogyOverview | null }) {
  return (
    <SectionCard title="Carga de la semana">
      <p className="mb-4 text-sm text-muted-foreground">
        Lo que ves acá es para gestionar tiempos del equipo, no para saber de quién se habló ni qué se dijo.
      </p>
      {overview ? (
        <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {overview.workload.map((row) => (
            <div key={row.key} className="rounded-md border p-3">
              <dt className="text-sm text-muted-foreground">{row.name ?? "Sesiones individuales"}</dt>
              <dd className="text-2xl font-semibold">{row.count}</dd>
            </div>
          ))}
        </dl>
      ) : (
        <p className="text-sm text-muted-foreground">Cargando…</p>
      )}
    </SectionCard>
  )
}

function withWhom(meeting: PsychopedagogyMeeting): string {
  return [meeting.student?.name, meeting.group?.name].filter(Boolean).join(" · ") || "—"
}

function withTeam(meeting: PsychopedagogyMeeting): string {
  const names = meeting.attendees.map((person) => person.name).join(", ")
  const external = meeting.external_attendees ? ` + ${meeting.external_attendees}` : ""
  return `${names || "—"}${external}`
}

function TeamAgenda({ onChanged }: { onChanged: () => void }) {
  const [week, setWeek] = useState(() => mondayOf(toDay(new Date())))
  const [meetings, setMeetings] = useState<PsychopedagogyMeeting[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [scheduling, setScheduling] = useState(false)
  const [completing, setCompleting] = useState<PsychopedagogyMeeting | null>(null)
  const [sheetFor, setSheetFor] = useState<PsychopedagogyMeeting | null>(null)
  const options = useSheetFormOptions()

  // Only the latest request counts: a slower answer for a week left behind
  // must not show under the current one.
  const latest = useRef(0)

  const load = useCallback(() => {
    const mine = ++latest.current
    fetchMeetings(week).then(
      (list) => {
        if (mine !== latest.current) return
        setError(null)
        setMeetings(list)
      },
      () => {
        if (mine === latest.current) setError("No pudimos cargar la agenda.")
      },
    )
  }, [week])

  useEffect(() => {
    load()
    return () => {
      latest.current++
    }
  }, [load])

  function changed() {
    load()
    onChanged()
  }

  async function markMeetingDone(meeting: PsychopedagogyMeeting) {
    try {
      await completeMeeting(meeting.id)
      changed()
    } catch {
      setError("No pudimos marcarla realizada.")
    }
  }

  return (
    <SectionCard
      title={`Semana del ${dayMonth(week)} al ${dayMonth(addDays(week, 6))}`}
      action={
        <Button size="sm" onClick={() => setScheduling(true)}>
          <Plus className="size-4" /> Agendar encuentro
        </Button>
      }
    >
      <div className="mb-3 flex gap-2">
        <Button size="sm" variant="outline" onClick={() => setWeek(addDays(week, -7))}>
          Semana anterior
        </Button>
        <Button size="sm" variant="outline" onClick={() => setWeek(addDays(week, 7))}>
          Semana siguiente
        </Button>
      </div>
      {error && <p className="mb-3 text-sm text-destructive">{error}</p>}
      {meetings === null ? (
        <p className="text-sm text-muted-foreground">Cargando…</p>
      ) : meetings.length === 0 ? (
        <EmptyState message="No hay encuentros agendados esta semana." />
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Día</TableHead>
              <TableHead>Hora</TableHead>
              <TableHead>Tipo</TableHead>
              <TableHead>Con quién</TableHead>
              <TableHead>Con el equipo</TableHead>
              <TableHead>Estado</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {meetings.map((meeting) => (
              <TableRow key={meeting.id}>
                <TableCell>{weekdayDayMonth(meeting.scheduled_on)}</TableCell>
                <TableCell>{meeting.start_time}</TableCell>
                <TableCell>
                  {meeting.kind === "individual_session" ? "Sesión individual" : meeting.sheet_type?.name}
                </TableCell>
                <TableCell>{withWhom(meeting)}</TableCell>
                <TableCell>{withTeam(meeting)}</TableCell>
                <TableCell>
                  <div className="flex flex-wrap items-center gap-2">
                    {meeting.status === "done" ? (
                      <Badge tone="success">Realizada</Badge>
                    ) : (
                      <Badge tone="info">Programada</Badge>
                    )}
                    {meeting.sheet && <Badge>con planilla</Badge>}
                    {meeting.status === "scheduled" && (
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                          meeting.kind === "individual_session" ? setCompleting(meeting) : void markMeetingDone(meeting)
                        }
                      >
                        Marcar realizada
                      </Button>
                    )}
                    {meeting.kind === "meeting" && !meeting.sheet && (
                      <Button size="sm" variant="ghost" onClick={() => setSheetFor(meeting)}>
                        Completar planilla
                      </Button>
                    )}
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
      <p className="mt-3 text-sm text-muted-foreground">
        Una sesión individual deja una nota en la ficha del alumno, con el selector de quién la ve: arranca en
        «solo Psicopedagogía». Una reunión con más de una persona genera una planilla.
      </p>
      {scheduling && (
        <ScheduleDialog
          defaultDate={week}
          onClose={() => setScheduling(false)}
          onSaved={() => {
            setScheduling(false)
            changed()
          }}
        />
      )}
      {completing && (
        <SessionNoteDialog
          meeting={completing}
          onClose={() => setCompleting(null)}
          onSaved={() => {
            setCompleting(null)
            changed()
          }}
        />
      )}
      {sheetFor && options && (
        <SheetEditorDialog
          options={options}
          fromMeeting={sheetFor}
          onClose={() => setSheetFor(null)}
          onSaved={() => {
            setSheetFor(null)
            load()
          }}
        />
      )}
    </SectionCard>
  )
}

function ScheduleDialog({
  defaultDate,
  onClose,
  onSaved,
}: {
  defaultDate: string
  onClose: () => void
  onSaved: () => void
}) {
  const options = useSheetFormOptions()
  const [kind, setKind] = useState<PsychopedagogyMeetingKind>("individual_session")
  const [typeId, setTypeId] = useState("")
  const [date, setDate] = useState(defaultDate)
  const [time, setTime] = useState("10:00")
  const [studentId, setStudentId] = useState("")
  const [groupId, setGroupId] = useState("")
  const [attendees, setAttendees] = useState<number[]>([])
  const [external, setExternal] = useState("")
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const types: MeetingSheetType[] = options?.types ?? []
  const staff: StaffMember[] = options?.staff ?? []
  const students: Student[] = options?.students ?? []
  const groups: Group[] = options?.groups ?? []

  async function save() {
    setError(null)
    if (kind === "individual_session" && !studentId) {
      setError("Elegí con qué alumno es la sesión.")
      return
    }
    setSaving(true)
    try {
      await createMeeting({
        kind,
        meeting_sheet_type_id: kind === "meeting" ? Number(typeId) : null,
        scheduled_on: date,
        start_time: time,
        student_id: studentId ? Number(studentId) : null,
        group_id: kind === "meeting" && groupId ? Number(groupId) : null,
        attendee_ids: attendees,
        external_attendees: external.trim() || null,
      })
      onSaved()
    } catch {
      setError("No pudimos agendar el encuentro. Revisá los datos.")
      setSaving(false)
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Agendar encuentro</DialogTitle>
          <DialogDescription>Día, hora, tipo y con quién.</DialogDescription>
        </DialogHeader>
        <div className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="meeting-kind">Tipo</Label>
            <select
              id="meeting-kind"
              className={selectClass}
              value={kind === "individual_session" ? "individual_session" : `type-${typeId}`}
              onChange={(event) => {
                const value = event.target.value
                if (value === "individual_session") {
                  setKind("individual_session")
                } else {
                  setKind("meeting")
                  setTypeId(value.replace("type-", ""))
                }
              }}
            >
              <option value="individual_session">Sesión individual</option>
              {types.map((type) => (
                <option key={type.id} value={`type-${type.id}`}>
                  {type.name}
                </option>
              ))}
            </select>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="meeting-date">Día</Label>
              <Input id="meeting-date" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="meeting-time">Hora</Label>
              <Input id="meeting-time" type="time" value={time} onChange={(e) => setTime(e.target.value)} />
            </div>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="meeting-student">
              {kind === "individual_session" ? "Alumno" : "Alumno (opcional)"}
            </Label>
            <select
              id="meeting-student"
              className={selectClass}
              value={studentId}
              onChange={(e) => setStudentId(e.target.value)}
            >
              <option value="">—</option>
              {students.map((student) => (
                <option key={student.id} value={student.id}>
                  {student.full_name}
                </option>
              ))}
            </select>
          </div>
          {kind === "meeting" && (
            <div className="grid gap-2">
              <Label htmlFor="meeting-group">Grupo (opcional)</Label>
              <select
                id="meeting-group"
                className={selectClass}
                value={groupId}
                onChange={(e) => setGroupId(e.target.value)}
              >
                <option value="">—</option>
                {groups.map((group) => (
                  <option key={group.id} value={group.id}>
                    {group.name}
                  </option>
                ))}
              </select>
            </div>
          )}
          <PeopleChecklist
            legend="Con el equipo"
            staff={staff}
            selected={attendees}
            onChange={setAttendees}
          />
          <div className="grid gap-2">
            <Label htmlFor="meeting-external">Además (opcional)</Label>
            <Input
              id="meeting-external"
              placeholder="Familia, equipo externo…"
              value={external}
              onChange={(e) => setExternal(e.target.value)}
            />
          </div>
          {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>
            Cancelar
          </Button>
          <Button type="button" onClick={() => void save()} disabled={saving}>
            {saving ? "Guardando…" : "Agendar"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

export function PeopleChecklist({
  legend,
  staff,
  selected,
  onChange,
}: {
  legend: string
  staff: StaffMember[]
  selected: number[]
  onChange: (ids: number[]) => void
}) {
  return (
    <fieldset className="grid gap-2">
      <legend className="mb-2 text-sm font-medium">{legend}</legend>
      <div className="grid max-h-40 gap-1 overflow-y-auto rounded-md border p-2">
        {staff.map((person) => (
          <label key={person.id} className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={selected.includes(person.id)}
              onChange={(event) =>
                onChange(
                  event.target.checked ? [...selected, person.id] : selected.filter((id) => id !== person.id),
                )
              }
            />
            {person.name}
          </label>
        ))}
        {staff.length === 0 && <p className="text-sm text-muted-foreground">Sin personas para elegir.</p>}
      </div>
    </fieldset>
  )
}

/**
 * «Marcar realizada» on an individual session, with its optional note. The
 * note is an observation in the student's file, «solo Psicopedagogía» unless
 * opened to another role (P10-DEC-10).
 */
export function SessionNoteDialog({
  meeting,
  onClose,
  onSaved,
}: {
  meeting: PsychopedagogyMeeting
  onClose: () => void
  onSaved: () => void
}) {
  const [note, setNote] = useState("")
  const [scope, setScope] = useState<ObservationScope>("psychopedagogue")
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  async function save() {
    setSaving(true)
    setError(null)
    try {
      await completeMeeting(meeting.id, note.trim() ? { note: note.trim(), scope } : {})
      onSaved()
    } catch {
      setError("No pudimos guardar.")
      setSaving(false)
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Sesión con {meeting.student?.name ?? "el alumno"}</DialogTitle>
          <DialogDescription>La nota queda en la ficha del alumno, como una observación.</DialogDescription>
        </DialogHeader>
        <div className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="session-note">Nota de la sesión (opcional)</Label>
            <Textarea id="session-note" rows={4} value={note} onChange={(e) => setNote(e.target.value)} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="session-scope">Visible para</Label>
            <select
              id="session-scope"
              className={selectClass}
              value={scope}
              onChange={(e) => setScope(e.target.value as ObservationScope)}
            >
              {OBSERVATION_SCOPES.map((option) => (
                <option key={option} value={option}>
                  {observationScopeLabels[option]}
                </option>
              ))}
            </select>
          </div>
          {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>
            Cancelar
          </Button>
          <Button type="button" onClick={() => void save()} disabled={saving}>
            Marcar realizada
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
