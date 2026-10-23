import { useEffect, useState } from "react"
import { Link } from "react-router-dom"
import { Badge } from "@/components/ui/badge"
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { EmptyState } from "@/components/ui/empty-state"
import { formatDateTime } from "@/lib/utils"
import {
  NOT_AVAILABLE_LABEL,
  journeyContentTypeLabels,
  journeyFailureReasonLabels,
  journeyStepLabels,
} from "@/types"
import type { CreationJourney, JourneyStep, JourneyStepRecord } from "@/types"
import { fetchTeacherJourneys } from "./adoptionApi"

/**
 * Director-only "Ver recorridos" (ClickUp 86e3qkxtc, screen 28): the creation
 * journeys behind a teacher's counts this month, each recorded step with its
 * date and time. Context for when a teacher raises a difficulty — never a
 * judgement: an unconfirmed journey is shown as such, not as "abandoned", and
 * a step with no record reads «dato no disponible», never «no intentó».
 */
export function TeacherJourneysDialog({
  schoolId,
  teacherId,
  teacherName,
  onClose,
}: {
  schoolId: number
  teacherId: number
  teacherName: string
  onClose: () => void
}) {
  const [journeys, setJourneys] = useState<CreationJourney[] | null>(null)
  const [error, setError] = useState(false)

  useEffect(() => {
    fetchTeacherJourneys(schoolId, teacherId)
      .then(setJourneys)
      .catch(() => setError(true))
  }, [schoolId, teacherId])

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>Recorridos de {teacherName}</DialogTitle>
          <DialogDescription>
            Recorridos de creación de este mes y los pasos registrados de cada uno.
          </DialogDescription>
        </DialogHeader>
        {error ? (
          <p className="text-sm text-destructive">No pudimos cargar los recorridos.</p>
        ) : !journeys ? (
          <p className="text-muted-foreground">Cargando…</p>
        ) : journeys.length === 0 ? (
          <EmptyState message="No hay recorridos registrados este mes." />
        ) : (
          <ul className="grid gap-4">
            {journeys.map((journey) => (
              <JourneyItem key={journey.id} journey={journey} />
            ))}
          </ul>
        )}
      </DialogContent>
    </Dialog>
  )
}

// The earlier steps each kind of journey is expected to record. A missing one
// is listed as «dato no disponible» (not recorded — e.g. the assistant screens
// that report them don't exist yet), never as something the teacher didn't do.
// The confirmation is not among them: the server always records it, so its
// absence means the journey is not confirmed yet, not that data is missing.
const EXPECTED_STEPS: Record<"ai" | "manual", JourneyStep[]> = {
  manual: ["form_opened", "filling_started"],
  ai: ["form_opened", "filling_started", "generation_requested"],
}

// Last steps that already explain why a journey has no confirmation.
const CLOSING_STEPS: JourneyStep[] = ["generation_failed", "draft_discarded"]

function JourneyItem({ journey }: { journey: CreationJourney }) {
  const recorded = new Set(journey.steps.map((step) => step.step))
  const missing = journey.flow ? EXPECTED_STEPS[journey.flow].filter((step) => !recorded.has(step)) : []
  const pending = !journey.confirmed && !CLOSING_STEPS.includes(journey.last_step.step)
  const context = [journey.title, journey.group_name, journey.subject_name].filter(Boolean).join(" · ")

  return (
    <li className="grid gap-2 rounded-md border p-3 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="font-medium">
          {journeyContentTypeLabels[journey.content_type]}
          {context && <span className="font-normal text-muted-foreground"> — {context}</span>}
        </p>
        <Badge>{flowLabel(journey.flow)}</Badge>
      </div>

      <p className="text-muted-foreground">{attemptLabel(journey)}</p>
      <p>
        Último paso: {stepText(journey.last_step)} ({formatDateTime(journey.last_step.at)})
      </p>
      {pending && (
        <p className="text-muted-foreground">Todavía sin confirmar: puede seguir en preparación.</p>
      )}

      <ol className="grid gap-1 border-l pl-3" aria-label="Pasos registrados">
        {journey.steps.map((step, index) => (
          <li key={`${step.step}-${index}`} className="flex flex-wrap justify-between gap-x-4">
            <span>{stepText(step)}</span>
            <time dateTime={step.at} className="text-muted-foreground tabular-nums">
              {formatDateTime(step.at)}
            </time>
          </li>
        ))}
      </ol>

      {(missing.length > 0 || journey.flow === null) && (
        <ul className="grid gap-1 text-muted-foreground" aria-label="Pasos sin registro">
          {journey.flow === null ? (
            <li>Pasos anteriores: {NOT_AVAILABLE_LABEL}</li>
          ) : (
            missing.map((step) => (
              <li key={step}>
                {journeyStepLabels[step]}: {NOT_AVAILABLE_LABEL}
              </li>
            ))
          )}
        </ul>
      )}

      {journey.content?.type === "assessment" && journey.content.group_id !== null && (
        <Link
          to={`/clases/${journey.content.group_id}/evaluaciones`}
          className="text-primary underline-offset-4 hover:underline"
        >
          Abrir evaluación
        </Link>
      )}
    </li>
  )
}

function flowLabel(flow: CreationJourney["flow"]) {
  if (flow === "ai") return "Con asistente"
  if (flow === "manual") return "Creación manual"
  return `Origen: ${NOT_AVAILABLE_LABEL}`
}

/** One attempt per AI journey (retries don't add); manual creation has none. */
function attemptLabel(journey: CreationJourney) {
  if (journey.flow === "manual") return "Intentos: no aplica (creación manual)"
  if (journey.flow === "ai") {
    return journey.steps.some((step) => step.step === "generation_requested")
      ? "Intentos: 1 (los reintentos y pedidos de cambio no suman)"
      : `Intentos: ${NOT_AVAILABLE_LABEL}`
  }
  return `Intentos: ${NOT_AVAILABLE_LABEL}`
}

/** A failed generation shows an understandable cause when one is known. */
function stepText(step: JourneyStepRecord) {
  const label = journeyStepLabels[step.step]
  if (step.step !== "generation_failed" || !step.failure_reason) return label
  return `${label}: ${journeyFailureReasonLabels[step.failure_reason]}`
}
