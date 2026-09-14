import { useCallback, useEffect, useMemo, useState } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import type { Assessment, GroupTrackingStudent } from "@/types"
import * as assessmentsApi from "./assessmentsApi"
import type { AssessmentResultInput } from "./assessmentsApi"

interface AssessmentResultsEditorProps {
  assessment: Assessment
  /** The group roster — every student gets a row, whether or not they have a score yet. */
  students: GroupTrackingStudent[]
  /** When false, the table is read-only (scores shown, no inputs, no save). */
  canManage: boolean
}

/** Per-student editable row state (kept as strings so the inputs stay controlled). */
interface RowState {
  score: string
  feedback: string
}

function emptyRows(students: GroupTrackingStudent[]): Record<number, RowState> {
  return Object.fromEntries(students.map((student) => [student.id, { score: "", feedback: "" }]))
}

/**
 * The per-assessment results table (docs/prompts/14-frontend-evaluaciones-
 * resultados.md §4). Loads any existing results, lets the owning teacher type a
 * numeric score + optional feedback per student, and upserts the whole class in
 * a SINGLE POST (never one request per student). Non-owners see the same table
 * read-only.
 */
export function AssessmentResultsEditor({
  assessment,
  students,
  canManage,
}: AssessmentResultsEditorProps) {
  const [rows, setRows] = useState<Record<number, RowState>>(() => emptyRows(students))
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [savedAt, setSavedAt] = useState<number | null>(null)

  const loadResults = useCallback(() => {
    setLoading(true)
    return assessmentsApi
      .fetchAssessmentResults(assessment.id)
      .then((results) => {
        const next = emptyRows(students)
        for (const result of results) {
          if (next[result.student_id]) {
            next[result.student_id] = {
              score: String(result.score),
              feedback: result.feedback ?? "",
            }
          }
        }
        setRows(next)
      })
      .catch(() => setError("No pudimos cargar las notas de esta evaluación."))
      .finally(() => setLoading(false))
  }, [assessment.id, students])

  useEffect(() => {
    loadResults()
  }, [loadResults])

  function updateRow(studentId: number, patch: Partial<RowState>) {
    setRows((current) => ({
      ...current,
      [studentId]: { ...current[studentId], ...patch },
    }))
  }

  // Only students with a score typed in are part of the upsert batch (the
  // backend requires a score per row); feedback alone is not persistable.
  const payload = useMemo<AssessmentResultInput[]>(
    () =>
      students
        .filter((student) => rows[student.id]?.score.trim() !== "")
        .map((student) => ({
          student_id: student.id,
          score: Number(rows[student.id].score),
          feedback: rows[student.id].feedback.trim() || null,
        })),
    [students, rows],
  )

  const hasInvalidScore = payload.some(
    (row) => Number.isNaN(row.score) || row.score < 0 || row.score > 999.99,
  )

  async function handleSave() {
    setError(null)
    if (payload.length === 0) {
      setError("Cargá al menos una nota antes de guardar.")
      return
    }
    if (hasInvalidScore) {
      setError("Las notas deben ser números entre 0 y 999,99.")
      return
    }
    setSaving(true)
    try {
      await assessmentsApi.saveAssessmentResults(assessment.id, payload)
      setSavedAt(Date.now())
      await loadResults()
    } catch {
      setError("No pudimos guardar las notas.")
    } finally {
      setSaving(false)
    }
  }

  if (students.length === 0) {
    return <p className="text-sm text-muted-foreground">La clase no tiene alumnos para calificar.</p>
  }

  if (loading) {
    return <p className="text-sm text-muted-foreground">Cargando notas…</p>
  }

  return (
    <div className="grid gap-3">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Alumno</TableHead>
            <TableHead className="w-32">Nota</TableHead>
            <TableHead>Devolución</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {students.map((student) => {
            const row = rows[student.id] ?? { score: "", feedback: "" }
            return (
              <TableRow key={student.id}>
                <TableCell>{student.full_name}</TableCell>
                <TableCell>
                  {canManage ? (
                    <Input
                      type="number"
                      step="0.01"
                      min={0}
                      max={999.99}
                      value={row.score}
                      aria-label={`Nota de ${student.full_name}`}
                      onChange={(event) => updateRow(student.id, { score: event.target.value })}
                    />
                  ) : (
                    <span>{row.score === "" ? "—" : row.score}</span>
                  )}
                </TableCell>
                <TableCell>
                  {canManage ? (
                    <Input
                      type="text"
                      value={row.feedback}
                      aria-label={`Devolución de ${student.full_name}`}
                      onChange={(event) => updateRow(student.id, { feedback: event.target.value })}
                    />
                  ) : (
                    <span>{row.feedback === "" ? "—" : row.feedback}</span>
                  )}
                </TableCell>
              </TableRow>
            )
          })}
        </TableBody>
      </Table>

      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}

      {canManage && (
        <div className="flex items-center gap-3">
          <Button type="button" onClick={handleSave} disabled={saving}>
            {saving ? "Guardando…" : "Guardar notas"}
          </Button>
          {savedAt && !saving && (
            <span className="text-sm text-muted-foreground">Notas guardadas.</span>
          )}
        </div>
      )}
    </div>
  )
}
