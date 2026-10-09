import { useEffect, useState, type FormEvent } from "react"
import { Link } from "react-router-dom"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { SingleSelect } from "@/components/ui/single-select"
import { Textarea } from "@/components/ui/textarea"
import { formatShortDate } from "@/lib/utils"
import {
  alertOutcomeActionLabels,
  alertOutcomeLabels,
  alertTypeLabels,
  roleLabels,
  type Alert,
  type AlertOutcome,
} from "@/types"
import * as alertsApi from "./alertsApi"
import type { HandoffCandidate } from "./alertsApi"

const OUTCOMES: AlertOutcome[] = ["owned", "handed_off", "observing"]

/** Suggested deadlines, so answering an alert is one tap (ClickUp 86e3jpzdp). */
const DEADLINE_SUGGESTIONS = [
  { label: "En una semana", days: 7 },
  { label: "En dos semanas", days: 14 },
  { label: "En un mes", days: 30 },
]

function isoInDays(days: number): string {
  const date = new Date()
  date.setDate(date.getDate() + days)
  const month = String(date.getMonth() + 1).padStart(2, "0")
  const day = String(date.getDate()).padStart(2, "0")
  return `${date.getFullYear()}-${month}-${day}`
}

function OutcomeForm({
  alert,
  outcome,
  onDone,
  onCancel,
}: {
  alert: Alert
  outcome: AlertOutcome
  onDone: (updated: Alert) => void
  onCancel: () => void
}) {
  const [dueOn, setDueOn] = useState(isoInDays(DEADLINE_SUGGESTIONS[0].days))
  const [assigneeId, setAssigneeId] = useState("")
  const [note, setNote] = useState("")
  const [candidates, setCandidates] = useState<HandoffCandidate[] | null>(null)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const handOff = outcome === "handed_off"

  useEffect(() => {
    if (!handOff) return
    alertsApi
      .fetchHandoffCandidates(alert.id)
      .then(setCandidates)
      .catch(() => setError("No pudimos cargar a quién se la podés pasar."))
  }, [alert.id, handOff])

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (handOff && !assigneeId) {
      setError("Elegí a quién se la pasás.")
      return
    }
    setError(null)
    setSaving(true)
    try {
      const updated = await alertsApi.chooseAlertOutcome(alert.id, {
        outcome,
        due_on: dueOn,
        assignee_id: handOff ? Number(assigneeId) : null,
        note: note.trim() ? note.trim() : null,
      })
      onDone(updated)
    } catch {
      setError("No pudimos guardar la salida. Revisá el plazo.")
    } finally {
      setSaving(false)
    }
  }

  const candidateLabel = (candidate: HandoffCandidate) =>
    candidate.roles.length > 0
      ? `${candidate.name} · ${candidate.roles.map((role) => roleLabels[role] ?? role).join(", ")}`
      : candidate.name

  return (
    <form onSubmit={submit} className="mt-3 grid gap-3 rounded-md bg-muted/40 p-3" noValidate>
      <p className="text-sm font-medium">{alertOutcomeActionLabels[outcome]}</p>
      {handOff && (
        <div className="grid gap-2">
          <Label htmlFor={`alert-${alert.id}-assignee`}>¿A quién se la pasás?</Label>
          <SingleSelect
            id={`alert-${alert.id}-assignee`}
            value={assigneeId}
            onChange={setAssigneeId}
            placeholder="Elegí una persona…"
            options={(candidates ?? []).map((candidate) => ({
              value: String(candidate.id),
              label: candidateLabel(candidate),
            }))}
          />
          <p className="text-xs text-muted-foreground">Desde ese momento la ve y figura como responsable.</p>
        </div>
      )}
      <div className="grid gap-2">
        <Label htmlFor={`alert-${alert.id}-due`}>Plazo</Label>
        <div className="flex flex-wrap gap-2">
          {DEADLINE_SUGGESTIONS.map((suggestion) => {
            const value = isoInDays(suggestion.days)
            return (
              <Button
                key={suggestion.days}
                type="button"
                size="xs"
                variant={dueOn === value ? "default" : "outline"}
                onClick={() => setDueOn(value)}
              >
                {suggestion.label}
              </Button>
            )
          })}
        </div>
        <Input
          id={`alert-${alert.id}-due`}
          type="date"
          value={dueOn}
          min={isoInDays(0)}
          onChange={(event) => setDueOn(event.target.value)}
          className="w-fit"
        />
      </div>
      <div className="grid gap-2">
        <Label htmlFor={`alert-${alert.id}-note`}>
          {handOff ? "Nota para quien la recibe (opcional)" : "Nota (opcional)"}
        </Label>
        <Textarea
          id={`alert-${alert.id}-note`}
          value={note}
          maxLength={2000}
          onChange={(event) => setNote(event.target.value)}
          placeholder="Qué observaste o qué hiciste — nunca un diagnóstico."
        />
      </div>
      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
      <div className="flex gap-2">
        <Button type="submit" size="sm" disabled={saving}>
          {saving ? "Guardando…" : "Confirmar"}
        </Button>
        <Button type="button" size="sm" variant="outline" onClick={onCancel}>
          Cancelar
        </Button>
      </div>
    </form>
  )
}

