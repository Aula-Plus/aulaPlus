import { useCallback, useEffect, useRef, useState } from "react"
import { Link, useLocation, useNavigate, useParams } from "react-router-dom"
import { Check, Pencil, RefreshCw, X } from "lucide-react"

import { PageHeader } from "@/components/ui/page-header"
import { SectionCard } from "@/components/ui/section-card"
import { Badge, type BadgeTone } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { ConfirmDialog } from "@/components/ui/confirm-dialog"
import { formatShortDate } from "@/lib/utils"
import { WhyBlock } from "./WhyBlock"
import {
  classPath,
  classSectionKeys,
  classSectionLabels,
  confirmClassProposal,
  discardProposal,
  fetchProposal,
  newClassPath,
  proposalPath,
  requestClassProposal,
  requestErrorMessage,
  type ClassDraft,
  type ClassProposal,
  type ClassRequestParameters,
  type ClassSectionKey,
  type SectionDecision,
} from "./classesApi"

/** How often a pending proposal is polled, in ms. */
const POLL_INTERVAL_MS = 2000

/** The teacher's decision on one section while reviewing the draft. */
type SectionState =
  | { status: "pending" }
  | { status: "approved" }
  | { status: "rejected" }
  | { status: "edited"; content: string }
  | { status: "regenerate"; note: string }

type Decisions = Record<ClassSectionKey, SectionState>

const stateLabels: Record<SectionState["status"], string> = {
  pending: "Sin decidir",
  approved: "Aprobada",
  rejected: "Rechazada",
  edited: "Editada",
  regenerate: "Para regenerar",
}

const stateTone: Record<SectionState["status"], BadgeTone> = {
  pending: "neutral",
  approved: "success",
  rejected: "danger",
  edited: "info",
  regenerate: "warning",
}

function initialDecisions(carried: Partial<Decisions> | undefined, draft: ClassDraft | null): Decisions {
  const regenerated = new Set(draft?.regenerated_sections ?? [])
  return Object.fromEntries(
    classSectionKeys.map((key) => {
      const previous = carried?.[key]
      // A regenerated section comes back undecided; what the teacher did not
      // touch keeps its decision (P15-DEC-06).
      const keep = previous && !regenerated.has(key) && previous.status !== "regenerate"
      return [key, keep ? previous : { status: "pending" }]
    }),
  ) as Decisions
}

/**
 * Screen 15 «Propuesta de clase» (ClickUp 86e3jujh0): what the AI drafted,
 * with «¿Por qué la IA generó esta propuesta?» for this group. The requesting
 * teacher approves, edits, rejects or regenerates each section; asking to
 * regenerate several sections goes in one single call. Nothing is saved until
 * the teacher confirms (P15-DEC-01), and only approved or edited sections are.
 * Psicopedagogía and Dirección read it (`can_decide` false).
 *
 * The AI's text is rendered as plain text (React escapes it), never as HTML.
 */
export function ClassProposalPage() {
  const { id } = useParams()
  // «Regenerar» navigates to the new draft's id on this same route: a fresh
  // page per id, so nothing of the previous draft (busy, decisions being
  // edited, errors) leaks into the new one.
  return <ClassProposalView key={id} />
}

