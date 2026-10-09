import { useCallback, useEffect, useState } from "react"
import { CalendarClock } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { EmptyState } from "@/components/ui/empty-state"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { SectionCard } from "@/components/ui/section-card"
import { Textarea } from "@/components/ui/textarea"
import { formatShortDate } from "@/lib/utils"
import { MultiSelect } from "@/components/ui/multi-select"
import { SingleSelect } from "@/components/ui/single-select"
import { roleLabels } from "@/types"
import type { Role, ScheduledFollowUp, StaffMember } from "@/types"
import * as scheduledFollowUpsApi from "./scheduledFollowUpsApi"

interface ScheduledFollowUpsPanelProps {
  studentId: number
}

/**
 * Scheduled follow-ups section embedded in StudentTrackingPage — the concrete
 * view of "nothing expires on its own, someone scheduled it" (docs/prompts/17
 * §Objetivo). Self-contained (same self-fetching pattern as
 * BarrierAccommodationsPanel): it owns its list, the "show resolved" toggle and
 * the new/resolve forms.
 *
 * Authorization is entirely the backend's: ScheduledFollowUpPolicy grants
 * create/view/resolve to any of the three roles related to the student, so
 * there is no client-side helper that would discriminate anything useful (spec
 * §3). The form and the "Resolver" button show whenever the section is visible;
 * a teacher who does not teach the student is rejected with 403 by the
 * Controller.
 */
export function ScheduledFollowUpsPanel({ studentId }: ScheduledFollowUpsPanelProps) {
  const [followUps, setFollowUps] = useState<ScheduledFollowUp[] | null>(null)
  const [showResolved, setShowResolved] = useState(false)
  const [loadError, setLoadError] = useState<string | null>(null)

  // Default: only pending (?resolved=false). When "Mostrar resueltos" is on we
  // drop the query param entirely so the backend returns every follow-up
  // (spec §4) — the toggle literally swaps `resolved=false` for no param.
  const loadFollowUps = useCallback(async () => {
    setLoadError(null)
    try {
      const data = await scheduledFollowUpsApi.fetchScheduledFollowUps(
        studentId,
        showResolved ? undefined : false,
      )
      setFollowUps(data)
    } catch {
      setLoadError("No pudimos cargar los seguimientos programados.")
      setFollowUps([])
    }
  }, [studentId, showResolved])

  useEffect(() => {
    loadFollowUps()
  }, [loadFollowUps])

  function handleCreated(created: ScheduledFollowUp) {
    // Use the returned row, no full refetch (same convention as the rest of
    // StudentTrackingPage). Skip it when it wouldn't be visible under the
    // current filter — a freshly created follow-up is never resolved, so it
    // only belongs in the list while pending ones are shown.
    setFollowUps((prev) => [created, ...(prev ?? [])])
  }

  function handleResolved(updated: ScheduledFollowUp) {
    setFollowUps((prev) => {
      if (!prev) return prev
      // When only pending are shown, drop the now-resolved row; otherwise
      // update it in place with the server's response.
      if (!showResolved) {
        return prev.filter((followUp) => followUp.id !== updated.id)
      }
      return prev.map((followUp) => (followUp.id === updated.id ? updated : followUp))
    })
  }

  return (
    <SectionCard
      title="Seguimientos programados"
      action={
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={showResolved}
            onChange={(event) => setShowResolved(event.target.checked)}
          />
          Mostrar resueltos
        </label>
      }
    >
      <div className="grid gap-4">
      <NewFollowUpForm studentId={studentId} onCreated={handleCreated} />

      {loadError && <p className="text-sm text-destructive">{loadError}</p>}

      {followUps === null ? (
        <p className="text-muted-foreground">Cargando…</p>
      ) : followUps.length === 0 ? (
        <EmptyState
          icon={CalendarClock}
          message={
            showResolved
              ? "Todavía no hay seguimientos programados."
              : "No hay seguimientos pendientes."
          }
        />
      ) : (
        <ul className="grid gap-3">
          {followUps.map((followUp) => (
            <FollowUpRow key={followUp.id} followUp={followUp} onResolved={handleResolved} />
          ))}
        </ul>
      )}
      </div>
    </SectionCard>
  )
}

