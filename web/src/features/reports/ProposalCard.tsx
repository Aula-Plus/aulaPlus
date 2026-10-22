import { useState } from "react"
import { Badge, type BadgeTone } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import {
  accommodationCategoryLabels,
  type AccommodationCategory,
  type AccommodationProposal,
  type ProposalBasis,
  type ProposalStatus,
  type Subject,
} from "@/types"
import type { ProposalFields } from "./reportsApi"

const CATEGORIES: AccommodationCategory[] = ["access", "content", "criteria"]

const basisLabels: Record<ProposalBasis, string> = {
  explicit: "Lo dice el informe",
  inferred: "Inferido por la IA",
  manual: "Agregado a mano",
}

const statusLabels: Record<ProposalStatus, string> = {
  pending: "Pendiente",
  approved: "Aprobado",
  modified: "Aprobado con cambios",
  rejected: "Rechazado",
}

const statusTone: Record<ProposalStatus, BadgeTone> = {
  pending: "warning",
  approved: "success",
  modified: "success",
  rejected: "neutral",
}

interface ProposalCardProps {
  proposal: AccommodationProposal
  /** The school's subjects, to show and change the ones the proposal applies to. */
  subjects: Subject[]
  /** Only psychopedagogy sees the proposals and decides. */
  canReview: boolean
  onReview: (
    proposal: AccommodationProposal,
    input: { decision: "approve" | "reject"; subject_ids?: number[] } & Partial<ProposalFields>,
  ) => Promise<void>
}

/**
 * One adjustment proposed from a report: where it comes from (page and
 * fragment), whether the report says it or the AI inferred it, and the three
 * ways out — approve, modify and approve, or reject. Nothing is in force until
 * it is approved; approved ones appear in «Ajustes».
 */