function ClassProposalView() {
  const { id } = useParams()
  const proposalId = Number(id)
  const navigate = useNavigate()
  const location = useLocation()
  const carried = (location.state as { decisions?: Partial<Decisions> } | null)?.decisions

  const [proposal, setProposal] = useState<ClassProposal | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [decisions, setDecisions] = useState<Decisions>(() => initialDecisions(undefined, null))
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)
  // A failed poll (school wifi, a stray 502) retries instead of replacing the
  // page with an error; only the first load shows one.
  const loaded = useRef(false)
  const [pollFailures, setPollFailures] = useState(0)

  const load = useCallback(
    () =>
      fetchProposal(proposalId)
        .then((next) => {
          loaded.current = true
          setProposal(next)
          if (next.status === "completed") setDecisions(initialDecisions(carried, next.raw_response))
          return next
        })
        .catch(() => {
          if (loaded.current) setPollFailures((count) => count + 1)
          else setLoadError("No pudimos cargar la propuesta.")
          return null
        }),
    [proposalId, carried],
  )

  useEffect(() => {
    void load()
  }, [load])

  // Generation runs on the queue: poll while it is pending.
  useEffect(() => {
    if (proposal?.status !== "pending") return
    const timer = setTimeout(() => void load(), POLL_INTERVAL_MS)
    return () => clearTimeout(timer)
  }, [proposal, load, pollFailures])

  if (loadError) return <p className="text-sm text-destructive">{loadError}</p>
  if (!proposal) return <p className="text-sm text-muted-foreground">Cargando…</p>

  const request = proposal.class_request
  const params = proposal.input_parameters
  const draft = proposal.raw_response
  const canDecide = proposal.can_decide && proposal.status === "completed"

  const meta = [
    request?.subject?.name,
    request?.group?.name,
    request?.unit ? `Unidad ${request.unit.position} — ${request.unit.name}` : "Clase libre",
    params.date ? formatShortDate(params.date) : null,
    params.duration_minutes ? `${params.duration_minutes} minutos` : null,
  ]
    .filter(Boolean)
    .join(" · ")

  const states = classSectionKeys.map((key) => decisions[key])
  const toRegenerate = classSectionKeys.filter((key) => decisions[key].status === "regenerate")
  const allDecided = states.every((s) => s.status !== "pending" && s.status !== "regenerate")
  const anyKept = states.some((s) => s.status === "approved" || s.status === "edited")

  function setDecision(key: ClassSectionKey, state: SectionState) {
    setDecisions((current) => ({ ...current, [key]: state }))
  }

  /** «Quiero cambiar algo» for the marked sections, in one single call. */
  async function regenerate(sections: ClassSectionKey[]) {
    if (!proposal) return
    setBusy(true)
    setActionError(null)
    const base = withoutRegeneration(proposal.input_parameters)
    const changeRequests = Object.fromEntries(
      sections
        .map((key) => [key, decisions[key].status === "regenerate" ? decisions[key].note.trim() : ""] as const)
        .filter(([, note]) => note !== ""),
    )
    try {
      const next = await requestClassProposal(
        proposal.group_id,
        sections.length === 0
          ? base
          : {
              ...base,
              reference_proposal_id: proposal.id,
              regenerate_sections: sections,
              ...(Object.keys(changeRequests).length > 0 ? { change_requests: changeRequests } : {}),
            },
        proposal.journey_id,
      )
      navigate(proposalPath(next.id), { state: { decisions } })
    } catch (err) {
      setActionError(requestErrorMessage(err))
      setBusy(false)
    }
  }

  async function confirm() {
    if (!proposal || !draft) return
    setBusy(true)
    setActionError(null)
    const body = Object.fromEntries(
      classSectionKeys.map((key): [ClassSectionKey, SectionDecision] => {
        const state = decisions[key]
        if (state.status === "edited") {
          const minutes = draft.sections[key]?.duration_minutes
          return [key, { decision: "edited", content: state.content, ...(minutes ? { duration_minutes: minutes } : {}) }]
        }
        return [key, { decision: state.status === "rejected" ? "rejected" : "approved" }]
      }),
    ) as Record<ClassSectionKey, SectionDecision>
    try {
      const applied = await confirmClassProposal(proposal.id, body)
      navigate(applied.applied_to_id ? classPath(applied.applied_to_id) : newClassPath())
    } catch {
      setActionError("No pudimos confirmar la clase. Probá de nuevo.")
      setBusy(false)
    }
  }

  async function discard() {
    if (!proposal) return
    setBusy(true)
    try {
      await discardProposal(proposal.id)
      navigate(newClassPath())
    } catch {
      setActionError("No pudimos descartar el borrador.")
      setBusy(false)
    }
  }

  return (
    <div className="grid gap-6">
      <PageHeader
        backTo="/nueva-clase"
        backLabel="Nueva clase"
        title={draft?.title ?? params.focus}
        description={meta}
      >
        <Badge tone={proposal.status === "applied" ? "success" : "warning"}>
          {proposal.status === "applied" ? "Confirmada" : "Borrador · sin confirmar"}
        </Badge>
      </PageHeader>

      {proposal.status === "pending" && (
        <SectionCard title="La IA está armando la clase">
          <p className="text-sm text-muted-foreground" role="status">
            Generando la propuesta… Puede tardar unos segundos.
          </p>
        </SectionCard>
      )}

      {proposal.status === "error" && (
        <SectionCard title="No se pudo generar la propuesta">
          <div className="grid gap-3 text-sm">
            <p className="text-muted-foreground">La IA no devolvió una propuesta válida. Podés volver a pedirla.</p>
            {proposal.can_decide && (
              <div>
                <Button onClick={() => void regenerate([])} disabled={busy}>
                  <RefreshCw aria-hidden="true" />
                  Volver a pedir
                </Button>
              </div>
            )}
            {actionError && <p className="text-destructive">{actionError}</p>}
          </div>
        </SectionCard>
      )}

      {proposal.status === "discarded" && (
        <p className="text-sm text-muted-foreground">Este borrador se descartó.</p>
      )}

      {proposal.status === "applied" && proposal.applied_to_id && (
        <p className="text-sm">
          Esta propuesta ya se confirmó.{" "}
          <Link className="text-primary underline-offset-2 hover:underline" to={classPath(proposal.applied_to_id)}>
            Ver la clase
          </Link>
        </p>
      )}

      {draft && proposal.status !== "pending" && (
        <>
          {(params.objectives || (request?.unit_objectives.length ?? 0) > 0) && (
            <SectionCard title="Lo que querés que logren">
              <div className="grid gap-2 text-sm">
                {params.objectives && <p>{params.objectives}</p>}
                {request && request.unit_objectives.length > 0 && (
                  <ul className="list-disc pl-5">
                    {request.unit_objectives.map((o) => (
                      <li key={o.id}>{o.description}</li>
                    ))}
                  </ul>
                )}
                <p className="text-xs text-muted-foreground">Los objetivos son tuyos; la IA no los cambia.</p>
              </div>
            </SectionCard>
          )}

          <WhyBlock rationale={draft.rationale} dataUsed={draft.data_used} planner={draft.planner} />

          {!proposal.can_decide && (
            <p className="text-sm text-muted-foreground">
              Psicopedagogía y Dirección leen la propuesta; la confirma el docente.
            </p>
          )}

          <section aria-label="La clase, sección por sección" className="grid gap-4">
            {classSectionKeys.map((key) => (
              <SectionReview
                key={key}
                sectionKey={key}
                content={draft.sections[key]?.content ?? ""}
                minutes={draft.sections[key]?.duration_minutes}
                state={decisions[key]}
                regenerated={draft.regenerated_sections?.includes(key) ?? false}
                canDecide={canDecide}
                disabled={busy}
                onChange={(state) => setDecision(key, state)}
              />
            ))}
          </section>

          {canDecide && (
            <SectionCard title="Confirmar">
              <div className="grid gap-3 text-sm">
                <p className="text-muted-foreground">
                  Nada se guarda hasta que confirmes. Se guardan solo las secciones aprobadas o editadas; la clase
                  queda en el Calendario{request?.unit ? " y en el Programa anual" : ""}.
                </p>
                {toRegenerate.length > 0 && (
                  <p>
                    Marcaste {toRegenerate.length === 1 ? "una sección" : `${toRegenerate.length} secciones`} para
                    regenerar: se piden juntas en un solo llamado y lo demás queda como lo decidiste.
                  </p>
                )}
                {actionError && <p className="text-destructive">{actionError}</p>}
                <div className="flex flex-wrap gap-2">
                  {toRegenerate.length > 0 && (
                    <Button onClick={() => void regenerate(toRegenerate)} disabled={busy}>
                      <RefreshCw aria-hidden="true" />
                      {toRegenerate.length === 1 ? "Regenerar la sección" : `Regenerar ${toRegenerate.length} secciones`}
                    </Button>
                  )}
                  <Button
                    onClick={() => void confirm()}
                    disabled={busy || !allDecided || !anyKept}
                    variant={toRegenerate.length > 0 ? "outline" : "default"}
                  >
                    <Check aria-hidden="true" />
                    Confirmar clase
                  </Button>
                  <ConfirmDialog
                    trigger={
                      <Button variant="outline" disabled={busy}>
                        Descartar el borrador
                      </Button>
                    }
                    title="Descartar el borrador"
                    description="La propuesta no se guarda como clase. Podés pedir otra cuando quieras."
                    confirmLabel="Descartar"
                    isConfirming={busy}
                    onConfirm={() => void discard()}
                  />
                </div>
                {!allDecided && toRegenerate.length === 0 && (
                  <p className="text-xs text-muted-foreground">Para confirmar, decidí cada sección.</p>
                )}
                {allDecided && !anyKept && (
                  <p className="text-xs text-muted-foreground">Rechazaste todas las secciones: no hay clase para guardar.</p>
                )}
              </div>
            </SectionCard>
          )}
        </>
      )}
    </div>
  )
}

