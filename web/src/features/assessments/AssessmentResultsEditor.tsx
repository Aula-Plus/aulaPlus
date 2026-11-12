import { useCallback, useEffect, useMemo, useState } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import type { Assessment, AssessmentResultScale, GroupTrackingStudent, OfficialScale } from "@/types"
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
  /**
   * A result recorded the other way (a score where levels are recorded now,
   * or the reverse), kept visible; null when there is none.
   */
  previous: string | null
  /** The teacher chose to replace `previous` (explicit, never silent). */
  replace: boolean
}

const EMPTY_ROW: RowState = {
  score: "",
  levelId: "",
  number: "",
  shownNumber: null,
  feedback: "",
  previous: null,
  replace: false,
}

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
 * With the switch on, no equivalence and no number written, the level is
 * shown with a discreet «sin nota» (PO answer of 12/11).
 *
 * A result recorded the other way (a score in a group that records levels
 * now, or the reverse) stays visible, and replacing it takes an explicit
 * «Reemplazar». When the group has two valid scales, the teacher chooses one
 * before the first result.
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
        const byLevel = scale.mode !== "score"
        for (const result of results) {
          if (next[result.student_id]) {
            // Recorded the other way: kept visible, not editable until replaced.
            const previous =
              byLevel && result.scale_level === null && result.score !== null
                ? `nota ${result.score}`
                : !byLevel && result.score === null && result.scale_level !== null
                  ? result.scale_level.name
                  : null
            next[result.student_id] = {
              score: result.score === null || previous ? "" : String(result.score),
              levelId: result.scale_level && !previous ? String(result.scale_level.id) : "",
              number:
                result.numeric_grade_source === "teacher" && result.numeric_grade !== null
                  ? String(result.numeric_grade)
                  : "",
              shownNumber: result.numeric_grade,
              feedback: result.feedback ?? "",
              previous,
              replace: false,
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
  const choices = resultScale?.mode === "choose" ? resultScale.choices : null
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
  // backend requires one per row); feedback alone is not persistable. A row
  // recorded the other way only goes when the teacher chose to replace it.
  const payload = useMemo<AssessmentResultInput[]>(() => {
    const editable = (row: RowState | undefined) => row !== undefined && (!row.previous || row.replace)
    if (scale) {
      return students
        .filter((student) => editable(rows[student.id]) && rows[student.id].levelId !== "")
        .map((student) => {
          const row = rows[student.id]
          return {
            student_id: student.id,
            scale_level_id: Number(row.levelId),
            ...(teacherWritesNumber ? { numeric_grade: row.number === "" ? null : Number(row.number) } : {}),
            ...(row.replace ? { replace_previous: true } : {}),
            feedback: row.feedback.trim() || null,
          }
        })
    }
    return students
      .filter((student) => editable(rows[student.id]) && rows[student.id].score.trim() !== "")
      .map((student) => ({
        student_id: student.id,
        score: Number(rows[student.id].score),
        ...(rows[student.id].replace ? { replace_previous: true } : {}),
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

  /**
   * Read-only rendering of a level result: the level and, if the school shows
   * the number, the number — or a discreet «sin nota» when there is none.
   */
  function levelCell(row: RowState) {
    const level = scale?.levels.find((l) => String(l.id) === row.levelId)
    if (!level) return <span>—</span>
    if (row.shownNumber !== null) return <span>{`${level.name} · ${row.shownNumber}`}</span>
    return (
      <span>
        {level.name}
        {numericGrade?.shown && <span className="ml-2 text-xs text-muted-foreground">sin nota</span>}
      </span>
    )
  }

  /** A result recorded the other way: kept visible, replaced only on purpose. */
  function previousCell(studentId: number, studentName: string, row: RowState) {
    return (
      <span className="flex flex-wrap items-center gap-2 text-sm">
        <span className="text-muted-foreground">Registrado antes como {row.previous}</span>
        {canManage && (
          <Button
            type="button"
            variant="outline"
            size="sm"
            aria-label={`Reemplazar el resultado de ${studentName}`}
            onClick={() => updateRow(studentId, { replace: true })}
          >
            Reemplazar
          </Button>
        )}
      </span>
    )
  }

  async function chooseScale(scaleId: number) {
    setError(null)
    setSaving(true)
    try {
      setResultScale(await assessmentsApi.chooseAssessmentResultScale(assessment.id, scaleId))
    } catch {
      setError("No pudimos guardar la escala elegida.")
    } finally {
      setSaving(false)
    }
  }

  if (choices) {
    return (
      <ScaleChooser choices={choices} canManage={canManage} saving={saving} error={error} onChoose={chooseScale} />
    )
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
                  {row.previous && !row.replace ? (
                    previousCell(student.id, student.full_name, row)
                  ) : scale ? (
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
                      levelCell(row)
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
                      disabled={row.previous !== null && !row.replace}
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

/**
 * Two or more valid scales for the group (PO answer of 12/11): the teacher
 * chooses which one this assessment records with, before the first result.
 * The choice is kept with the assessment.
 */
function ScaleChooser({
  choices,
  canManage,
  saving,
  error,
  onChoose,
}: {
  choices: OfficialScale[]
  canManage: boolean
  saving: boolean
  error: string | null
  onChoose: (scaleId: number) => void
}) {
  const [selected, setSelected] = useState("")

  if (!canManage) {
    return (
      <p className="text-sm text-muted-foreground">
        Todavía no se eligió con qué escala se registra esta evaluación.
      </p>
    )
  }

  return (
    <div className="grid max-w-xl gap-3">
      <p className="text-sm">
        El grupo tiene más de una escala oficial. Elegí con cuál registrás esta evaluación antes de
        cargar el primer resultado: queda guardada con la evaluación.
      </p>
      <select
        className="rounded-md border px-2 py-1 text-sm"
        aria-label="Escala de esta evaluación"
        value={selected}
        onChange={(event) => setSelected(event.target.value)}
      >
        <option value="">Elegí una escala</option>
        {choices.map((choice) => (
          <option key={choice.id} value={choice.id}>
            {choice.framework.name} — {choice.name}
          </option>
        ))}
      </select>
      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
      <div>
        <Button type="button" disabled={selected === "" || saving} onClick={() => onChoose(Number(selected))}>
          {saving ? "Guardando…" : "Usar esta escala"}
        </Button>
      </div>
    </div>
  )
}
