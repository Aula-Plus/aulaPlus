import { screeningColorLabels } from "@/types"
import type { ScreeningTestDesign } from "@/types"
import { Button } from "@/components/ui/button"

interface ScreeningTestDesignCardProps {
  design: ScreeningTestDesign
  /** Whether the current user may approve/reject (director only). */
  canApprove: boolean
  onApprove: (design: ScreeningTestDesign) => void
  onReject: (design: ScreeningTestDesign) => void
  /** Disables the buttons while an approve/reject request is in flight. */
  busy?: boolean
}

/**
 * Presentational card for a single screening-test design version: its cutoffs,
 * the Spanish meaning of each traffic-light band, and a status badge derived
 * from the tri-state `approved`.
 *
 * The "Aprobar"/"Rechazar" buttons appear ONLY when the design is still pending
 * (`approved === null`) AND the viewer may approve (director) — mirrors
 * docs/prompts/12 §4. A decided design (approved `true`/`false`) never shows the
 * buttons regardless of role, so the buttons can never re-decide a design (the
 * backend also 422s that, but the UI never offers it).
 */
export function ScreeningTestDesignCard({
  design,
  canApprove,
  onApprove,
  onReject,
  busy = false,
}: ScreeningTestDesignCardProps) {
  const status =
    design.approved === null ? "pending" : design.approved ? "approved" : "rejected"

  const badge = {
    pending: { label: "Pendiente de aprobación", className: "bg-amber-100 text-amber-800" },
    approved: { label: "Aprobado (vigente)", className: "bg-green-100 text-green-800" },
    rejected: { label: "Rechazado", className: "bg-red-100 text-red-800" },
  }[status]

  return (
    <div className="rounded-md border p-3">
      <div className="flex items-center justify-between gap-3">
        <span className={`rounded px-2 py-0.5 text-xs font-semibold ${badge.className}`}>
          {badge.label}
        </span>
        {status === "pending" && canApprove && (
          <div className="flex gap-2">
            <Button type="button" size="sm" disabled={busy} onClick={() => onApprove(design)}>
              Aprobar
            </Button>
            <Button
              type="button"
              size="sm"
              variant="destructive"
              disabled={busy}
              onClick={() => onReject(design)}
            >
              Rechazar
            </Button>
          </div>
        )}
      </div>

      <dl className="mt-3 grid gap-1 text-sm">
        <div className="flex gap-2">
          <dt className="text-muted-foreground">Cortes:</dt>
          <dd>
            {screeningColorLabels.red} ≤ {design.cutoff_low} &lt; {screeningColorLabels.yellow} &lt;{" "}
            {design.cutoff_high} ≤ {screeningColorLabels.green}
          </dd>
        </div>
        <div className="flex gap-2">
          <dt className="text-muted-foreground">{screeningColorLabels.red}:</dt>
          <dd>{design.meaning_red}</dd>
        </div>
        <div className="flex gap-2">
          <dt className="text-muted-foreground">{screeningColorLabels.yellow}:</dt>
          <dd>{design.meaning_yellow}</dd>
        </div>
        <div className="flex gap-2">
          <dt className="text-muted-foreground">{screeningColorLabels.green}:</dt>
          <dd>{design.meaning_green}</dd>
        </div>
      </dl>
    </div>
  )
}