export function ProposalCard({ proposal, subjects, canReview, onReview }: ProposalCardProps) {
  const [editing, setEditing] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [fields, setFields] = useState<ProposalFields>({
    type: proposal.type,
    category: proposal.category,
    description: proposal.description,
    focus_area: proposal.focus_area ?? "",
  })
  const [subjectIds, setSubjectIds] = useState<number[]>(proposal.subject_ids)
  const subjectsChanged =
    subjectIds.length !== proposal.subject_ids.length || subjectIds.some((id) => !proposal.subject_ids.includes(id))
  const subjectNames = proposal.subject_ids.map((id) => subjects.find((s) => s.id === id)?.name).filter(Boolean)
  const pending = proposal.status === "pending" && canReview

  async function decide(input: { decision: "approve" | "reject"; subject_ids?: number[] } & Partial<ProposalFields>) {
    setBusy(true)
    setError(null)
    try {
      await onReview(proposal, input)
    } catch {
      setError("No pudimos guardar tu decisión. Probá de nuevo.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <li className="grid gap-3 rounded-md border p-4 text-sm">
      <div className="flex flex-wrap items-center gap-2">
        <span className="font-medium">{proposal.type}</span>
        <Badge tone="info">{accommodationCategoryLabels[proposal.category]}</Badge>
        <Badge tone={proposal.basis === "inferred" ? "warning" : "neutral"}>
          {basisLabels[proposal.basis]}
        </Badge>
        <Badge tone={statusTone[proposal.status]}>{statusLabels[proposal.status]}</Badge>
      </div>

      {editing ? (
        <div className="grid gap-3">
          <div className="grid gap-1">
            <Label htmlFor={`proposal-type-${proposal.id}`}>Nombre del ajuste</Label>
            <Input
              id={`proposal-type-${proposal.id}`}
              value={fields.type}
              onChange={(event) => setFields({ ...fields, type: event.target.value })}
            />
          </div>
          <div className="grid gap-1">
            <Label htmlFor={`proposal-category-${proposal.id}`}>Categoría</Label>
            <select
              id={`proposal-category-${proposal.id}`}
              className="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
              value={fields.category}
              onChange={(event) =>
                setFields({ ...fields, category: event.target.value as AccommodationCategory })
              }
            >
              {CATEGORIES.map((category) => (
                <option key={category} value={category}>
                  {accommodationCategoryLabels[category]}
                </option>
              ))}
            </select>
          </div>
          <div className="grid gap-1">
            <Label htmlFor={`proposal-area-${proposal.id}`}>Área de enfoque</Label>
            <Input
              id={`proposal-area-${proposal.id}`}
              value={fields.focus_area}
              onChange={(event) => setFields({ ...fields, focus_area: event.target.value })}
            />
          </div>
          <div className="grid gap-1">
            <Label htmlFor={`proposal-description-${proposal.id}`}>Qué hacer en clase</Label>
            <Textarea
              id={`proposal-description-${proposal.id}`}
              value={fields.description}
              onChange={(event) => setFields({ ...fields, description: event.target.value })}
            />
          </div>
        </div>
      ) : (
        <>
          <p>{proposal.description}</p>
          {proposal.focus_area && (
            <p className="text-muted-foreground">Área de enfoque: {proposal.focus_area}</p>
          )}
        </>
      )}

      {pending ? (
        <fieldset className="grid gap-1">
          <legend className="font-medium">
            Materias
            {proposal.subjects_basis && (
              <span className="ml-2 font-normal text-muted-foreground">
                ({proposal.subjects_basis === "explicit" ? "lo dice el informe" : "inferido"})
              </span>
            )}
          </legend>
          <p className="text-muted-foreground">
            {subjectIds.length === 0
              ? "Sin materias marcadas: el ajuste va para todas las materias."
              : "El docente ve solo los ajustes generales y los de sus materias."}
          </p>
          <div className="flex flex-wrap gap-x-4 gap-y-1">
            {subjects.map((subject) => (
              <label key={subject.id} className="flex items-center gap-1.5">
                <input
                  type="checkbox"
                  checked={subjectIds.includes(subject.id)}
                  onChange={(event) =>
                    setSubjectIds(
                      event.target.checked ? [...subjectIds, subject.id] : subjectIds.filter((id) => id !== subject.id),
                    )
                  }
                />
                {subject.name}
              </label>
            ))}
          </div>
        </fieldset>
      ) : (
        proposal.subject_ids.length > 0 && (
          <p className="text-muted-foreground">Materias: {subjectNames.join(", ") || "—"}</p>
        )
      )}

      <div className="rounded-md bg-muted/40 p-3 text-muted-foreground">
        {proposal.source_fragment ? (
          <>
            <p className="font-medium text-foreground">
              {proposal.source_page ? `Página ${proposal.source_page}` : "Fragmento del informe"}
            </p>
            <blockquote className="mt-1 border-l-2 pl-3 italic">{proposal.source_fragment}</blockquote>
          </>
        ) : proposal.basis === "manual" ? (
          <p>Lo agregaste vos, sin fragmento de origen.</p>
        ) : (
          <p>
            La IA no pudo citar un fragmento exacto del informe
            {proposal.source_page ? ` (página ${proposal.source_page})` : ""}. Revisá el texto antes de aprobarlo.
          </p>
        )}
      </div>

      {error && (
        <p role="alert" className="text-destructive">
          {error}
        </p>
      )}

      {pending && (
        <div className="flex flex-wrap gap-2">
          {editing ? (
            <>
              <Button
                type="button"
                size="sm"
                disabled={busy || !fields.type.trim() || !fields.description.trim() || !fields.focus_area.trim()}
                onClick={() => decide({ decision: "approve", ...fields, ...(subjectsChanged ? { subject_ids: subjectIds } : {}) })}
              >
                Aprobar con estos cambios
              </Button>
              <Button type="button" size="sm" variant="outline" disabled={busy} onClick={() => setEditing(false)}>
                Cancelar
              </Button>
            </>
          ) : (
            <>
              <Button
                type="button"
                size="sm"
                disabled={busy || !proposal.focus_area}
                onClick={() => decide({ decision: "approve", ...(subjectsChanged ? { subject_ids: subjectIds } : {}) })}
              >
                Aprobar
              </Button>
              <Button type="button" size="sm" variant="outline" disabled={busy} onClick={() => setEditing(true)}>
                Modificar
              </Button>
              <Button
                type="button"
                size="sm"
                variant="ghost"
                disabled={busy}
                onClick={() => decide({ decision: "reject" })}
              >
                Rechazar
              </Button>
            </>
          )}
        </div>
      )}
      {pending && !proposal.focus_area && !editing && (
        <p className="text-muted-foreground">Falta el área de enfoque: usá «Modificar» para completarla.</p>
      )}
    </li>
  )
}
