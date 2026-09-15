import { useEffect, useState } from "react"
import { SectionCard } from "@/components/ui/section-card"
import { SingleSelect } from "@/components/ui/single-select"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import type { GroupTeacherAssignment, Subject } from "@/types"
import * as subjectsApi from "./subjectsApi"

interface TeacherOption {
  id: number
  name: string
}

interface Props {
  groupId: number
  canManage: boolean
}

/**
 * Director-managed teacher-subject assignments for a group (backend Sesión 2).
 * Each row is "teacher teaches subject in this group". Adding is idempotent on
 * the backend (unique on group+teacher+subject).
 */
export function GroupTeacherAssignments({ groupId, canManage }: Props) {
  const [assignments, setAssignments] = useState<GroupTeacherAssignment[] | null>(null)
  const [subjects, setSubjects] = useState<Subject[]>([])
  const [teachers, setTeachers] = useState<TeacherOption[]>([])
  const [teacherId, setTeacherId] = useState<string>("")
  const [subjectId, setSubjectId] = useState<string>("")
  const [error, setError] = useState<string | null>(null)

  function loadAssignments() {
    return subjectsApi
      .fetchGroupAssignments(groupId)
      .then(setAssignments)
      .catch(() => setError("No pudimos cargar las asignaciones."))
  }

  useEffect(() => {
    loadAssignments()
    subjectsApi.fetchSubjects().then(setSubjects).catch(() => undefined)
    subjectsApi.fetchTeachers().then(setTeachers).catch(() => undefined)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [groupId])

  async function onAdd() {
    if (!teacherId || !subjectId) return
    setError(null)
    try {
      await subjectsApi.createGroupAssignment(groupId, {
        teacher_id: Number(teacherId),
        subject_id: Number(subjectId),
      })
      setTeacherId("")
      setSubjectId("")
      await loadAssignments()
    } catch {
      setError("No pudimos crear la asignación.")
    }
  }

  async function onRemove(a: GroupTeacherAssignment) {
    setError(null)
    try {
      await subjectsApi.deleteGroupAssignment(groupId, {
        teacher_id: a.teacher_id,
        subject_id: a.subject_id,
      })
      await loadAssignments()
    } catch {
      setError("No pudimos eliminar la asignación.")
    }
  }

  return (
    <SectionCard title="Docentes y materias">
      {error && <p className="text-sm text-destructive">{error}</p>}

      {canManage && (
        <div className="mb-4 flex flex-wrap items-end gap-3">
          <div className="grid gap-1.5">
            <Label htmlFor="assign-teacher">Docente</Label>
            <SingleSelect
              id="assign-teacher"
              options={teachers.map((t) => ({ value: String(t.id), label: t.name }))}
              value={teacherId}
              onChange={setTeacherId}
            />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="assign-subject">Materia</Label>
            <SingleSelect
              id="assign-subject"
              options={subjects.map((s) => ({ value: String(s.id), label: s.name }))}
              value={subjectId}
              onChange={setSubjectId}
            />
          </div>
          <Button type="button" onClick={onAdd} disabled={!teacherId || !subjectId}>
            Asignar
          </Button>
        </div>
      )}

      {assignments === null ? (
        <p className="text-muted-foreground">Cargando…</p>
      ) : assignments.length === 0 ? (
        <p className="text-muted-foreground">Sin asignaciones todavía.</p>
      ) : (
        <ul className="grid gap-2">
          {assignments.map((a) => (
            <li
              key={`${a.teacher_id}-${a.subject_id}`}
              className="flex items-center justify-between rounded-md border px-3 py-2 text-sm"
            >
              <span>
                {a.teacher_name} — {a.subject_name ?? "—"}
              </span>
              {canManage && (
                <Button type="button" variant="ghost" size="sm" onClick={() => onRemove(a)}>
                  Quitar
                </Button>
              )}
            </li>
          ))}
        </ul>
      )}
    </SectionCard>
  )
}
