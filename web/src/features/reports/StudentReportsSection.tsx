import { useCallback, useEffect, useState } from "react"
import { FileText } from "lucide-react"
import { Badge, type BadgeTone } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { ConfirmDialog } from "@/components/ui/confirm-dialog"
import { EmptyState } from "@/components/ui/empty-state"
import { Label } from "@/components/ui/label"
import { SectionCard } from "@/components/ui/section-card"
import { formatShortDate } from "@/lib/utils"
import {
  reportStatusLabels,
  reportVisibilityLabels,
  type ReportDocument,
  type ReportStatus,
  type ReportVisibility,
} from "@/types"
import { ReportReviewPanel } from "./ReportReviewPanel"
import * as reportsApi from "./reportsApi"

const statusTone: Record<ReportStatus, BadgeTone> = {
  processing: "neutral",
  extraction_failed: "danger",
  ready: "neutral",
  awaiting_review: "warning",
  interpreting: "info",
  proposed: "success",
  error: "danger",
}

interface StudentReportsSectionProps {
  studentId: number
  /** Psychopedagogy: upload, review, delete, set visibility. */
  canManage: boolean
  /** Direction and psychopedagogy see status and can open the review workspace. */
  canSeeContent: boolean
  onAccommodationCreated: () => void
}

/**
 * «Informes» of the student's profile (ClickUp 86e3jujgx). Every file has its
 * own visibility: clinical reports are for psychopedagogy and direction; a
 * teacher only sees (and opens) the files psychopedagogy enabled for teachers.
 */
export function StudentReportsSection({
  studentId,
  canManage,
  canSeeContent,
  onAccommodationCreated,
}: StudentReportsSectionProps) {
  const [reports, setReports] = useState<ReportDocument[] | null>(null)
  const [openId, setOpenId] = useState<number | null>(null)
  const [file, setFile] = useState<File | null>(null)
  const [useAi, setUseAi] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(() => {
    return reportsApi
      .fetchReports(studentId)
      .then(setReports)
      .catch(() => {
        setReports([])
        setError("No pudimos cargar los informes.")
      })
  }, [studentId])

  useEffect(() => {
    void load()
  }, [load])

  async function upload() {
    if (!file) return
    setBusy(true)
    setError(null)
    try {
      const created = await reportsApi.uploadReport(studentId, { file, useAi })
      setFile(null)
      await load()
      setOpenId(created.id)
    } catch (caught) {
      const message = (caught as { response?: { data?: { message?: string } } }).response?.data?.message
      setError(message ?? "No pudimos subir el informe. Revisá que sea un PDF o Word de hasta 10 MB.")
    } finally {
      setBusy(false)
    }
  }

  async function open(report: ReportDocument) {
    try {
      window.open(await reportsApi.fetchReportDownloadUrl(report.id), "_blank", "noopener,noreferrer")
    } catch {
      setError("No pudimos abrir el archivo.")
    }
  }

  async function changeVisibility(report: ReportDocument, visibility: ReportVisibility) {
    setError(null)
    try {
      const updated = await reportsApi.updateReport(report.id, { visibility })
      setReports((current) => current?.map((r) => (r.id === updated.id ? updated : r)) ?? null)
    } catch {
      setError("No pudimos cambiar la visibilidad.")
    }
  }

  async function remove(report: ReportDocument) {
    try {
      await reportsApi.deleteReport(report.id)
      if (openId === report.id) setOpenId(null)
      await load()
    } catch {
      setError("No pudimos eliminar el informe.")
    }
  }

  return (
    <SectionCard title="Informes">
      {error && (
        <p role="alert" className="mb-3 text-sm text-destructive">
          {error}
        </p>
      )}

      {canManage && (
        <form
          className="mb-4 grid gap-3 rounded-md border p-4"
          onSubmit={(event) => {
            event.preventDefault()
            void upload()
          }}
        >
          <div className="grid gap-1">
            <Label htmlFor="report-file">Subir informe (PDF o Word, hasta 10 MB)</Label>
            <input
              id="report-file"
              type="file"
              accept=".pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
              onChange={(event) => setFile(event.target.files?.[0] ?? null)}
              className="text-sm"
            />
          </div>
          <label className="flex items-start gap-2 text-sm">
            <input type="checkbox" className="mt-1" checked={useAi} onChange={(e) => setUseAi(e.target.checked)} />
            <span>
              Usar la IA para proponer ajustes de este informe
              <span className="block text-muted-foreground">
                Solo si el colegio la tiene activada. Antes de enviar nada vas a revisar el texto anonimizado.
              </span>
            </span>
          </label>
          <div>
            <Button type="submit" size="sm" disabled={!file || busy}>
              {busy ? "Subiendo…" : "Subir informe"}
            </Button>
          </div>
        </form>
      )}

      {reports === null ? (
        <p className="text-sm text-muted-foreground">Cargando…</p>
      ) : reports.length === 0 ? (
        <EmptyState icon={FileText} message="Todavía no hay documentos para mostrar." />
      ) : (
        <ul className="grid gap-3">
          {reports.map((report) => (
            <li key={report.id} className="grid gap-3 rounded-md border p-4 text-sm">
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">{report.name}</span>
                <span className="text-muted-foreground">{formatShortDate(report.uploaded_at)}</span>
                {report.status && <Badge tone={statusTone[report.status]}>{reportStatusLabels[report.status]}</Badge>}
              </div>

              <div className="flex flex-wrap items-center gap-3">
                {canManage ? (
                  <div className="flex items-center gap-2">
                    <Label htmlFor={`visibility-${report.id}`}>Quién lo ve</Label>
                    <select
                      id={`visibility-${report.id}`}
                      className="h-8 rounded-md border border-input bg-transparent px-2 text-sm"
                      value={report.visibility}
                      onChange={(event) => changeVisibility(report, event.target.value as ReportVisibility)}
                    >
                      {(Object.keys(reportVisibilityLabels) as ReportVisibility[]).map((visibility) => (
                        <option key={visibility} value={visibility}>
                          {reportVisibilityLabels[visibility]}
                        </option>
                      ))}
                    </select>
                  </div>
                ) : (
                  <span className="text-muted-foreground">{reportVisibilityLabels[report.visibility]}</span>
                )}
                <Button type="button" size="sm" variant="outline" onClick={() => open(report)}>
                  Abrir archivo
                </Button>
                {canSeeContent && (
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => setOpenId(openId === report.id ? null : report.id)}
                  >
                    {openId === report.id ? "Cerrar revisión" : "Revisar"}
                  </Button>
                )}
                {canManage && (
                  <ConfirmDialog
                    trigger={
                      <Button type="button" size="sm" variant="ghost">
                        Eliminar
                      </Button>
                    }
                    title="Eliminar informe"
                    description="Se borra el archivo y su texto. Los ajustes que ya aprobaste se mantienen."
                    confirmLabel="Eliminar"
                    onConfirm={() => void remove(report)}
                  />
                )}
              </div>

              {canSeeContent && openId === report.id && (
                <ReportReviewPanel
                  reportId={report.id}
                  canManage={canManage}
                  onAccommodationCreated={onAccommodationCreated}
                />
              )}
            </li>
          ))}
        </ul>
      )}
    </SectionCard>
  )
}