function statusLine(alert: Alert): string | null {
  if (!alert.outcome || !alert.assignee) return null
  const since = formatShortDate(alert.outcome_at)
  const due = formatShortDate(alert.due_on)
  if (alert.outcome === "observing") {
    return `En observación: la tiene ${alert.assignee.name} desde el ${since}, para mirar de nuevo el ${due}.`
  }
  return `La tiene ${alert.assignee.name} desde el ${since} · plazo al ${due}.`
}

/**
 * One alert with its three ways out (ClickUp 86e3jpzdp; documento vivo screen
 * 11): "me ocupo yo", "se la paso a otro rol", "la dejo en observación", each
 * with a responsible person and a suggested deadline. Whether the buttons
 * show comes from `alert.can`, decided server-side by `AlertPolicy`.
 */
export function AlertCard({ alert, onChange }: { alert: Alert; onChange: (updated: Alert) => void }) {
  const [choosing, setChoosing] = useState<AlertOutcome | null>(null)
  const [error, setError] = useState<string | null>(null)
  const status = statusLine(alert)
  const escalated = alert.type === "escalated_overdue"

  async function resolve() {
    setError(null)
    try {
      onChange(await alertsApi.resolveAlert(alert.id))
    } catch {
      setError("No pudimos marcarla como resuelta.")
    }
  }

  return (
    <article
      className={`rounded-md border border-l-4 p-4 ${escalated || alert.is_overdue ? "border-l-destructive" : "border-l-warning"}`}
    >
      <div className="flex flex-wrap items-center gap-2">
        {alert.student_name ? (
          <Link to={`/alumnos/${alert.student_id}/seguimiento`} className="font-medium hover:underline">
            {alert.student_name}
          </Link>
        ) : null}
        <span className="text-sm text-muted-foreground">
          {escalated
            ? alertTypeLabels[alert.type]
            : alert.type === "performance"
              ? `Desempeño bajo sostenido${alert.subject_name ? ` — ${alert.subject_name}` : ""}`
              : alertTypeLabels[alert.type]}
          {alert.condition_met_on && !escalated && ` — desde el ${formatShortDate(alert.condition_met_on)}`}
        </span>
        {alert.outcome ? (
          <Badge tone="info">{alertOutcomeLabels[alert.outcome]}</Badge>
        ) : (
          !escalated && <Badge tone="warning">Sin salida elegida</Badge>
        )}
        {alert.is_overdue && <Badge tone="danger">Plazo vencido</Badge>}
      </div>
      <p className="mt-2 text-sm">{alert.description}</p>
      {status && <p className="mt-1 text-sm text-muted-foreground">{status}</p>}

      {alert.actions && alert.actions.some((action) => action.note) && (
        <ul className="mt-3 grid gap-2">
          {alert.actions
            .filter((action) => action.note)
            .map((action) => (
              <li key={action.id} className="rounded-md bg-muted/40 p-2 text-sm">
                <p className="text-xs text-muted-foreground">
                  <span className="font-medium text-foreground">{action.actor.name}</span> ·{" "}
                  {formatShortDate(action.created_at)}
                </p>
                <p>{action.note}</p>
              </li>
            ))}
        </ul>
      )}

      {alert.can?.act && choosing === null && (
        <div className="mt-3 flex flex-wrap gap-2">
          {OUTCOMES.map((outcome, index) => (
            <Button
              key={outcome}
              type="button"
              size="sm"
              variant={index === 0 ? "default" : "outline"}
              onClick={() => setChoosing(outcome)}
            >
              {alertOutcomeActionLabels[outcome]}
            </Button>
          ))}
        </div>
      )}
      {choosing && (
        <OutcomeForm
          alert={alert}
          outcome={choosing}
          onCancel={() => setChoosing(null)}
          onDone={(updated) => {
            setChoosing(null)
            onChange(updated)
          }}
        />
      )}
      {alert.can?.resolve && choosing === null && (
        <div className="mt-3">
          <Button type="button" size="sm" variant="ghost" onClick={resolve}>
            Marcar como resuelta
          </Button>
        </div>
      )}
      {error && (
        <p role="alert" className="mt-2 text-sm text-destructive">
          {error}
        </p>
      )}
    </article>
  )
}
