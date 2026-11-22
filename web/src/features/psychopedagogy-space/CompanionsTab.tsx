import { useCallback, useEffect, useState } from "react"
import { isAxiosError } from "axios"
import { Plus } from "lucide-react"
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
import { toDay } from "@/features/calendar/calendarDates"
import { fetchStudents } from "@/features/students/studentsApi"
import { formatShortDate } from "@/lib/utils"
import { WEEKDAYS, weekdayNameLabels, type Student, type TherapeuticCompanion, type Weekday } from "@/types"
import { createCompanion, endCompanion, fetchCompanions, updateCompanion } from "./psychopedagogySpaceApi"
import { formatDays, formatHours } from "./spaceText"

const selectClass =
  "h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"

/**
 * «Acompañantes» (P10-ACO-02): who accompanies each student, weekly hours,
 * days and the companion's role (free text, visible to whoever sees the
 * companion — ClickUp 1dfd3mc4bge). Psicopedagogía assigns, edits and ends a
 * companion («Dejó de acompañar»: an end date, the record stays in the
 * student's history). Dirección reads the current ones; the server never sends
 * it the history.
 */
export function CompanionsTab({ works, onChanged }: { works: boolean; onChanged: () => void }) {
  const [companions, setCompanions] = useState<TherapeuticCompanion[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [editing, setEditing] = useState<TherapeuticCompanion | "new" | null>(null)
  const [ending, setEnding] = useState<TherapeuticCompanion | null>(null)

  const load = useCallback(() => {
    fetchCompanions().then(setCompanions, () => setError("No pudimos cargar los acompañantes."))
  }, [])

  useEffect(() => {
    load()
  }, [load])

  function changed() {
    load()
    onChanged()
  }

  const current = companions?.filter((companion) => !companion.ended_on) ?? []
  const history = companions?.filter((companion) => companion.ended_on) ?? []

  return (
    <SectionCard
      title="Acompañantes terapéuticos"
      action={
        works ? (
          <Button size="sm" onClick={() => setEditing("new")}>
            <Plus className="size-4" /> Asignar acompañante
          </Button>
        ) : undefined
      }
    >
      {error && <p className="mb-3 text-sm text-destructive">{error}</p>}
      {companions === null ? (
        <p className="text-sm text-muted-foreground">Cargando…</p>
      ) : current.length === 0 ? (
        <EmptyState message="No hay acompañantes vigentes." />
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Acompañante</TableHead>
              <TableHead>Alumno</TableHead>
              <TableHead>Grupo</TableHead>
              <TableHead>Rol del acompañante</TableHead>
              <TableHead>Horas semanales</TableHead>
              <TableHead>Días</TableHead>
              {works && <TableHead className="sr-only">Acciones</TableHead>}
            </TableRow>
          </TableHeader>
          <TableBody>
            {current.map((companion) => (
              <TableRow key={companion.id}>
                <TableCell>{companion.name}</TableCell>
                <TableCell>{companion.student?.name ?? "—"}</TableCell>
                <TableCell>{companion.group?.name ?? "—"}</TableCell>
                <TableCell>{companion.role || "—"}</TableCell>
                <TableCell>{formatHours(companion.weekly_hours)}</TableCell>
                <TableCell>{formatDays(companion.days)}</TableCell>
                {works && (
                  <TableCell>
                    <div className="flex gap-1">
                      <Button size="sm" variant="ghost" onClick={() => setEditing(companion)}>
                        Editar
                      </Button>
                      <Button size="sm" variant="ghost" onClick={() => setEnding(companion)}>
                        Dejó de acompañar
                      </Button>
                    </div>
                  </TableCell>
                )}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
      {works && history.length > 0 && (
        <div className="mt-6 grid gap-2" data-testid="companions-history">
          <h3 className="text-sm font-medium">Historial: ya no acompañan</h3>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Acompañante</TableHead>
                <TableHead>Alumno</TableHead>
                <TableHead>Rol del acompañante</TableHead>
                <TableHead>Horas semanales</TableHead>
                <TableHead>Días</TableHead>
                <TableHead>Hasta</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {history.map((companion) => (
                <TableRow key={companion.id}>
                  <TableCell>{companion.name}</TableCell>
                  <TableCell>{companion.student?.name ?? "—"}</TableCell>
                  <TableCell>{companion.role || "—"}</TableCell>
                  <TableCell>{formatHours(companion.weekly_hours)}</TableCell>
                  <TableCell>{formatDays(companion.days)}</TableCell>
                  <TableCell>{formatShortDate(companion.ended_on)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}
      <p className="mt-3 text-sm text-muted-foreground">
        {works
          ? "Psicopedagogía asigna el acompañante y fija su rol, sus horas y días. Cuando deja de acompañar se le pone fecha de fin y queda en el historial del alumno. El colegio contrata y paga; acá solo queda registrada la cobertura, no el contrato."
          : "Ves quién entra al colegio, a quién acompaña, con qué rol, qué días y cuántas horas."}
      </p>
      {ending && (
        <EndCompanionDialog
          companion={ending}
          onClose={() => setEnding(null)}
          onEnded={() => {
            setEnding(null)
            changed()
          }}
        />
      )}
      {editing && (
        <CompanionDialog
          companion={editing === "new" ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            changed()
          }}
        />
      )}
    </SectionCard>
  )
}

function CompanionDialog({
  companion,
  onClose,
  onSaved,
}: {
  companion: TherapeuticCompanion | null
  onClose: () => void
  onSaved: () => void
}) {
  const [students, setStudents] = useState<Student[]>([])
  const [studentId, setStudentId] = useState(String(companion?.student?.id ?? ""))
  const [name, setName] = useState(companion?.name ?? "")
  const [hours, setHours] = useState(companion ? String(companion.weekly_hours) : "")
  const [days, setDays] = useState<Weekday[]>(companion?.days ?? [])
  const [role, setRole] = useState(companion?.role ?? "")
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (!companion) fetchStudents().then(setStudents, () => setStudents([]))
  }, [companion])

  async function save() {
    setError(null)
    const weekly = Number(hours.replace(",", "."))
    if ((!companion && !studentId) || !name.trim() || !(weekly > 0) || days.length === 0) {
      setError("Completá alumno, nombre, horas semanales y al menos un día.")
      return
    }
    setSaving(true)
    const input = { name: name.trim(), weekly_hours: weekly, days, role: role.trim() || null }
    try {
      if (companion) await updateCompanion(companion.id, input)
      else await createCompanion({ ...input, student_id: Number(studentId) })
      onSaved()
    } catch {
      setError("No pudimos guardar. Revisá los datos (las horas van de media en media).")
      setSaving(false)
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{companion ? "Editar acompañante" : "Asignar acompañante"}</DialogTitle>
          <DialogDescription>Es un registro: el acompañante no tiene cuenta en Aula+.</DialogDescription>
        </DialogHeader>
        <div className="grid gap-4">
          {companion ? (
            <p className="text-sm">Alumno: {companion.student?.name}</p>
          ) : (
            <div className="grid gap-2">
              <Label htmlFor="companion-student">Alumno</Label>
              <select
                id="companion-student"
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
          )}
          <div className="grid gap-2">
            <Label htmlFor="companion-name">Nombre del acompañante</Label>
            <Input id="companion-name" value={name} onChange={(e) => setName(e.target.value)} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="companion-hours">Horas semanales</Label>
            <Input
              id="companion-hours"
              inputMode="decimal"
              value={hours}
              onChange={(e) => setHours(e.target.value)}
            />
          </div>
          <fieldset className="grid gap-2">
            <legend className="mb-2 text-sm font-medium">Días</legend>
            <div className="flex flex-wrap gap-3">
              {WEEKDAYS.map((day) => (
                <label key={day} className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={days.includes(day)}
                    onChange={(e) =>
                      setDays(e.target.checked ? [...days, day] : days.filter((value) => value !== day))
                    }
                  />
                  {weekdayNameLabels[day]}
                </label>
              ))}
            </div>
          </fieldset>
          <div className="grid gap-2">
            <Label htmlFor="companion-role">Rol del acompañante</Label>
            <Input id="companion-role" maxLength={160} value={role} onChange={(e) => setRole(e.target.value)} />
            <p className="text-xs text-muted-foreground">
              Lo ven Dirección y los docentes del alumno, junto con el nombre, las horas y los días.
            </p>
          </div>
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

/** «Dejó de acompañar»: the end date (today by default, never in the future). */
function EndCompanionDialog({
  companion,
  onClose,
  onEnded,
}: {
  companion: TherapeuticCompanion
  onClose: () => void
  onEnded: () => void
}) {
  const [today] = useState(() => toDay(new Date()))
  const [endedOn, setEndedOn] = useState(today)
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  async function save() {
    setError(null)
    if (!endedOn) {
      setError("Indicá la fecha de fin.")
      return
    }
    setSaving(true)
    try {
      await endCompanion(companion.id, endedOn)
      onEnded()
    } catch (err) {
      setError(
        isAxiosError(err) && err.response?.status === 422
          ? (err.response.data?.errors?.ended_on?.[0] ?? "Revisá la fecha de fin.")
          : "No pudimos guardar la fecha de fin.",
      )
      setSaving(false)
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Dejó de acompañar</DialogTitle>
          <DialogDescription>
            {companion.name} deja de acompañar a {companion.student?.name ?? "este alumno"}. Queda en el historial del
            alumno con esta fecha de fin.
          </DialogDescription>
        </DialogHeader>
        <div className="grid gap-2">
          <Label htmlFor="companion-ended-on">Fecha de fin</Label>
          <Input
            id="companion-ended-on"
            type="date"
            max={today}
            value={endedOn}
            onChange={(e) => setEndedOn(e.target.value)}
          />
          {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>
            Cancelar
          </Button>
          <Button type="button" onClick={() => void save()} disabled={saving}>
            {saving ? "Guardando…" : "Guardar fecha de fin"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
