import { api } from "@/lib/api"
import type {
  AccommodationCategory,
  AccommodationProposal,
  AiReportSetting,
  ReportDocument,
  ReportDocumentDetail,
  ReportVisibility,
} from "@/types"

export async function fetchReports(studentId: number): Promise<ReportDocument[]> {
  const { data } = await api.get<{ data: ReportDocument[] }>(`/api/v1/students/${studentId}/reports`)
  return data.data
}

export async function uploadReport(
  studentId: number,
  input: { file: File; useAi: boolean; visibility: ReportVisibility },
): Promise<ReportDocument> {
  const form = new FormData()
  form.append("file", input.file)
  form.append("use_ai", input.useAi ? "1" : "0")
  form.append("visibility", input.visibility)
  const { data } = await api.post<{ data: ReportDocument }>(`/api/v1/students/${studentId}/reports`, form)
  return data.data
}

export async function fetchReport(id: number): Promise<ReportDocumentDetail> {
  const { data } = await api.get<{ data: ReportDocumentDetail }>(`/api/v1/reports/${id}`)
  return data.data
}

export async function fetchReportDownloadUrl(id: number): Promise<string> {
  const { data } = await api.get<{ data: { url: string } }>(`/api/v1/reports/${id}/download`)
  return data.data.url
}

export async function updateReport(
  id: number,
  input: { visibility?: ReportVisibility; use_ai?: boolean },
): Promise<ReportDocument> {
  const { data } = await api.patch<{ data: ReportDocument }>(`/api/v1/reports/${id}`, input)
  return data.data
}

export async function deleteReport(id: number): Promise<void> {
  await api.delete(`/api/v1/reports/${id}`)
}

export async function saveAnonymizedText(id: number, pages: string[]): Promise<ReportDocumentDetail> {
  const { data } = await api.put<{ data: ReportDocumentDetail }>(`/api/v1/reports/${id}/anonymized-text`, {
    pages,
  })
  return data.data
}

export async function confirmSend(id: number): Promise<ReportDocumentDetail> {
  const { data } = await api.post<{ data: ReportDocumentDetail }>(`/api/v1/reports/${id}/confirm-send`)
  return data.data
}

export interface ProposalFields {
  type: string
  category: AccommodationCategory
  description: string
  focus_area: string
}

export async function addProposal(
  reportId: number,
  input: ProposalFields & { source_page?: number | null; source_fragment?: string | null },
): Promise<AccommodationProposal> {
  const { data } = await api.post<{ data: AccommodationProposal }>(
    `/api/v1/reports/${reportId}/proposals`,
    input,
  )
  return data.data
}

export async function reviewProposal(
  id: number,
  input: { decision: "approve" | "reject"; subject_ids?: number[] } & Partial<ProposalFields>,
): Promise<AccommodationProposal> {
  const { data } = await api.post<{ data: AccommodationProposal }>(
    `/api/v1/accommodation-proposals/${id}/review`,
    input,
  )
  return data.data
}

export async function fetchAiReportSetting(): Promise<AiReportSetting> {
  const { data } = await api.get<{ data: AiReportSetting }>("/api/v1/ai-report-setting")
  return data.data
}

export async function updateAiReportSetting(enabled: boolean): Promise<AiReportSetting> {
  const { data } = await api.put<{ data: AiReportSetting }>("/api/v1/ai-report-setting", { enabled })
  return data.data
}

/**
 * Opens a report in a new tab through its short-lived URL. The tab is opened
 * synchronously (inside the click) because Safari/iOS block a `window.open`
 * made after an await.
 */
export function openReportFile(reportId: number, onError: (message: string) => void): void {
  const tab = window.open("", "_blank")
  // noopener semantics: the file's tab can't reach back into the app.
  if (tab) tab.opener = null

  fetchReportDownloadUrl(reportId)
    .then((url) => {
      if (tab) tab.location.href = url
      else onError("Tu navegador bloqueó la pestaña nueva. Permití las ventanas emergentes y volvé a intentar.")
    })
    .catch(() => {
      tab?.close()
      onError("No pudimos abrir el archivo.")
    })
}
