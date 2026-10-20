import { useCallback, useEffect, useState } from "react"
import { Link } from "react-router-dom"
import { isAxiosError } from "axios"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { formatShortDate } from "@/lib/utils"
import type { FollowUpEvent, ScheduledFollowUpDetail, StaffMember } from "@/types"
import * as detailApi from "./followUpDetailApi"
import { personLabel, type RunAction } from "./followUpDetailUtils"
import { FollowUpPeopleSection } from "./FollowUpPeopleSection"
import { FollowUpStagesSection } from "./FollowUpStagesSection"
import * as scheduledFollowUpsApi from "./scheduledFollowUpsApi"

interface FollowUpDetailDialogProps {
  /** The follow-up to open; `null` keeps the dialog closed. */
  followUpId: number | null
  onClose: () => void
  /** Called with the server's record after every change (to refresh a list). */
  onChanged?: (detail: ScheduledFollowUpDetail) => void
}

/**
 * The full record of a follow-up, managed in place (ClickUp 86e3qkwna): title,
 * description, general deadline, stages, people, resolution and history.
 * Opened from "Mis seguimientos" and from the student file's panel, so
 * everyone involved works on the same up-to-date record without going
 * through the student file.
 *
 * What the viewer may do comes from the server (`can` flags computed by
 * ScheduledFollowUpPolicy); hiding a button is only UX — every action is
 * authorized again by the backend.
 */
