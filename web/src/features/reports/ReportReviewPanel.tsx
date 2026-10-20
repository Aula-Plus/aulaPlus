import { useCallback, useEffect, useState } from "react"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { SectionCard } from "@/components/ui/section-card"
import type { AccommodationProposal, ReportDocumentDetail } from "@/types"
import { AddProposalForm } from "./AddProposalForm"
import { ProposalCard } from "./ProposalCard"
import * as reportsApi from "./reportsApi"

const POLL_MS = 3000

interface ReportReviewPanelProps {
  reportId: number
  /** Psychopedagogy edits and decides; direction only reads. */
  canManage: boolean
  /** Called when a proposal is approved, so the student's adjustments can reload. */
  onAccommodationCreated: () => void
}

/**
 * Psychopedagogy's workspace for one report: review/correct the anonymized
 * text, confirm that it may go to the AI, then approve, modify or reject each
 * proposed adjustment (ClickUp 86e3jujgx). Nothing leaves the system before
 * the explicit confirmation, and nothing is in force before an approval.
 */
export function ReportReviewPanel({ reportId, canManage, onAccommodationCreated }: ReportReviewPanelProps) {
  const [report, setReport] = useState<ReportDocumentDetail | null>(null)
  const [pages, setPages] = useState<string[]>([])
  const [dirty, setDirty] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const apply = useCallback((next: ReportDocumentDetail) => {
    setReport(next)
    setPages(next.anonymized_pages ?? [])
    setDirty(false)
  }, [])

  const load = useCallback(() => {
    return reportsApi
      .fetchReport(reportId)
      .then(apply)
      .catch(() => setError("No pudimos cargar el informe."))
  }, [reportId, apply])

  useEffect(() => {
    void load()
  }, [load])

  const waiting = report?.status === "processing" || report?.status === "interpreting"

  useEffect(() => {
    if (!waiting) return
    const timer = window.setInterval(() => {
      reportsApi
        .fetchReport(reportId)
        .then((next) => setReport((current) => (current && next.status !== current.status ? next : current)))
        .catch(() => undefined)
    }, POLL_MS)
    return () => window.clearInterval(timer)
  }, [waiting, reportId])

  // After a status change picked up by polling, bring the texts along.
  useEffect(() => {
    if (report && !dirty && report.anonymized_pages) setPages(report.anonymized_pages)
  }, [report, dirty])

  async function run(action: () => Promise<void>, failure: string) {
    setBusy(true)
    setError(null)
    try {
      await action()
    } catch (caught) {
      const message = (caught as { response?: { data?: { message?: string } } }).response?.data?.message
      setError(message ?? failure)
    } finally {
      setBusy(false)
    }
  }

  if (!report) {
    return error ? (
      <p role="alert" className="text-sm text-destructive">
        {error}
      </p>
    ) : (
      <p className="text-sm text-muted-foreground">Cargando…</p>
    )
  }

  const reviewable = canManage && ["awaiting_review", "ready", "error"].includes(report.status ?? "")
  const canSend = reviewable && report.ai_available && pages.length > 0

  async function handleReview(
    proposal: AccommodationProposal,
    input: Parameters<typeof reportsApi.reviewProposal>[1],
  ) {
    const updated = await reportsApi.reviewProposal(proposal.id, input)
    setReport((current) =>
      current
        ? { ...current, proposals: current.proposals.map((p) => (p.id === updated.id ? updated : p)) }
        : current,
    )
    if (updated.accommodation_id) onAccommodationCreated()
  }

  async function handleAdd(input: reportsApi.ProposalFields) {
    const added = await reportsApi.addProposal(reportId, input)
    setReport((current) => (current ? { ...current, proposals: [...current.proposals, added] } : current))
  }

  return (
    <div className="grid gap-4">
      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}

      {report.status === "processing" && (
        <p role="status" className="text-sm text-muted-foreground">
          Estamos leyendo el informe y anonimizándolo. Esto puede demorar un momento.
        </p>
      )}

      {report.status === "extraction_failed" && (
        <p role="note" className="rounded-md border bg-muted/40 p-3 text-sm">
          No pudimos leer el texto de este archivo (puede ser un escaneo o estar protegido). El archivo quedó
          guardado. Podés cargar los ajustes a mano.
        </p>
      )}

      {report.status === "error" && report.error_message && (
        <p role="alert" className="text-sm text-destructive">
          La lectura con IA falló. Podés revisar el texto y volver a enviarlo, o seguir a mano.
        </p>
      )}

      {report.anonymized_pages && pages.length > 0 && (
        <SectionCard title="Texto anonimizado">
          <p className="mb-3 text-sm text-muted-foreground">
            Aula+ borró el nombre y los datos identificables que detectó, pero puede no haber detectado todos.
            Revisá el texto y corregilo si hace falta
            {report.ai_available
              ? ": a la IA solo se le envía este texto, y solo cuando lo confirmás."
              : ". La lectura con IA no está activa para este informe, así que no se envía nada."}
          </p>
          <div className="grid gap-3">
            {pages.map((text, index) => (
              <div key={index} className="grid gap-1">
                <Label htmlFor={`report-${reportId}-page-${index}`}>Página {index + 1}</Label>
                <Textarea
                  id={`report-${reportId}-page-${index}`}
                  className="min-h-32"
                  value={text}
                  readOnly={!reviewable}
                  onChange={(event) => {
                    setPages(pages.map((p, i) => (i === index ? event.target.value : p)))
                    setDirty(true)
                  }}
                />
              </div>
            ))}
          </div>
          {reviewable && (
            <div className="mt-3 flex flex-wrap gap-2">
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={busy || !dirty}
                onClick={() =>
                  run(async () => apply(await reportsApi.saveAnonymizedText(reportId, pages)), "No pudimos guardar el texto.")
                }
              >
                Guardar correcciones
              </Button>
              {canSend && (
                <Button
                  type="button"
                  size="sm"
                  disabled={busy}
                  onClick={() =>
                    run(async () => {
                      if (dirty) await reportsApi.saveAnonymizedText(reportId, pages)
                      apply(await reportsApi.confirmSend(reportId))
                    }, "No pudimos enviar el texto.")
                  }
                >
                  Confirmar y enviar a la IA
                </Button>
              )}
            </div>
          )}
          {reviewable && !report.ai_available && (
            <p className="mt-2 text-sm text-muted-foreground">
              La IA está apagada para este informe o para el colegio.
            </p>
          )}
        </SectionCard>
      )}

      {report.status === "interpreting" && (
        <p role="status" className="text-sm text-muted-foreground">
          La IA está leyendo el texto que confirmaste. Las propuestas van a aparecer acá.
        </p>
      )}

      <SectionCard title="Ajustes propuestos">
        <p className="mb-3 text-sm text-muted-foreground">
          Ninguno está vigente hasta que lo aprobás. Los aprobados aparecen en «Ajustes».
        </p>
        {report.proposals.length === 0 ? (
          <p className="mb-3 text-sm text-muted-foreground">Todavía no hay propuestas para este informe.</p>
        ) : (
          <ul className="mb-3 grid gap-3">
            {report.proposals.map((proposal) => (
              <ProposalCard key={proposal.id} proposal={proposal} canReview={canManage} onReview={handleReview} />
            ))}
          </ul>
        )}
        {canManage && <AddProposalForm onAdd={handleAdd} />}
      </SectionCard>
    </div>
  )
}