function withoutRegeneration(parameters: ClassRequestParameters): ClassRequestParameters {
  const rest: ClassRequestParameters = { ...parameters }
  delete rest.reference_proposal_id
  delete rest.regenerate_sections
  delete rest.change_requests
  return rest
}

function SectionReview({
  sectionKey,
  content,
  minutes,
  state,
  regenerated,
  canDecide,
  disabled,
  onChange,
}: {
  sectionKey: ClassSectionKey
  content: string
  minutes?: number
  state: SectionState
  regenerated: boolean
  canDecide: boolean
  disabled: boolean
  onChange: (state: SectionState) => void
}) {
  const label = classSectionLabels[sectionKey]
  const [editing, setEditing] = useState(false)
  const [text, setText] = useState(state.status === "edited" ? state.content : content)
  const inputId = `section-${sectionKey}`

  const shown = state.status === "edited" ? state.content : content

  return (
    <SectionCard
      title={
        <span className="flex flex-wrap items-center gap-2">
          {label}
          {minutes ? <span className="text-sm font-normal text-muted-foreground">{minutes} min</span> : null}
          {regenerated && <Badge tone="info">Regenerada</Badge>}
        </span>
      }
      action={canDecide ? <Badge tone={stateTone[state.status]}>{stateLabels[state.status]}</Badge> : undefined}
    >
      <div className="grid gap-3 text-sm">
        {editing ? (
          <div className="grid gap-2">
            <Label htmlFor={inputId}>Tu versión de «{label}»</Label>
            <Textarea id={inputId} value={text} onChange={(e) => setText(e.target.value)} rows={6} maxLength={5000} />
            <div className="flex flex-wrap gap-2">
              <Button
                size="sm"
                disabled={!text.trim()}
                onClick={() => {
                  onChange({ status: "edited", content: text.trim() })
                  setEditing(false)
                }}
              >
                Guardar mi versión
              </Button>
              <Button size="sm" variant="ghost" onClick={() => setEditing(false)}>
                Cancelar
              </Button>
            </div>
          </div>
        ) : (
          <p className={"whitespace-pre-line " + (state.status === "rejected" ? "text-muted-foreground line-through" : "")}>
            {shown}
          </p>
        )}

        {state.status === "regenerate" && (
          <div className="grid gap-1.5">
            <Label htmlFor={`${inputId}-note`}>¿Qué querés que cambie? (opcional)</Label>
            <Textarea
              id={`${inputId}-note`}
              value={state.note}
              maxLength={1000}
              onChange={(e) => onChange({ status: "regenerate", note: e.target.value })}
            />
          </div>
        )}

        {canDecide && !editing && (
          <div className="flex flex-wrap gap-2">
            <Button size="sm" variant="outline" disabled={disabled} onClick={() => onChange({ status: "approved" })} aria-label={`Aprobar ${label}`}>
              <Check aria-hidden="true" />
              Aprobar
            </Button>
            <Button
              size="sm"
              variant="outline"
              disabled={disabled}
              aria-label={`Editar ${label}`}
              onClick={() => {
                setText(shown)
                setEditing(true)
              }}
            >
              <Pencil aria-hidden="true" />
              Editar
            </Button>
            <Button size="sm" variant="outline" disabled={disabled} onClick={() => onChange({ status: "rejected" })} aria-label={`Rechazar ${label}`}>
              <X aria-hidden="true" />
              Rechazar
            </Button>
            <Button
              size="sm"
              variant="outline"
              disabled={disabled}
              aria-label={`Regenerar ${label}`}
              onClick={() =>
                onChange(state.status === "regenerate" ? { status: "pending" } : { status: "regenerate", note: "" })
              }
            >
              <RefreshCw aria-hidden="true" />
              {state.status === "regenerate" ? "No regenerar" : "Regenerar"}
            </Button>
          </div>
        )}
      </div>
    </SectionCard>
  )
}
