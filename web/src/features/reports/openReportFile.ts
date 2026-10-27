import * as reportsApi from "./reportsApi"

/**
 * Opens a report in a new tab through its short-lived URL. The tab is opened
 * synchronously (inside the click) because Safari/iOS block a `window.open`
 * made after an await. Shared by the reports list and «Abrir archivo» of
 * an accommodation approved from a report.
 */
export function openReportFile(reportId: number, onError: (message: string) => void): void {
  const tab = window.open("", "_blank")
  // noopener semantics: the file's tab can't reach back into the app.
  if (tab) tab.opener = null

  reportsApi
    .fetchReportDownloadUrl(reportId)
    .then((url) => {
      if (tab) tab.location.href = url
      else onError("Tu navegador bloqueó la pestaña nueva. Permití las ventanas emergentes y volvé a intentar.")
    })
    .catch(() => {
      tab?.close()
      onError("No pudimos abrir el archivo.")
    })
}
