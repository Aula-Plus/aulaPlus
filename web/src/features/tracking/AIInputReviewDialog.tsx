import { useCallback, useEffect, useRef, useState } from "react"
import { Link } from "react-router-dom"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { formatShortDate } from "@/lib/utils"
import {
  accommodationCategoryLabels,
  assessmentTypeLabels,
  type AIInputLine,
  type AIInputReview,
} from "@/types"
import * as aiInputApi from "./aiInputApi"

interface AIInputReviewDialogProps {
  groupId: number
  open: boolean
  onOpenChange: (open: boolean) => void
}

type ActiveForm = { key: string; kind: "mark" } | { key: string; kind: "ok"; markId: number }

function subjectsText(line: AIInputLine): string {
  return line.subjects === "all" ? "Todas las materias" : line.subjects.join(", ")
}

function assessmentTypesText(line: AIInputLine): string | null {
  if (line.assessment_types === "all") return null
  return line.assessment_types.map((type) => assessmentTypeLabels[type] ?? type).join(", ")
}

/**
 * «Ver lo que recibiría la IA» (ClickUp 1dfd3mc2wx2; documento vivo screen 03,
 * P03-AJU-03): exactly the lines «Lo que recibe la IA» would send for the
 * group, optionally for one subject, with access to the accommodation each one
 * comes from. The text is derived and is never edited here.
 *
 * Psicopedagogía marks a line «La IA lo entendió mal» (it stops being sent
 * while the mark is pending) and, on a marked line, «El ajuste está bien» (it
 * goes back as it was). Dirección reads only. Whether the actions show comes
 * from `can.mark`, decided server-side.
 */