function NewFollowUpForm({
  studentId,
  onCreated,
}: {
  studentId: number
  onCreated: (created: ScheduledFollowUp) => void
}) {
  const [description, setDescription] = useState("")
  const [dueDate, setDueDate] = useState("")
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  // Responsible person: by default the creator, nothing is sent. "Asignar a
  // otra persona" loads the candidates (people who already see the student),
  // filters them by role and then picks one; "Compartir con" picks more.
  const [assigning, setAssigning] = useState(false)
  const [candidates, setCandidates] = useState<StaffMember[]>([])
  const [roleFilter, setRoleFilter] = useState<string>("")
  const [assigneeId, setAssigneeId] = useState<string>("")
  const [sharedIds, setSharedIds] = useState<number[]>([])

  async function startAssigning() {
    setAssigning(true)
    try {
      setCandidates(await scheduledFollowUpsApi.fetchFollowUpCandidates(studentId))
    } catch {
      setError("No pudimos cargar a las personas disponibles.")
    }
  }

  function stopAssigning() {
    setAssigning(false)
    setRoleFilter("")
    setAssigneeId("")
    setSharedIds([])
  }

  const roleOptions = (Object.keys(roleLabels) as Role[]).map((role) => ({
    value: role,
    label: roleLabels[role],
  }))
  const personOptions = candidates
    .filter((person) => !roleFilter || person.role === roleFilter)
    .map((person) => ({ value: String(person.id), label: person.name }))
  const sharedOptions = candidates
    .filter((person) => String(person.id) !== assigneeId)
    .map((person) => ({
      id: person.id,
      label: `${person.name}${person.role ? ` (${roleLabels[person.role]})` : ""}`,
    }))

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault()
    setError(null)

    const trimmed = description.trim()
    if (!trimmed) {
      setError("Escribí una descripción.")
      return
    }
    if (!dueDate) {
      setError("Elegí una fecha límite.")
      return
    }

    setSubmitting(true)
    try {
      const shared = sharedIds.filter((id) => String(id) !== assigneeId)
      const created = await scheduledFollowUpsApi.createScheduledFollowUp(studentId, {
        description: trimmed,
        due_date: dueDate,
        ...(assigneeId ? { assigned_to_id: Number(assigneeId) } : {}),
        ...(shared.length > 0 ? { shared_with_ids: shared } : {}),
      })
      onCreated(created)
      setDescription("")
      setDueDate("")
      stopAssigning()
    } catch {
      setError("No pudimos guardar el seguimiento.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} className="grid gap-3 rounded-md border p-4">
      <div className="grid gap-1.5">
        <Label htmlFor="follow-up-description">Nuevo seguimiento</Label>
        <Textarea
          id="follow-up-description"
          value={description}
          onChange={(event) => setDescription(event.target.value)}
          placeholder="Qué hay que hacer y por qué…"
        />
      </div>

      <div className="grid gap-1.5">
        <Label htmlFor="follow-up-due-date">Fecha límite</Label>
        <Input
          id="follow-up-due-date"
          type="date"
          value={dueDate}
          onChange={(event) => setDueDate(event.target.value)}
          className="max-w-48"
        />
      </div>

      {assigning ? (
        <div className="grid gap-3 rounded-md border p-3">
          <div className="flex flex-wrap gap-3">
            <div className="grid gap-1.5">
              <Label htmlFor="follow-up-role">Rol</Label>
              <SingleSelect
                id="follow-up-role"
                options={roleOptions}
                value={roleFilter}
                onChange={(value) => {
                  setRoleFilter(value)
                  setAssigneeId("")
                }}
                placeholder="Todos los roles"
                className="w-48"
              />
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="follow-up-assignee">Responsable</Label>
              <SingleSelect
                id="follow-up-assignee"
                options={personOptions}
                value={assigneeId}
                onChange={setAssigneeId}
                placeholder="Elegí una persona"
                className="w-56"
              />
            </div>
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="follow-up-shared">Compartir con</Label>
            <MultiSelect
              id="follow-up-shared"
              options={sharedOptions}
              selected={sharedIds}
              onChange={setSharedIds}
              placeholder="Nadie más"
            />
          </div>
          <div>
            <Button type="button" size="sm" variant="outline" onClick={stopAssigning}>
              Quedarme como responsable
            </Button>
          </div>
        </div>
      ) : (
        <div>
          <Button type="button" size="sm" variant="outline" onClick={startAssigning}>
            Asignar a otra persona o compartir
          </Button>
        </div>
      )}

      {error && <p className="text-sm text-destructive">{error}</p>}

      <div>
        <Button type="submit" disabled={submitting}>
          {submitting ? "Guardando…" : "Programar seguimiento"}
        </Button>
      </div>
    </form>
  )
}

function FollowUpRow({
  followUp,
  onResolved,
}: {
  followUp: ScheduledFollowUp
  onResolved: (updated: ScheduledFollowUp) => void
}) {
  const [resolving, setResolving] = useState(false)
  const [note, setNote] = useState("")
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function handleConfirm() {
    setError(null)
    setSubmitting(true)
    try {
      const trimmed = note.trim()
      // Never send resolution_note: "" — pass undefined so the field is omitted
      // when the user leaves it empty (spec §6).
      const updated = await scheduledFollowUpsApi.resolveScheduledFollowUp(
        followUp.id,
        trimmed ? trimmed : undefined,
      )
      onResolved(updated)
    } catch {
      setError("No pudimos resolver el seguimiento.")
      setSubmitting(false)
    }
  }

  return (
    <li className="grid gap-2 rounded-md border p-3 text-sm">
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="whitespace-pre-wrap">{followUp.description}</p>
          <p className="mt-1 text-xs text-muted-foreground">
            Vence: {formatShortDate(followUp.due_date)}
          </p>
          {followUp.assigned_to && (
            <p className="text-xs text-muted-foreground">
              Responsable: {followUp.assigned_to.name}
              {followUp.assigned_to.role && ` (${roleLabels[followUp.assigned_to.role]})`}
            </p>
          )}
          {followUp.shared_with && followUp.shared_with.length > 0 && (
            <p className="text-xs text-muted-foreground">
              Compartido con: {followUp.shared_with.map((person) => person.name).join(", ")}
            </p>
          )}
        </div>
        {/* Painted straight from the server boolean — the component never does
            date arithmetic (spec §4). */}
        {followUp.is_overdue && <Badge tone="danger">Vencido</Badge>}
      </div>

      {followUp.resolved ? (
        <div className="rounded-md bg-muted/50 p-2 text-xs text-muted-foreground">
          <p>
            Resuelto el {formatShortDate(followUp.resolved_at)}
            {followUp.resolved_by_id !== null && ` por Usuario #${followUp.resolved_by_id}`}
          </p>
          {followUp.resolution_note && (
            <p className="mt-1 whitespace-pre-wrap">Nota: {followUp.resolution_note}</p>
          )}
        </div>
      ) : resolving ? (
        <div className="grid gap-2 border-t pt-2">
          <Label htmlFor={`resolution-note-${followUp.id}`} className="text-xs">
            Nota de resolución (opcional)
          </Label>
          <Textarea
            id={`resolution-note-${followUp.id}`}
            value={note}
            onChange={(event) => setNote(event.target.value)}
            placeholder="Qué se hizo (opcional)…"
          />
          {error && <p className="text-sm text-destructive">{error}</p>}
          <div className="flex gap-2">
            <Button type="button" size="sm" disabled={submitting} onClick={handleConfirm}>
              {submitting ? "Resolviendo…" : "Confirmar"}
            </Button>
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={submitting}
              onClick={() => {
                setResolving(false)
                setNote("")
                setError(null)
              }}
            >
              Cancelar
            </Button>
          </div>
        </div>
      ) : (
        <div>
          <Button type="button" size="sm" variant="outline" onClick={() => setResolving(true)}>
            Resolver
          </Button>
        </div>
      )}
    </li>
  )
}