export function FollowUpDetailDialog({ followUpId, onClose, onChanged }: FollowUpDetailDialogProps) {
  const [detail, setDetail] = useState<ScheduledFollowUpDetail | null>(null)
  const [candidates, setCandidates] = useState<StaffMember[]>([])
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async (id: number) => {
    setError(null)
    setDetail(null)
    try {
      const loaded = await detailApi.fetchFollowUpDetail(id)
      setDetail(loaded)
      // Only people who already see the student can be picked (backend rule).
      if (loaded.viewer.active) {
        scheduledFollowUpsApi
          .fetchFollowUpCandidates(loaded.student_id)
          .then(setCandidates)
          .catch(() => setCandidates([]))
      }
    } catch {
      setError("No pudimos cargar el seguimiento.")
    }
  }, [])

  useEffect(() => {
    if (followUpId !== null) load(followUpId)
  }, [followUpId, load])

  const run: RunAction = async (action) => {
    setError(null)
    setBusy(true)
    try {
      const updated = await action()
      setDetail(updated)
      onChanged?.(updated)
      return true
    } catch (err) {
      setError(errorMessage(err))
      return false
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open={followUpId !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-3xl" aria-busy={busy}>
        {detail === null ? (
          <>
            <DialogHeader>
              <DialogTitle>Seguimiento</DialogTitle>
              <DialogDescription>{error ?? "Cargando…"}</DialogDescription>
            </DialogHeader>
          </>
        ) : (
          <div className="grid gap-5">
            <DialogHeader>
              <div className="flex flex-wrap items-center gap-2">
                <DialogTitle className="text-xl">{detail.title || "Seguimiento sin título"}</DialogTitle>
                {detail.resolved ? (
                  <Badge tone="success">Resuelto</Badge>
                ) : detail.is_overdue ? (
                  <Badge tone="danger">Fuera de fecha</Badge>
                ) : (
                  <Badge tone="warning">Abierto</Badge>
                )}
              </div>
              <DialogDescription className="whitespace-pre-wrap">{detail.description}</DialogDescription>
              <p className="text-xs text-muted-foreground">
                {detail.student && `${detail.student.full_name} · `}
                Plazo general: {formatShortDate(detail.due_date)} ·{" "}
                <Link className="underline" to={`/alumnos/${detail.student_id}/seguimiento`}>
                  Ver ficha del alumno
                </Link>
              </p>
              {detail.viewer.left_at && (
                <p className="text-xs text-muted-foreground">
                  Te retiraste de este seguimiento el {formatShortDate(detail.viewer.left_at)}: ves lo ocurrido hasta
                  ese momento.
                </p>
              )}
            </DialogHeader>

            {error && <p className="text-sm text-destructive">{error}</p>}

            <FollowUpStagesSection detail={detail} candidates={candidates} run={run} />
            <ResolveSection detail={detail} run={run} />
            <FollowUpPeopleSection detail={detail} candidates={candidates} run={run} />
            {detail.can.hide && (
              <div>
                <Button type="button" size="sm" variant="outline" onClick={() => run(() => detailApi.hide(detail.id))}>
                  Ocultar de mi bandeja
                </Button>
              </div>
            )}
            <HistorySection detail={detail} />
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}

function errorMessage(err: unknown): string {
  if (isAxiosError(err) && err.response?.status === 403) {
    return "No podés hacer este cambio en el seguimiento."
  }
  if (isAxiosError(err) && err.response?.status === 422) {
    return "Revisá los datos: falta algo o la persona elegida no puede ver al alumno."
  }
  return "No pudimos guardar el cambio."
}

/**
 * Resolving is its own explicit action with a general conclusion (distinct
 * from "Entendido" on a notice), only for general responsibles and only once
 * every stage is closed.
 */
function ResolveSection({ detail, run }: { detail: ScheduledFollowUpDetail; run: RunAction }) {
  const [conclusion, setConclusion] = useState("")
  const pending = detail.stages.some((stage) => stage.status === "pending")

  if (detail.resolved) {
    return (
      <section className="rounded-md bg-muted/50 p-3 text-sm">
        <p className="font-medium">Conclusión general</p>
        <p className="whitespace-pre-wrap">{detail.resolution_note}</p>
        <p className="mt-1 text-xs text-muted-foreground">Resuelto el {formatShortDate(detail.resolved_at)}</p>
      </section>
    )
  }

  if (!detail.viewer.is_general_responsible) return null

  return (
    <section className="grid gap-2 rounded-md border p-3">
      <Label htmlFor="follow-up-conclusion">Conclusión general</Label>
      <Textarea
        id="follow-up-conclusion"
        value={conclusion}
        onChange={(event) => setConclusion(event.target.value)}
        placeholder="Qué se resolvió en este seguimiento…"
      />
      {pending && <p className="text-xs text-muted-foreground">Cerrá todas las etapas para poder resolverlo.</p>}
      <div>
        <Button
          type="button"
          size="sm"
          disabled={!detail.can.resolve || !conclusion.trim()}
          onClick={async () => {
            if (await run(() => detailApi.resolve(detail.id, conclusion.trim()))) setConclusion("")
          }}
        >
          Resolver seguimiento
        </Button>
      </div>
    </section>
  )
}

const eventLabels: Record<string, string> = {
  created: "creó el seguimiento",
  stage_added: "agregó una etapa",
  stage_updated: "editó una etapa",
  stage_split: "subdividió una etapa",
  stage_closed: "cerró una etapa",
  deadline_changed: "cambió el plazo general",
  participant_added: "agregó a un participante",
  participant_removed: "quitó a un participante",
  general_responsible_added: "agregó a un responsable general",
  general_responsible_removed: "quitó a un responsable general",
  withdrew: "se retiró",
  resolved: "resolvió el seguimiento",
  reopened: "reabrió el seguimiento al agregar una etapa pendiente",
}

function eventDetail(event: FollowUpEvent): string {
  const parts: string[] = []
  if (event.type === "deadline_changed" && event.data) {
    parts.push(`de ${formatShortDate(String(event.data.from))} a ${formatShortDate(String(event.data.to))}`)
  }
  if (event.subject_user && event.type !== "withdrew") parts.push(personLabel(event.subject_user))
  if (event.note) {
    const label = event.type === "reopened" ? "conclusión anterior" : event.type === "resolved" ? "conclusión" : "motivo"
    parts.push(`${label}: ${event.note}`)
  }
  return parts.length ? ` — ${parts.join(" · ")}` : ""
}

function HistorySection({ detail }: { detail: ScheduledFollowUpDetail }) {
  return (
    <section className="grid gap-2" aria-labelledby="follow-up-history-heading">
      <h3 id="follow-up-history-heading" className="font-semibold">
        Historial
      </h3>
      <ol className="grid gap-1 text-xs">
        {detail.events.map((event) => (
          <li key={event.id}>
            <span className="text-muted-foreground">{formatShortDate(event.created_at)}</span>{" "}
            {event.actor?.name ?? "Alguien"} {eventLabels[event.type] ?? event.type}
            {eventDetail(event)}
          </li>
        ))}
      </ol>
    </section>
  )
}
