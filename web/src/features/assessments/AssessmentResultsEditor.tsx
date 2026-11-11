import { useCallback, useEffect, useMemo, useState } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import type { Assessment, AssessmentResultScale, GroupTrackingStudent } from "@/types"
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
  /** Primaria: the chosen level id ("" = none yet). */
  levelId: string
  /** Primaria: the teacher's number 1..10 (only when the school has no equivalence). */
  number: string
  /** The number the server returns for display (school's or teacher's). */
  shownNumber: number | null
  feedback: string
}

const EMPTY_ROW: RowState = { score: "", levelId: "", number: "", shownNumber: null, feedback: "" }

function emptyRows(students: GroupTrackingStudent[]): Record<number, RowState> {
  return Object.fromEntries(students.map((student) => [student.id, { ...EMPTY_ROW }]))
}

const NUMBERS = Array.from({ length: 10 }, (_, i) => 10 - i)

/**
 * The per-assessment results table (docs/prompts/14-frontend-evaluaciones-
 * resultados.md §4). Loads any existing results, lets the owning teacher enter
 * each student's result + optional feedback, and upserts the whole class in a
 * SINGLE POST (never one request per student). Non-owners see it read-only.
 *
 * In a Primaria group with an official scale (ClickUp 1dfd3mc4bgg) each
 * student gets a level of that scale instead of a number. The number 1..10 is
 * shown only when the school turned it on: it is the school's equivalence
 * when it configured one, otherwise the teacher may write it with the level.
 * With the switch on, no equivalence and no number written, only the level
 * is shown (open product question — neutral on purpose).
 */
