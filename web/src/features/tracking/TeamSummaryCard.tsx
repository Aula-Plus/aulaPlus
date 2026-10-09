import { useState } from "react"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { SectionCard } from "@/components/ui/section-card"
import { Textarea } from "@/components/ui/textarea"
import type { SupportChip, TeamSummary } from "@/types"
import type { TeamSummaryInput } from "./trackingApi"

const FIELDS: { key: keyof TeamSummaryInput; label: string; id: string }[] = [
  { key: "strengths", label: "Fortalezas", id: "team-summary-strengths" },
  { key: "difficulties", label: "Qué le cuesta", id: "team-summary-difficulties" },
  { key: "adjustments", label: "Ajustes", id: "team-summary-adjustments" },
]

export interface TeamSummaryCardProps {
  summary: TeamSummary | null
  accommodations: SupportChip[]
  barriers: SupportChip[]
  /** Only psychopedagogy edits; the server enforces it, this just hides the form. */
  canEdit: boolean
  onSave: (input: TeamSummaryInput) => Promise<void>
  /** Called with the tab the chip leads to (always "adjustments"). */
  onChipClick: () => void
}

/**
 * "Qué necesitás saber hoy": the team-facing summary of the technical report
 * (strengths / what is hard in class terms / adjustments — never a diagnosis)
 * plus a thin strip of chips with the current accommodations and barriers.
 * Nothing is shown until psychopedagogy has confirmed (saved) the summary.
 */
export function TeamSummaryCard({
  summary,
  accommodations,
  barriers,
  canEdit,
  onSave,
  onChipClick,
}: TeamSummaryCardProps) {
  const [editing, setEditing] = useState(false)
  const [draft, setDraft] = useState<TeamSummaryInput>({
    strengths: "",
    difficulties: "",
    adjustments: "",
  })
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  function startEditing() {
    setDraft({
      strengths: summary?.strengths ?? "",
      difficulties: summary?.difficulties ?? "",
      adjustments: summary?.adjustments ?? "",
    })
    setError(null)
    setEditing(true)
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault()
    if (FIELDS.some(({ key }) => draft[key].trim() === "")) {
      setError("Completá las tres partes del resumen.")
      return
    }
    setSaving(true)
    setError(null)
    try {
      await onSave({
        strengths: draft.strengths.trim(),
        difficulties: draft.difficulties.trim(),
        adjustments: draft.adjustments.trim(),
      })
      setEditing(false)
    } catch {
      setError("No pudimos guardar el resumen.")
    } finally {
      setSaving(false)
    }
  }

  const hasChips = accommodations.length > 0 || barriers.length > 0

  return (
    <SectionCard
      title="Qué necesitás saber hoy"
      action={
        canEdit && !editing ? (
          <Button type="button" size="sm" variant="outline" onClick={startEditing}>
            {summary ? "Editar resumen" : "Escribir resumen"}
          </Button>
        ) : undefined
      }
    >
      <div className="grid gap-4">
        {editing ? (
          <form onSubmit={handleSubmit} className="grid gap-3">
            {FIELDS.map(({ key, label, id }) => (
              <div key={key} className="grid gap-1.5">
                <Label htmlFor={id}>{label}</Label>
                <Textarea
                  id={id}
                  value={draft[key]}
                  maxLength={2000}
                  onChange={(event) => setDraft((d) => ({ ...d, [key]: event.target.value }))}
                />
              </div>
            ))}
            <p className="text-xs text-muted-foreground">
              Pensado para el equipo docente: no nombres el diagnóstico y escribí lo que le cuesta
              en términos de clase. Al guardar, el resumen queda visible para el equipo.
            </p>
            {error && <p className="text-sm text-destructive">{error}</p>}
            <div className="flex gap-2">
              <Button type="submit" disabled={saving}>
                {saving ? "Guardando…" : "Guardar y confirmar"}
              </Button>
              <Button type="button" variant="outline" onClick={() => setEditing(false)}>
                Cancelar
              </Button>
            </div>
          </form>
        ) : summary ? (
          <dl className="grid gap-2 text-sm">
            {FIELDS.map(({ key, label }) => (
              <div key={key}>
                <dt className="font-medium">{label}</dt>
                <dd className="whitespace-pre-wrap text-muted-foreground">{summary[key]}</dd>
              </div>
            ))}
          </dl>
        ) : (
          <p className="text-sm text-muted-foreground">
            Todavía no hay un resumen confirmado para este alumno.
          </p>
        )}

        {hasChips && (
          <ul aria-label="Ajustes y barreras vigentes" className="flex flex-wrap gap-2 border-t pt-3">
            {accommodations.map((chip) => (
              <li key={`a-${chip.id}`}>
                <button
                  type="button"
                  onClick={onChipClick}
                  className="rounded-full border border-info/30 bg-info-background/40 px-3 py-1 text-xs text-info"
                >
                  {chip.label}
                </button>
              </li>
            ))}
            {barriers.map((chip) => (
              <li key={`b-${chip.id}`}>
                <button
                  type="button"
                  onClick={onChipClick}
                  className="rounded-full border border-warning/30 bg-warning-background/40 px-3 py-1 text-xs text-warning"
                >
                  {chip.label}
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </SectionCard>
  )
}
