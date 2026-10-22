import { useCallback, useEffect, useState } from "react"
import { isAxiosError } from "axios"
import { Badge, type BadgeTone } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { SectionCard } from "@/components/ui/section-card"
import { Textarea } from "@/components/ui/textarea"
import { useAuth } from "@/features/auth/AuthContext"
import { canReviewAccommodationProposals, createsAccommodationProposals } from "@/lib/permissions"
import { formatShortDate } from "@/lib/utils"
import {
  accommodationCategoryLabels,
  accommodationFieldLabels,
  accommodationReviewActionLabels,
  accommodationReviewStatusLabels,
  roleLabels,
  type Accommodation,
  type AccommodationReviewEvent,
  type AccommodationReviewStatus,
} from "@/types"
import { describeAccommodationScope } from "./accommodationScope"
import * as trackingApi from "./trackingApi"
import type { AccommodationProposalStep } from "./trackingApi"

const statusTone: Record<AccommodationReviewStatus, BadgeTone> = {
  pending: "warning",
  returned: "info",
  confirmed: "success",
  withdrawn: "neutral",
  rejected: "danger",
}

interface AccommodationProposalsSectionProps {
  studentId: number
  /** Bumped by the parent after a proposal was created or edited, to refetch. */
  refreshKey: number
  /** Opens the shared accommodation form on this proposal. */
  onEdit: (proposal: Accommodation) => void
  /** After psychopedagogy confirmed one: it is in force, the parent refetches. */
  onConfirmed: () => void
}

/**
 * «Propuestas de ajuste» in the student file's Ajustes tab (ClickUp
 * 86e3qkwkk), for direction and psychopedagogy only: the direction proposals
 * not confirmed yet — open ones (pending / returned for corrections) first,
 * then the closed ones (withdrawn / rejected) kept for the record. A confirmed
 * proposal leaves this list and shows among «Ajustes vigentes».
 *
 * Direction edits, resubmits or withdraws (with a reason) its proposals;
 * psychopedagogy edits, confirms («Vigente desde»), returns them with a
 * comment or rejects them (mandatory reason). Which buttons show is UX only:
 * AccommodationPolicy decides on the server, and a step the state no longer
 * allows comes back as 422.
 */