export function AIInputReviewDialog({ groupId, open, onOpenChange }: AIInputReviewDialogProps) {
  const [subjectId, setSubjectId] = useState<number | null>(null)
  const [review, setReview] = useState<AIInputReview | null>(null)
  // The subject the shown review was loaded for. While another subject is
  // loading, the lines on screen belong to the previous one, so marking is
  // disabled: a mark made from there would be saved with the new subject.
  const [reviewSubjectId, setReviewSubjectId] = useState<number | null | undefined>(undefined)
  const [error, setError] = useState<string | null>(null)
  const [active, setActive] = useState<ActiveForm | null>(null)
  const [comment, setComment] = useState("")
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  // Only the latest request may update the view: switching subjects fires a
  // new GET, and a slower earlier response must not show another subject's
  // lines under the selector (a mark made from there would carry the wrong
  // subject to the team notice).
  const latestRequest = useRef(0)

  const load = useCallback(async () => {
    const request = ++latestRequest.current
    setError(null)
    try {
      const data = await aiInputApi.fetchAIInputReview(groupId, subjectId)
      if (request === latestRequest.current) {
        setReview(data)
        setReviewSubjectId(subjectId)
      }
    } catch {
      if (request === latestRequest.current) setError("No pudimos cargar lo que recibiría la IA.")
    }
  }, [groupId, subjectId])

  useEffect(() => {
    if (open) load()
    // Closing the dialog or unmounting discards any request still in flight.
    return () => {
      latestRequest.current++
    }
  }, [open, load])

  function openForm(form: ActiveForm) {
    setActive(form)
    setComment("")
    setFormError(null)
    setNotice(null)
  }

  async function submit() {
    if (!active) return
    setSaving(true)
    setFormError(null)
    try {
      if (active.kind === "mark") {
        await aiInputApi.markAIInputLine(groupId, { line_key: active.key, subject_id: subjectId, comment })
        setNotice("Marcaste la línea. Mientras la marca esté pendiente, no se envía a la IA.")
      } else {
        await aiInputApi.confirmAIInputAdjustment(active.markId, comment)
        setNotice("Listo: la línea vuelve a enviarse a la IA sin cambios.")
      }
      setActive(null)
      await load()
    } catch (err) {
      setFormError(aiInputApi.validationMessage(err) ?? "No pudimos guardar la marca.")
    } finally {
      setSaving(false)
    }
  }

  const canMark = review?.can.mark ?? false
  const isStale = review !== null && reviewSubjectId !== subjectId

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>Lo que recibe la IA</DialogTitle>
          <DialogDescription>
            Lo que recibiría la IA al pedirle una clase o una evaluación para este grupo: solo ajustes
            vigentes que confirmó Psicopedagogía, como acciones pedagógicas, sin nombres, diagnósticos ni
            cantidad de alumnos. El texto se arma solo y no se edita a mano.
          </DialogDescription>
        </DialogHeader>

        <div className="grid gap-2">
          <Label htmlFor="ai-input-subject">Materia (opcional)</Label>
          <select
            id="ai-input-subject"
            className="h-9 rounded-md border bg-background px-3 text-sm"
            value={subjectId ?? ""}
            onChange={(event) => {
              setActive(null)
              setSubjectId(event.target.value === "" ? null : Number(event.target.value))
            }}
          >
            <option value="">Todas las materias</option>
            {(review?.subjects ?? []).map((subject) => (
              <option key={subject.id} value={subject.id}>
                {subject.name}
              </option>
            ))}
          </select>
        </div>

        {!canMark && review && (
          <p className="text-sm text-muted-foreground">Dirección la abre solo para leer: no marca líneas.</p>
        )}
        {notice && (
          <p role="status" className="text-sm text-success">
            {notice}
          </p>
        )}

        {error ? (
          <p className="text-sm text-destructive">{error}</p>
        ) : review === null ? (
          <p className="text-muted-foreground">Cargando…</p>
        ) : review.lines.length === 0 ? (
          <p className="text-sm text-muted-foreground">La IA no recibiría ningún ajuste para este grupo.</p>
        ) : (
          <ul className="grid gap-3">
            {review.lines.map((line) => {
              const types = assessmentTypesText(line)
              return (
                <li key={line.key} className="rounded-md border p-3 text-sm">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium">{line.action}</span>
                    <span className="text-muted-foreground">· {subjectsText(line)}</span>
                    {types && <span className="text-muted-foreground">· {types}</span>}
                    {line.category && <Badge tone="info">{accommodationCategoryLabels[line.category]}</Badge>}
                    {!line.sent && <Badge tone="warning">No se envía mientras la marca esté pendiente</Badge>}
                  </div>
                  {line.mark && (
                    <p className="mt-1 text-xs text-muted-foreground">
                      Marcada como «La IA lo entendió mal»
                      {line.mark.marked_by ? ` por ${line.mark.marked_by.name}` : ""}
                      {line.mark.created_at ? ` el ${formatShortDate(line.mark.created_at)}` : ""}
                      {line.mark.comment ? `: «${line.mark.comment}»` : ""}. Se resuelve cambiando el ajuste.
                    </p>
                  )}
                  {line.mark && canMark && (
                    <p role="note" className="mt-2 rounded-md bg-muted p-2 text-xs">
                      Ser más específico en la acción pedagógica del ajuste ayuda a la IA a decidir mejor.
                    </p>
                  )}
                  <div className="mt-2 flex flex-wrap items-center gap-2">
                    <span className="text-xs text-muted-foreground">Ver ajuste de origen:</span>
                    {line.origins.map((origin) => (
                      <Link
                        key={origin.accommodation_id}
                        to={`/alumnos/${origin.student_id}/seguimiento`}
                        className="text-xs hover:underline"
                      >
                        {origin.student_name ?? "Alumno"}
                      </Link>
                    ))}
                  </div>
                  {canMark && !isStale && active?.key !== line.key && (
                    <div className="mt-2 flex flex-wrap gap-2">
                      {line.mark ? (
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          onClick={() => openForm({ key: line.key, kind: "ok", markId: line.mark!.id })}
                        >
                          El ajuste está bien
                        </Button>
                      ) : (
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          onClick={() => openForm({ key: line.key, kind: "mark" })}
                        >
                          La IA lo entendió mal
                        </Button>
                      )}
                    </div>
                  )}
                  {canMark && !isStale && active?.key === line.key && (
                    <div className="mt-3 grid gap-2">
                      <p role="note" className="rounded-md bg-muted p-3 text-sm">
                        No escribas nombres de alumnos ni datos de salud.
                      </p>
                      <Label htmlFor={`ai-input-comment-${line.key}`}>Comentario (opcional)</Label>
                      <p id={`ai-input-comment-hint-${line.key}`} className="text-xs text-muted-foreground">
                        Nos sirve mucho saber qué entendió mal la IA: contalo en una frase si podés.
                      </p>
                      <Textarea
                        id={`ai-input-comment-${line.key}`}
                        rows={3}
                        maxLength={500}
                        aria-describedby={`ai-input-comment-hint-${line.key}`}
                        value={comment}
                        onChange={(event) => setComment(event.target.value)}
                      />
                      <p className="text-xs text-muted-foreground">
                        {active.kind === "mark"
                          ? "Le avisamos al equipo de Aula+ con la línea, el tipo de ajuste, la materia, tu comentario y el nivel del grupo; nunca datos del alumno."
                          : "La línea vuelve a enviarse sin tocar el ajuste y la marca se cierra. Le avisamos igual al equipo de Aula+."}
                      </p>
                      {formError && <p className="text-sm text-destructive">{formError}</p>}
                      <div className="flex flex-wrap gap-2">
                        <Button type="button" size="sm" onClick={submit} disabled={saving}>
                          {active.kind === "mark" ? "Marcar la línea" : "Confirmar que el ajuste está bien"}
                        </Button>
                        <Button type="button" size="sm" variant="ghost" onClick={() => setActive(null)}>
                          Cancelar
                        </Button>
                      </div>
                    </div>
                  )}
                </li>
              )
            })}
          </ul>
        )}
      </DialogContent>
    </Dialog>
  )
}