export function AssessmentResultsEditor({
  assessment,
  students,
  canManage,
}: AssessmentResultsEditorProps) {
  const [rows, setRows] = useState<Record<number, RowState>>(() => emptyRows(students))
  const [resultScale, setResultScale] = useState<AssessmentResultScale | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [savedAt, setSavedAt] = useState<number | null>(null)

  const loadResults = useCallback(() => {
    setLoading(true)
    return Promise.all([
      assessmentsApi.fetchAssessmentResults(assessment.id),
      assessmentsApi.fetchAssessmentResultScale(assessment.id),
    ])
      .then(([results, scale]) => {
        const next = emptyRows(students)
        for (const result of results) {
          if (next[result.student_id]) {
            next[result.student_id] = {
              score: result.score === null ? "" : String(result.score),
              levelId: result.scale_level ? String(result.scale_level.id) : "",
              number:
                result.numeric_grade_source === "teacher" && result.numeric_grade !== null
                  ? String(result.numeric_grade)
                  : "",
              shownNumber: result.numeric_grade,
              feedback: result.feedback ?? "",
            }
          }
        }
        setRows(next)
        setResultScale(scale)
      })
      .catch(() => setError("No pudimos cargar las calificaciones de esta evaluación."))
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

  const scale = resultScale?.mode === "scale" ? resultScale.scale : null
  const numericGrade = resultScale?.mode === "scale" ? resultScale.numeric_grade : null
  const teacherWritesNumber = numericGrade?.shown === true && numericGrade.source === "teacher"
  const schoolNumbers = useMemo(
    () =>
      new Map(
        numericGrade?.shown && numericGrade.source === "school"
          ? numericGrade.equivalences.map((row) => [row.level_id, row.numeric_grade])
          : [],
      ),
    [numericGrade],
  )

  // Only students with a result entered are part of the upsert batch (the
  // backend requires one per row); feedback alone is not persistable.
  const payload = useMemo<AssessmentResultInput[]>(() => {
    if (scale) {
      return students
        .filter((student) => rows[student.id]?.levelId !== "")
        .map((student) => {
          const row = rows[student.id]
          return {
            student_id: student.id,
            scale_level_id: Number(row.levelId),
            ...(teacherWritesNumber ? { numeric_grade: row.number === "" ? null : Number(row.number) } : {}),
            feedback: row.feedback.trim() || null,
          }
        })
    }
    return students
      .filter((student) => rows[student.id]?.score.trim() !== "")
      .map((student) => ({
        student_id: student.id,
        score: Number(rows[student.id].score),
        feedback: rows[student.id].feedback.trim() || null,
      }))
  }, [students, rows, scale, teacherWritesNumber])

  const hasInvalidScore = payload.some(
    (row) => "score" in row && (Number.isNaN(row.score) || row.score < 0 || row.score > 999.99),
  )

  async function handleSave() {
    setError(null)
    if (payload.length === 0) {
      setError(
        scale
          ? "Elegí el nivel de al menos un alumno antes de guardar."
          : "Cargá al menos una calificación antes de guardar.",
      )
      return
    }
    if (hasInvalidScore) {
      setError("Las calificaciones deben ser números entre 0 y 999,99.")
      return
    }
    setSaving(true)
    try {
      await assessmentsApi.saveAssessmentResults(assessment.id, payload)
      setSavedAt(Date.now())
      await loadResults()
    } catch {
      setError("No pudimos guardar las calificaciones.")
    } finally {
      setSaving(false)
    }
  }

  if (students.length === 0) {
    return <p className="text-sm text-muted-foreground">El grupo no tiene alumnos para calificar.</p>
  }

  if (loading) {
    return <p className="text-sm text-muted-foreground">Cargando calificaciones…</p>
  }

  /** Read-only rendering of a level result: the level and, if allowed, the number. */
  function levelText(row: RowState): string {
    const level = scale?.levels.find((l) => String(l.id) === row.levelId)
    if (!level) return "—"
    return row.shownNumber !== null ? `${level.name} · ${row.shownNumber}` : level.name
  }

  return (
    <div className="grid gap-3">
      {scale && (
        <p className="text-sm text-muted-foreground">
          Se registra con la escala {scale.framework.name} — {scale.name} ({scale.citation.document},
          p. {scale.citation.page}).
          {schoolNumbers.size > 0 && " La nota junto a cada nivel es la equivalencia del colegio."}
        </p>
      )}
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Alumno</TableHead>
            <TableHead className={scale ? "w-56" : "w-32"}>
              {scale ? "Nivel de avance" : "Calificación"}
            </TableHead>
            {scale && canManage && (teacherWritesNumber || schoolNumbers.size > 0) && (
              <TableHead className="w-28">Nota</TableHead>
            )}
            <TableHead>Devolución</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {students.map((student) => {
            const row = rows[student.id] ?? EMPTY_ROW
            return (
              <TableRow key={student.id}>
                <TableCell>{student.full_name}</TableCell>
                <TableCell>
                  {scale ? (
                    canManage ? (
                      <select
                        className="w-full rounded-md border px-2 py-1 text-sm"
                        value={row.levelId}
                        aria-label={`Nivel de avance de ${student.full_name}`}
                        onChange={(event) => updateRow(student.id, { levelId: event.target.value })}
                      >
                        <option value="">—</option>
                        {scale.levels.map((level) => (
                          <option key={level.id} value={level.id}>
                            {level.name}
                          </option>
                        ))}
                      </select>
                    ) : (
                      <span>{levelText(row)}</span>
                    )
                  ) : canManage ? (
                    <Input
                      type="number"
                      step="0.01"
                      min={0}
                      max={999.99}
                      value={row.score}
                      aria-label={`Calificación de ${student.full_name}`}
                      onChange={(event) => updateRow(student.id, { score: event.target.value })}
                    />
                  ) : (
                    <span>{row.score === "" ? "—" : row.score}</span>
                  )}
                </TableCell>
                {scale && canManage && teacherWritesNumber && (
                  <TableCell>
                    <select
                      className="w-full rounded-md border px-2 py-1 text-sm"
                      value={row.number}
                      aria-label={`Nota de ${student.full_name} (opcional)`}
                      onChange={(event) => updateRow(student.id, { number: event.target.value })}
                    >
                      <option value="">—</option>
                      {NUMBERS.map((n) => (
                        <option key={n} value={n}>
                          {n}
                        </option>
                      ))}
                    </select>
                  </TableCell>
                )}
                {scale && canManage && !teacherWritesNumber && schoolNumbers.size > 0 && (
                  <TableCell className="tabular-nums text-muted-foreground">
                    {row.levelId !== "" ? (schoolNumbers.get(Number(row.levelId)) ?? "—") : "—"}
                  </TableCell>
                )}
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
            {saving ? "Guardando…" : "Guardar calificaciones"}
          </Button>
          {savedAt && !saving && (
            <span className="text-sm text-muted-foreground">Calificaciones guardadas.</span>
          )}
        </div>
      )}
    </div>
  )
}
