import { useState } from "react"
import { isAxiosError } from "axios"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { SingleSelect } from "@/components/ui/single-select"
import { Textarea } from "@/components/ui/textarea"
import { assessmentTypeLabels, type AssessmentSummary } from "@/types"
import { formatShortDate } from "@/lib/utils"
import * as trackingApi from "./trackingApi"

interface AccommodationInstanceOverrideFormProps {
  accommodationId: number
  /**
   * Assessment options, taken straight from `tracking.recent_assessments`
   * already loaded by StudentTrackingPage — no extra fetch (docs/prompts/24 §5).
   */
  assessments: AssessmentSummary[]
}

/**
 * "Desactivar para una evaluación": records an AccommodationInstanceOverride so
 * a vigent accommodation does not apply on one specific assessment, with a
 * mandatory reason (docs/prompts/24 §5). Collapsed until clicked, like the other
 * inline actions on the tracking page.
 *
 * The backend only lets the teacher who OWNS the chosen assessment do this and
 * answers 403 otherwise; the SPA cannot know assessment ownership up front
 * (there is no "assessments I teach" endpoint), so it shows the action wherever
 * the accommodations section is visible and surfaces the 403 as a readable
 * message rather than an unhandled exception. This mirrors the documented
 * limitation of BarrierAccommodationsPanel — not a bug of this session.
 */
export function AccommodationInstanceOverrideForm({
  accommodationId,
  assessments,
}: AccommodationInstanceOverrideFormProps) {
  const [expanded, setExpanded] = useState(false)
  const [assessmentId, setAssessmentId] = useState("")
  const [reason, setReason] = useState("")
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [done, setDone] = useState(false)

  function reset() {
    setAssessmentId("")
    setReason("")
    setError(null)
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault()
    setError(null)

    const id = Number(assessmentId)
    if (!id) {
      setError("Elegí una evaluación.")
      return
    }
    const trimmed = reason.trim()
    if (!trimmed) {
      setError("Escribí un motivo.")
      return
    }

    setSubmitting(true)
    try {
      await trackingApi.deactivateAccommodationForAssessment(accommodationId, {
        assessment_id: id,
        reason: trimmed,
      })
      setDone(true)
      setExpanded(false)
      reset()
    } catch (err) {
      if (isAxiosError(err) && err.response?.status === 403) {
        setError(
          "Solo el docente responsable de esa evaluación puede desactivar la adaptación para ella.",
        )
      } else if (isAxiosError(err) && err.response?.status === 422) {
        setError("Esa evaluación no es válida para esta adaptación.")
      } else {
        setError("No pudimos desactivar la adaptación para esa evaluación.")
      }
    } finally {
      setSubmitting(false)
    }
  }

  if (!expanded) {
    return (
      <div className="mt-2">
        {done && (
          <p className="mb-2 text-xs text-muted-foreground">
            Adaptación desactivada para la evaluación elegida.
          </p>
        )}
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => {
            setDone(false)
            setExpanded(true)
          }}
        >
          Desactivar para una evaluación
        </Button>
      </div>
    )
  }

  return (
    <form onSubmit={handleSubmit} className="mt-2 grid gap-2 border-t pt-2">
      <div className="grid gap-1.5">
        <Label htmlFor={`override-assessment-${accommodationId}`} className="text-xs">
          Evaluación
        </Label>
        <SingleSelect
          id={`override-assessment-${accommodationId}`}
          value={assessmentId}
          onChange={(value) => setAssessmentId(value)}
          placeholder="Elegí una evaluación…"
          options={assessments.map((assessment) => ({
            value: String(assessment.id),
            label:
              assessmentTypeLabels[assessment.type] +
              (assessment.variant_number ? ` (variante ${assessment.variant_number})` : "") +
              ` · ${formatShortDate(assessment.created_at)}`,
          }))}
        />
      </div>
      <div className="grid gap-1.5">
        <Label htmlFor={`override-reason-${accommodationId}`} className="text-xs">
          Motivo
        </Label>
        <Textarea
          id={`override-reason-${accommodationId}`}
          value={reason}
          onChange={(event) => setReason(event.target.value)}
          placeholder="Por qué no aplica esta vez…"
        />
      </div>
      {error && <p className="text-sm text-destructive">{error}</p>}
      <div className="flex gap-2">
        <Button type="submit" size="sm" disabled={submitting || assessments.length === 0}>
          {submitting ? "Desactivando…" : "Desactivar"}
        </Button>
        <Button
          type="button"
          size="sm"
          variant="outline"
          disabled={submitting}
          onClick={() => {
            setExpanded(false)
            reset()
          }}
        >
          Cancelar
        </Button>
      </div>
      {assessments.length === 0 && (
        <p className="text-xs text-muted-foreground">
          No hay evaluaciones recientes para desactivar.
        </p>
      )}
    </form>
  )
}
