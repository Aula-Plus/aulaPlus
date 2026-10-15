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
  type AlertActionEntry,
  type AlertFollowUpLink,
  type AlertOutcome,
} from "@/types"
import { NewFollowUpForm } from "@/features/tracking/ScheduledFollowUpsPanel"
import { alertHolderPhrase } from "./alertHolder"
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

function candidateLabel(candidate: HandoffCandidate): string {
  return candidate.roles.length > 0
    ? `${candidate.name} · ${candidate.roles.map((role) => roleLabels[role] ?? role).join(", ")}`
    : candidate.name
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
  const holder = alertHolderPhrase(alert)
  if (!holder) return null
  const due = formatShortDate(alert.due_on)
  if (alert.outcome === "observing") {
    return `En observación: ${holder}, para mirar de nuevo el ${due}.`
  }
  return `${holder.charAt(0).toUpperCase()}${holder.slice(1)} · plazo al ${due}.`
}

function NoteForm({
  alert,
  onDone,
  onCancel,
}: {
  alert: Alert
  onDone: (updated: Alert) => void
  onCancel: () => void
}) {
  const [note, setNote] = useState("")
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!note.trim()) {
      setError("Escribí la nota.")
      return
    }
    setError(null)
    setSaving(true)
    try {
      onDone(await alertsApi.addAlertNote(alert.id, note.trim()))
    } catch {
      setError("No pudimos guardar la nota.")
    } finally {
      setSaving(false)
    }
  }

  return (
    <form onSubmit={submit} className="mt-3 grid gap-3 rounded-md bg-muted/40 p-3" noValidate>
      <div className="grid gap-2">
        <Label htmlFor={`alert-${alert.id}-thread-note`}>Nota</Label>
        <Textarea
          id={`alert-${alert.id}-thread-note`}
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
          {saving ? "Guardando…" : "Guardar nota"}
        </Button>
        <Button type="button" size="sm" variant="outline" onClick={onCancel}>
          Cancelar
        </Button>
      </div>
    </form>
  )
}

/** One step of the thread, in words: who did what, with which deadline. */
function threadHeadline(action: AlertActionEntry): string {
  const due = action.due_on ? formatShortDate(action.due_on) : null
  if (action.kind === "overdue") {
    const holder = action.assignee?.name ? `, la tenía ${action.assignee.name}` : ""
    return `Llegó a su fecha sin salida${due ? ` (plazo al ${due}${holder})` : ""}`
  }
  const actor = action.actor?.name ?? "Alguien del equipo"
  if (action.kind === "note") return actor
  switch (action.outcome) {
    case "owned":
      return `${actor} se ocupa${due ? `, plazo al ${due}` : ""}`
    case "handed_off":
      return `${actor} se la pasó a ${action.assignee?.name ?? "otra persona"}${due ? `, plazo al ${due}` : ""}`
    case "observing":
      return `${actor} la dejó en observación${due ? ` hasta el ${due}` : ""}`
    default:
      return actor
  }
}

function followUpState(link: AlertFollowUpLink): string {
  if (link.resolved) return "Resuelto"
  if (link.is_overdue) return "Vencido"
  return "Pendiente"
}

type Panel = AlertOutcome | "note" | "follow-up" | null

/**
 * One alert with its three ways out (ClickUp 86e3jpzdp; documento vivo screen
 * 11): "me ocupo yo", "se la paso a otro rol", "la dejo en observación", each
 * with a responsible person and a suggested deadline. Once someone has it,
 * they leave notes in its thread. The alert itself is never shared (review of
 * 14/10): working the case with several people is done on a follow-up linked
 * to it («Crear seguimiento vinculado»), which keeps its own state and
 * deadline. A deadline that passes with the alert pending marks this same
 * alert «Llegó a su fecha sin salida». Whether the buttons show comes from
 * `alert.can`, decided server-side by `AlertPolicy`.
 */
