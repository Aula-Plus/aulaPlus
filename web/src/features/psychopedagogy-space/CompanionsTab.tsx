import { useCallback, useEffect, useState } from "react"
import { Plus } from "lucide-react"
import { Button } from "@/components/ui/button"
import { ConfirmDialog } from "@/components/ui/confirm-dialog"
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
import { fetchStudents } from "@/features/students/studentsApi"
import { WEEKDAYS, weekdayNameLabels, type Student, type TherapeuticCompanion, type Weekday } from "@/types"
import { createCompanion, deleteCompanion, fetchCompanions, updateCompanion } from "./psychopedagogySpaceApi"
import { formatDays, formatHours } from "./spaceText"

const selectClass =
  "h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"

/**
 * «Acompañantes» (P10-ACO-02): who accompanies each student, weekly hours and
 * days. Psicopedagogía assigns and edits, with the reason; Dirección reads the
 * full list without it (P10-DEC-09) — the server never sends it to Dirección,
 * so the column is only drawn when it came.
 */
export function CompanionsTab({ works, onChanged }: { works: boolean; onChanged: () => void }) {
  const [companions, setCompanions] = useState<TherapeuticCompanion[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [editing, setEditing] = useState<TherapeuticCompanion | "new" | null>(null)

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

  async function remove(companion: TherapeuticCompanion) {
    try {
      await deleteCompanion(companion.id)
      changed()
    } catch {
      setError("No pudimos quitar el acompañante.")
    }
  }

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
      ) : companions.length === 0 ? (
        <EmptyState message="No hay acompañantes registrados." />
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Acompañante</TableHead>
              <TableHead>Alumno</TableHead>
              <TableHead>Grupo</TableHead>
              <TableHead>Horas semanales</TableHead>
              <TableHead>Días</TableHead>
              {works && <TableHead>Motivo</TableHead>}
              {works && <TableHead className="sr-only">Acciones</TableHead>}
            </TableRow>
          </TableHeader>
          <TableBody>
            {companions.map((companion) => (
              <TableRow key={companion.id}>
                <TableCell>{companion.name}</TableCell>
                <TableCell>{companion.student?.name ?? "—"}</TableCell>
                <TableCell>{companion.group?.name ?? "—"}</TableCell>
                <TableCell>{formatHours(companion.weekly_hours)}</TableCell>
                <TableCell>{formatDays(companion.days)}</TableCell>
                {works && <TableCell>{companion.reason || "—"}</TableCell>}
                {works && (
                  <TableCell>
                    <div className="flex gap-1">
                      <Button size="sm" variant="ghost" onClick={() => setEditing(companion)}>
                        Editar
                      </Button>
                      <ConfirmDialog
                        trigger={
                          <Button size="sm" variant="ghost">
                            Quitar
                          </Button>
                        }
                        title="Quitar acompañante"
                        description={`Se deja de registrar a ${companion.name} como acompañante de ${companion.student?.name ?? "este alumno"}.`}
                        confirmLabel="Quitar"
                        onConfirm={() => void remove(companion)}
                      />
                    </div>
                  </TableCell>
                )}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
      <p className="mt-3 text-sm text-muted-foreground">
        {works
          ? "Psicopedagogía asigna el acompañante y fija sus horas y días. El colegio contrata y paga; acá solo queda registrada la cobertura, no el contrato."
          : "Ves quién entra al colegio, a quién acompaña, qué días y cuántas horas. El motivo del acompañamiento es del equipo de Psicopedagogía."}
      </p>
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
  const [reason, setReason] = useState(companion?.reason ?? "")
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
    const input = { name: name.trim(), weekly_hours: weekly, days, reason: reason.trim() || null }
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
            <Label htmlFor="companion-reason">Motivo (solo lo ve Psicopedagogía)</Label>
            <Textarea id="companion-reason" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
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