export function AccommodationProposalsSection({
  studentId,
  refreshKey,
  onEdit,
  onConfirmed,
}: AccommodationProposalsSectionProps) {
  const [proposals, setProposals] = useState<Accommodation[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  // Bumped on every fetch: each row remounts with the fresh data, so an open
  // history or step form never shows what the proposal was before.
  const [version, setVersion] = useState(0)

  const load = useCallback(() => {
    trackingApi
      .fetchAccommodationProposals(studentId)
      .then((data) => {
        setProposals(data)
        setVersion((current) => current + 1)
        setError(null)
      })
      .catch(() => setError("No pudimos cargar las propuestas de ajuste."))
  }, [studentId])

  useEffect(() => {
    load()
  }, [load, refreshKey])

  function handleStepDone(updated: Accommodation) {
    if (updated.review_status === "confirmed") {
      onConfirmed()
    }
    load()
  }

  if (error) {
    return (
      <SectionCard title="Propuestas de ajuste">
        <p className="text-sm text-destructive">{error}</p>
      </SectionCard>
    )
  }

  // Nothing to show until there is a proposal: the section is not an empty state.
  if (!proposals || proposals.length === 0) {
    return null
  }

  return (
    <SectionCard title="Propuestas de ajuste">
      <ul className="grid gap-2">
        {proposals.map((proposal) => (
          <ProposalItem
            key={`${proposal.id}:${version}`}
            proposal={proposal}
            onEdit={onEdit}
            onStepDone={handleStepDone}
            onStale={load}
          />
        ))}
      </ul>
    </SectionCard>
  )
}

type OpenForm = null | "confirm" | "return" | "resubmit" | "withdraw" | "reject"

/** Copy of each step's inline form. */
const STEP_FORMS: Record<
  Exclude<OpenForm, null>,
  { label: string; field: "note" | "reason" | "starts_on"; required: boolean; submit: string }
> = {
  confirm: { label: "Vigente desde", field: "starts_on", required: true, submit: "Confirmar ajuste" },
  return: {
    label: "Comentario o contrapropuesta para Dirección",
    field: "note",
    required: true,
    submit: "Devolver para corregir",
  },
  resubmit: {
    label: "Respuesta para Psicopedagogía (opcional)",
    field: "note",
    required: false,
    submit: "Reenviar a revisión",
  },
  withdraw: { label: "Motivo del retiro", field: "reason", required: true, submit: "Retirar propuesta" },
  reject: { label: "Motivo del rechazo", field: "reason", required: true, submit: "Rechazar propuesta" },
}

function capitalizeFirst(text: string): string {
  return text.charAt(0).toUpperCase() + text.slice(1)
}

/** Today as a local `YYYY-MM-DD`. */
function todayIso(): string {
  const now = new Date()
  const pad = (value: number) => String(value).padStart(2, "0")
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}

function ProposalItem({
  proposal,
  onEdit,
  onStepDone,
  onStale,
}: {
  proposal: Accommodation
  onEdit: (proposal: Accommodation) => void
  onStepDone: (updated: Accommodation) => void
  /** Someone else moved the proposal meanwhile: refetch the list. */
  onStale: () => void
}) {
  const { user } = useAuth()
  const reviews = canReviewAccommodationProposals(user)
  const proposes = createsAccommodationProposals(user)
  const status = proposal.review_status
  const isOpen = status === "pending" || status === "returned"
  const [openForm, setOpenForm] = useState<OpenForm>(null)
  const [value, setValue] = useState("")
  // «Origen», filled in on confirming (ClickUp 86e3qkwkk): prefilled with who
  // proposed it, editable.
  const [origin, setOrigin] = useState("")
  const [submitting, setSubmitting] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const [history, setHistory] = useState<AccommodationReviewEvent[] | null>(null)
  const [historyOpen, setHistoryOpen] = useState(false)
  const [historyError, setHistoryError] = useState<string | null>(null)

  function open(form: Exclude<OpenForm, null>) {
    setOpenForm(form)
    setValue(form === "confirm" ? todayIso() : "")
    setOrigin(
      proposal.origin ?? (proposal.proposed_by_name ? `Propuesta de ${proposal.proposed_by_name}` : ""),
    )
    setFormError(null)
  }

  async function submit() {
    if (!openForm) return
    const config = STEP_FORMS[openForm]
    const trimmed = value.trim()
    if (config.required && !trimmed) {
      setFormError(config.field === "starts_on" ? "Elegí desde cuándo rige." : "Completá este campo.")
      return
    }
    setSubmitting(true)
    setFormError(null)
    try {
      const updated = await trackingApi.reviewAccommodationProposal(
        proposal.id,
        openForm as AccommodationProposalStep,
        {
          ...(trimmed ? { [config.field]: trimmed } : {}),
          ...(openForm === "confirm" ? { origin: origin.trim() || undefined } : {}),
        },
      )
      setOpenForm(null)
      setHistory(null)
      setHistoryOpen(false)
      onStepDone(updated)
    } catch (err) {
      // 422 on the proposal itself (not a field): someone else already took
      // a step on it. Show where it stands now instead of a stale card.
      if (isAxiosError(err) && err.response?.status === 422 && err.response.data?.errors?.accommodation) {
        onStale()
        return
      }
      setFormError("No pudimos completar la acción. Volvé a cargar la ficha y probá de nuevo.")
    } finally {
      setSubmitting(false)
    }
  }

  async function toggleHistory() {
    if (historyOpen) {
      setHistoryOpen(false)
      return
    }
    setHistoryOpen(true)
    if (history) return
    try {
      setHistory(await trackingApi.fetchAccommodationProposalHistory(proposal.id))
      setHistoryError(null)
    } catch {
      setHistoryError("No pudimos cargar el historial.")
    }
  }

  const noteLabel =
    status === "returned"
      ? "Comentario de Psicopedagogía"
      : status === "withdrawn"
        ? "Motivo del retiro"
        : "Motivo del rechazo"

  return (
    <li className="grid gap-2 rounded-md border p-3 text-sm">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <span className="font-medium">{proposal.type}</span>
          {proposal.category && (
            <Badge tone="neutral" className="ml-2">
              {accommodationCategoryLabels[proposal.category]}
            </Badge>
          )}
          {proposal.description && <p className="text-muted-foreground">{proposal.description}</p>}
          <p className="text-muted-foreground">{capitalizeFirst(describeAccommodationScope(proposal))}</p>
        </div>
        {status && <Badge tone={statusTone[status]}>{accommodationReviewStatusLabels[status]}</Badge>}
      </div>

      {proposal.review_note && (
        <p className="rounded-md bg-muted/50 p-2 whitespace-pre-wrap">
          <span className="font-medium">{noteLabel}:</span> {proposal.review_note}
        </p>
      )}
      {status === "returned" && proposes && (
        <p className="text-xs text-muted-foreground">Le toca responder a Dirección.</p>
      )}

      {isOpen && openForm === null && (
        <div className="flex flex-wrap gap-2">
          {(reviews || proposes) && (
            <Button type="button" size="sm" variant="outline" onClick={() => onEdit(proposal)}>
              {proposes ? "Editar propuesta" : "Editar"}
            </Button>
          )}
          {reviews && (
            <>
              <Button type="button" size="sm" onClick={() => open("confirm")}>
                Confirmar
              </Button>
              {status === "pending" && (
                <Button type="button" size="sm" variant="outline" onClick={() => open("return")}>
                  Devolver para corregir
                </Button>
              )}
              <Button type="button" size="sm" variant="outline" onClick={() => open("reject")}>
                Rechazar
              </Button>
            </>
          )}
          {proposes && (
            <>
              {status === "returned" && (
                <Button type="button" size="sm" onClick={() => open("resubmit")}>
                  Reenviar a revisión
                </Button>
              )}
              <Button type="button" size="sm" variant="outline" onClick={() => open("withdraw")}>
                Retirar propuesta
              </Button>
            </>
          )}
        </div>
      )}

      {openForm && (
        <div className="grid gap-2 border-t pt-2">
          <Label htmlFor={`proposal-${openForm}-${proposal.id}`} className="text-xs">
            {STEP_FORMS[openForm].label}
          </Label>
          {openForm === "confirm" ? (
            <Input
              id={`proposal-${openForm}-${proposal.id}`}
              type="date"
              value={value}
              onChange={(event) => setValue(event.target.value)}
            />
          ) : (
            <Textarea
              id={`proposal-${openForm}-${proposal.id}`}
              value={value}
              onChange={(event) => setValue(event.target.value)}
            />
          )}
          {openForm === "confirm" && (
            <>
              <Label htmlFor={`proposal-origin-${proposal.id}`} className="text-xs">
                Origen
              </Label>
              <Input
                id={`proposal-origin-${proposal.id}`}
                value={origin}
                maxLength={255}
                onChange={(event) => setOrigin(event.target.value)}
              />
              <p className="text-xs text-muted-foreground">
                Al confirmarla, el ajuste queda vigente y llega a los docentes que corresponda. El
                origen lo ven Psicopedagogía y Dirección, nunca el docente.
              </p>
            </>
          )}
          {formError && (
            <p role="alert" className="text-sm text-destructive">
              {formError}
            </p>
          )}
          <div className="flex gap-2">
            <Button type="button" size="sm" disabled={submitting} onClick={submit}>
              {submitting ? "Enviando…" : STEP_FORMS[openForm].submit}
            </Button>
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={submitting}
              onClick={() => setOpenForm(null)}
            >
              Cancelar
            </Button>
          </div>
        </div>
      )}

      <div>
        <Button type="button" size="sm" variant="ghost" onClick={toggleHistory} aria-expanded={historyOpen}>
          {historyOpen ? "Ocultar historial" : "Ver historial"}
        </Button>
        {historyOpen &&
          (historyError ? (
            <p className="text-sm text-destructive">{historyError}</p>
          ) : history === null ? (
            <p className="text-muted-foreground">Cargando…</p>
          ) : (
            <ol className="mt-1 grid gap-1 text-xs text-muted-foreground">
              {history.map((event) => (
                <li key={event.id}>
                  {formatShortDate(event.created_at)} ·{" "}
                  {event.user
                    ? `${event.user.name}${event.user.role ? ` (${roleLabels[event.user.role]})` : ""}`
                    : "Usuario eliminado"}
                  : {accommodationReviewActionLabels[event.action]}
                  {event.changed_fields && event.changed_fields.length > 0 && (
                    <>
                      {" "}
                      ({event.changed_fields.map((field) => accommodationFieldLabels[field] ?? field).join(", ")})
                    </>
                  )}
                  {event.note && <span className="whitespace-pre-wrap"> — «{event.note}»</span>}
                </li>
              ))}
            </ol>
          ))}
      </div>
    </li>
  )
}