export function AlertCard({ alert, onChange }: { alert: Alert; onChange: (updated: Alert) => void }) {
  const [choosing, setChoosing] = useState<Panel>(null)
  const [error, setError] = useState<string | null>(null)
  const status = statusLine(alert)
  // Legacy escalated alerts (no longer created) may still be open.
  const escalated = alert.type === "escalated_overdue"
  const overdue = Boolean(alert.overdue_at) || Boolean(alert.is_overdue)
  const thread = alert.actions ?? []
  const followUps = alert.follow_ups ?? []

  function closePanel() {
    setChoosing(null)
  }

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
      className={`rounded-md border border-l-4 p-4 ${escalated || overdue ? "border-l-destructive" : "border-l-warning"}`}
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
        {overdue && <Badge tone="danger">Llegó a su fecha sin salida</Badge>}
      </div>
      <p className="mt-2 text-sm">{alert.description}</p>
      {status && <p className="mt-1 text-sm text-muted-foreground">{status}</p>}

      {thread.length > 0 && (
        <ul className="mt-3 grid gap-2">
          {thread.map((action) => (
            <li key={action.id} className="rounded-md bg-muted/40 p-2 text-sm">
              <p
                className={`text-xs ${action.kind === "overdue" ? "font-medium text-destructive" : "text-muted-foreground"}`}
              >
                {threadHeadline(action)} · {formatShortDate(action.created_at)}
              </p>
              {action.note && <p>{action.note}</p>}
            </li>
          ))}
        </ul>
      )}

      {followUps.length > 0 && (
        <div className="mt-3 grid gap-1 text-sm">
          <p className="text-xs font-medium text-muted-foreground">Seguimientos vinculados</p>
          <ul className="grid gap-1">
            {followUps.map((link) => (
              <li key={link.id} className="flex flex-wrap items-center gap-2 text-xs">
                <Link to={`/alumnos/${alert.student_id}/seguimiento`} className="hover:underline">
                  Seguimiento · vence el {formatShortDate(link.due_date)}
                </Link>
                <Badge tone={link.resolved ? "success" : link.is_overdue ? "danger" : "info"}>
                  {followUpState(link)}
                </Badge>
              </li>
            ))}
          </ul>
        </div>
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
      {choosing && choosing !== "note" && choosing !== "follow-up" && (
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
      {choosing === "note" && (
        <NoteForm
          alert={alert}
          onCancel={() => setChoosing(null)}
          onDone={(updated) => {
            setChoosing(null)
            onChange(updated)
          }}
        />
      )}
      {choosing === "follow-up" && (
        <div className="mt-3">
          <NewFollowUpForm
            studentId={alert.student_id}
            alertId={alert.id}
            idPrefix={`alert-${alert.id}-follow-up`}
            title="Seguimiento vinculado a esta alerta"
            onCancel={closePanel}
            onCreated={(created) => {
              closePanel()
              // The alert itself does not change: only its list of links.
              onChange({
                ...alert,
                follow_ups: [
                  ...followUps,
                  {
                    id: created.id,
                    due_date: created.due_date,
                    resolved: created.resolved,
                    is_overdue: created.is_overdue,
                  },
                ],
              })
            }}
          />
        </div>
      )}
      {choosing === null && (alert.can?.note || alert.can?.resolve || alert.can?.link_follow_up) && (
        <div className="mt-3 flex flex-wrap gap-2">
          {alert.can?.link_follow_up && (
            <Button type="button" size="sm" variant="outline" onClick={() => setChoosing("follow-up")}>
              Crear seguimiento vinculado
            </Button>
          )}
          {alert.can?.note && (
            <Button type="button" size="sm" variant="outline" onClick={() => setChoosing("note")}>
              Dejar una nota
            </Button>
          )}
          {alert.can?.resolve && (
            <Button type="button" size="sm" variant="ghost" onClick={resolve}>
              Marcar como resuelta
            </Button>
          )}
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
