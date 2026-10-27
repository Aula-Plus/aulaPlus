import { render, screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import type { AccommodationProposal, ReportDocument, ReportDocumentDetail, Subject } from "@/types"
import { StudentReportsSection } from "./StudentReportsSection"
import * as subjectsApi from "@/features/subjects/subjectsApi"
import * as reportsApi from "./reportsApi"

const report: ReportDocument = {
  id: 7,
  student_id: 3,
  name: "informe.pdf",
  format: "pdf",
  size: 1000,
  visibility: "clinical",
  uploaded_at: "2026-10-20T10:00:00Z",
  status: "awaiting_review",
  use_ai: true,
}

const proposal: AccommodationProposal = {
  id: 11,
  report_document_id: 7,
  type: "Lectura en voz alta",
  category: "access",
  description: "Leerle las consignas.",
  focus_area: "lectura",
  basis: "inferred",
  source_page: 2,
  source_fragment: "se cansa al leer textos largos",
  subject_ids: [4],
  subjects_basis: "inferred",
  status: "pending",
  accommodation_id: null,
  reviewed_at: null,
}

const detail: ReportDocumentDetail = {
  ...report,
  anonymized_pages: ["[ALUMNO] se cansa al leer textos largos."],
  ai_available: true,
  proposals: [proposal],
}

const subjects = [
  { id: 4, name: "Lengua" },
  { id: 5, name: "Matemática" },
] as Subject[]

beforeEach(() => {
  vi.spyOn(subjectsApi, "fetchSubjects").mockResolvedValue(subjects)
})

afterEach(() => vi.restoreAllMocks())

describe("StudentReportsSection", () => {
  it("shows the empty message when there are no reports", async () => {
    vi.spyOn(reportsApi, "fetchReports").mockResolvedValue([])

    render(<StudentReportsSection studentId={3} canManage={false} canSeeContent={false} onAccommodationCreated={vi.fn()} />)

    expect(await screen.findByText("Todavía no hay documentos para mostrar.")).toBeInTheDocument()
    expect(screen.queryByLabelText(/Subir informe/)).not.toBeInTheDocument()
  })

  it("gives a teacher only the name and the open button for enabled files", async () => {
    vi.spyOn(reportsApi, "fetchReports").mockResolvedValue([
      { id: 8, student_id: 3, name: "plan.pdf", format: "pdf", size: 10, visibility: "teachers", uploaded_at: "2026-10-20T10:00:00Z" },
    ])

    render(<StudentReportsSection studentId={3} canManage={false} canSeeContent={false} onAccommodationCreated={vi.fn()} />)

    expect(await screen.findByText("plan.pdf")).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Abrir archivo" })).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Revisar" })).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Eliminar" })).not.toBeInTheDocument()
  })

  it("lets psychopedagogy review the anonymized text, confirm the send and approve a proposal", async () => {
    vi.spyOn(reportsApi, "fetchReports").mockResolvedValue([report])
    vi.spyOn(reportsApi, "fetchReport").mockResolvedValue(detail)
    const confirm = vi.spyOn(reportsApi, "confirmSend").mockResolvedValue({ ...detail, status: "interpreting" })
    const review = vi
      .spyOn(reportsApi, "reviewProposal")
      .mockResolvedValue({ ...proposal, status: "approved", accommodation_id: 99 })
    const created = vi.fn()

    render(<StudentReportsSection studentId={3} canManage canSeeContent onAccommodationCreated={created} />)
    await userEvent.click(await screen.findByRole("button", { name: "Revisar" }))

    expect(await screen.findByLabelText("Página 1")).toHaveValue("[ALUMNO] se cansa al leer textos largos.")
    expect(screen.getByText("se cansa al leer textos largos")).toBeInTheDocument()
    expect(screen.getByText("Página 2")).toBeInTheDocument()

    await userEvent.click(screen.getByRole("button", { name: "Confirmar y enviar a la IA" }))
    expect(confirm).toHaveBeenCalledWith(7)

    await userEvent.click(screen.getByRole("button", { name: "Aprobar" }))
    expect(review).toHaveBeenCalledWith(11, { decision: "approve" })
    expect(created).toHaveBeenCalled()
    expect(await screen.findByText("Aprobado")).toBeInTheDocument()
  })

  it("saves corrections before sending and shows the server's refusal", async () => {
    vi.spyOn(reportsApi, "fetchReports").mockResolvedValue([report])
    vi.spyOn(reportsApi, "fetchReport").mockResolvedValue({ ...detail, proposals: [] })
    const save = vi.spyOn(reportsApi, "saveAnonymizedText").mockResolvedValue({ ...detail, proposals: [] })
    vi.spyOn(reportsApi, "confirmSend").mockRejectedValue({
      response: { data: { message: "Todavía aparece el nombre del alumno o del colegio en el texto. Quitalo antes de enviarlo." } },
    })

    render(<StudentReportsSection studentId={3} canManage canSeeContent onAccommodationCreated={vi.fn()} />)
    await userEvent.click(await screen.findByRole("button", { name: "Revisar" }))
    const page = await screen.findByLabelText("Página 1")
    await userEvent.type(page, " Extra")
    await userEvent.click(screen.getByRole("button", { name: "Confirmar y enviar a la IA" }))

    expect(save).toHaveBeenCalledWith(7, ["[ALUMNO] se cansa al leer textos largos. Extra"])
    expect(await screen.findByRole("alert")).toHaveTextContent("Todavía aparece el nombre del alumno")
  })

  it("does not offer to send when the AI is off", async () => {
    vi.spyOn(reportsApi, "fetchReports").mockResolvedValue([{ ...report, status: "ready" }])
    vi.spyOn(reportsApi, "fetchReport").mockResolvedValue({ ...detail, status: "ready", ai_available: false, proposals: [] })

    render(<StudentReportsSection studentId={3} canManage canSeeContent onAccommodationCreated={vi.fn()} />)
    await userEvent.click(await screen.findByRole("button", { name: "Revisar" }))

    await screen.findByLabelText("Página 1")
    expect(screen.queryByRole("button", { name: "Confirmar y enviar a la IA" })).not.toBeInTheDocument()
  })

  it("gives direction the file but neither the review workspace nor the proposals", async () => {
    vi.spyOn(reportsApi, "fetchReports").mockResolvedValue([{ id: 7, student_id: 3, name: "informe.pdf", format: "pdf", size: 1000, visibility: "clinical", uploaded_at: "2026-10-20T10:00:00Z" }])

    render(<StudentReportsSection studentId={3} canManage={false} canSeeContent={false} onAccommodationCreated={vi.fn()} />)

    expect(await screen.findByText("informe.pdf")).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Abrir archivo" })).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Revisar" })).not.toBeInTheDocument()
  })

  it("preloads the subjects the report points to and sends the reviewer's change", async () => {
    vi.spyOn(reportsApi, "fetchReports").mockResolvedValue([report])
    vi.spyOn(reportsApi, "fetchReport").mockResolvedValue(detail)
    const review = vi.spyOn(reportsApi, "reviewProposal").mockResolvedValue({ ...proposal, status: "approved" })

    render(<StudentReportsSection studentId={3} canManage canSeeContent onAccommodationCreated={vi.fn()} />)
    await userEvent.click(await screen.findByRole("button", { name: "Revisar" }))

    expect(await screen.findByText("(inferido)")).toBeInTheDocument()
    const lengua = await screen.findByLabelText("Lengua")
    expect(lengua).toBeChecked()
    expect(screen.getByLabelText("Matemática")).not.toBeChecked()

    await userEvent.click(screen.getByLabelText("Matemática"))
    await userEvent.click(screen.getByRole("button", { name: "Aprobar" }))
    expect(review).toHaveBeenCalledWith(11, { decision: "approve", subject_ids: [4, 5] })
  })

  it("shows only the source fragment when the proposal has no real page", async () => {
    vi.spyOn(reportsApi, "fetchReports").mockResolvedValue([{ ...report, format: "docx" }])
    vi.spyOn(reportsApi, "fetchReport").mockResolvedValue({
      ...detail,
      format: "docx",
      proposals: [{ ...proposal, source_page: null }],
    })

    render(<StudentReportsSection studentId={3} canManage canSeeContent onAccommodationCreated={vi.fn()} />)
    await userEvent.click(await screen.findByRole("button", { name: "Revisar" }))

    expect(await screen.findByText("Fragmento del informe")).toBeInTheDocument()
    expect(screen.queryByText(/Página/)).not.toBeInTheDocument()
    expect(screen.getByLabelText("Texto del informe")).toBeInTheDocument()
  })

  it("offers «Volver a enviar» after a failure, reusing the reviewed text", async () => {
    vi.spyOn(reportsApi, "fetchReports").mockResolvedValue([{ ...report, status: "error" }])
    vi.spyOn(reportsApi, "fetchReport").mockResolvedValue({ ...detail, status: "error", error_message: "x", proposals: [] })
    const save = vi.spyOn(reportsApi, "saveAnonymizedText")
    const confirm = vi.spyOn(reportsApi, "confirmSend").mockResolvedValue({ ...detail, status: "interpreting", proposals: [] })

    render(<StudentReportsSection studentId={3} canManage canSeeContent onAccommodationCreated={vi.fn()} />)
    await userEvent.click(await screen.findByRole("button", { name: "Revisar" }))
    await userEvent.click(await screen.findByRole("button", { name: "Volver a enviar" }))

    expect(save).not.toHaveBeenCalled()
    expect(confirm).toHaveBeenCalledWith(7)
  })

  it("warns next to «Todos los que ven al alumno» when uploading", async () => {
    vi.spyOn(reportsApi, "fetchReports").mockResolvedValue([])
    const upload = vi.spyOn(reportsApi, "uploadReport").mockResolvedValue(report)
    vi.spyOn(reportsApi, "fetchReport").mockResolvedValue(detail)

    render(<StudentReportsSection studentId={3} canManage canSeeContent onAccommodationCreated={vi.fn()} />)
    expect(await screen.findByLabelText(/Psicopedagogía y Dirección/)).toBeChecked()
    expect(screen.getByText("Elegí esta opción solo si el archivo no tiene información clínica")).toBeInTheDocument()

    const file = new File(["%PDF"], "informe.pdf", { type: "application/pdf" })
    await userEvent.upload(screen.getByLabelText(/Subir informe \(PDF/), file)
    await userEvent.click(screen.getByLabelText(/Todos los que ven al alumno/))
    await userEvent.click(screen.getByRole("button", { name: "Subir informe" }))
    expect(upload).toHaveBeenCalledWith(3, { file, useAi: true, visibility: "teachers" })
  })

  it("warns next to «Quién lo ve» when an uploaded file is opened to everyone who sees the student", async () => {
    vi.spyOn(reportsApi, "fetchReports").mockResolvedValue([report])
    const update = vi
      .spyOn(reportsApi, "updateReport")
      .mockResolvedValue({ ...report, visibility: "teachers" })

    render(<StudentReportsSection studentId={3} canManage canSeeContent onAccommodationCreated={vi.fn()} />)
    const select = await screen.findByRole("combobox", { name: "Quién lo ve" })
    expect(select).not.toHaveAccessibleDescription()

    await userEvent.selectOptions(select, "teachers")

    expect(update).toHaveBeenCalledWith(7, { visibility: "teachers" })
    // Only a warning: nothing else to confirm.
    await waitFor(() =>
      expect(select).toHaveAccessibleDescription("Elegí esta opción solo si el archivo no tiene información clínica"),
    )
  })

  it("uploads a file with the AI choice", async () => {
    vi.spyOn(reportsApi, "fetchReports").mockResolvedValue([])
    const upload = vi.spyOn(reportsApi, "uploadReport").mockResolvedValue(report)
    vi.spyOn(reportsApi, "fetchReport").mockResolvedValue(detail)

    render(<StudentReportsSection studentId={3} canManage canSeeContent onAccommodationCreated={vi.fn()} />)
    const input = await screen.findByLabelText(/Subir informe \(PDF/)
    const file = new File(["%PDF"], "informe.pdf", { type: "application/pdf" })
    await userEvent.upload(input, file)
    await userEvent.click(screen.getByLabelText(/Usar la IA para proponer/))
    await userEvent.click(within(screen.getByRole("button", { name: "Subir informe" }).closest("form")!).getByRole("button", { name: "Subir informe" }))

    expect(upload).toHaveBeenCalledWith(3, { file, useAi: false, visibility: "clinical" })
  })

  it("opens the file in a tab created inside the click, then points it at the temporary URL", async () => {
    vi.spyOn(reportsApi, "fetchReports").mockResolvedValue([report])
    vi.spyOn(reportsApi, "fetchReportDownloadUrl").mockResolvedValue("https://files.test/informe.pdf?sig=1")
    const tab = { opener: {} as unknown, location: { href: "" }, close: vi.fn() }
    const openSpy = vi.spyOn(window, "open").mockReturnValue(tab as unknown as Window)

    render(<StudentReportsSection studentId={3} canManage canSeeContent onAccommodationCreated={vi.fn()} />)
    await userEvent.click(await screen.findByRole("button", { name: "Abrir archivo" }))

    expect(openSpy).toHaveBeenCalledWith("", "_blank")
    expect(tab.opener).toBeNull()
    await waitFor(() => expect(tab.location.href).toBe("https://files.test/informe.pdf?sig=1"))
  })

  it("keeps the list badge in step with the status the review panel loads", async () => {
    vi.spyOn(reportsApi, "fetchReports").mockResolvedValue([report])
    vi.spyOn(reportsApi, "fetchReport").mockResolvedValue({ ...detail, status: "proposed" })

    render(<StudentReportsSection studentId={3} canManage canSeeContent onAccommodationCreated={vi.fn()} />)
    expect(await screen.findByText("Falta revisar el texto anonimizado")).toBeInTheDocument()
    await userEvent.click(screen.getByRole("button", { name: "Revisar" }))

    expect(await screen.findByText("Propuestas para revisar")).toBeInTheDocument()
    expect(screen.queryByText("Falta revisar el texto anonimizado")).not.toBeInTheDocument()
  })

  it("shows why the server refused an approval", async () => {
    vi.spyOn(reportsApi, "fetchReports").mockResolvedValue([report])
    vi.spyOn(reportsApi, "fetchReport").mockResolvedValue(detail)
    vi.spyOn(reportsApi, "reviewProposal").mockRejectedValue({
      response: {
        data: {
          message: "Una de las materias propuestas ya no existe.",
          errors: { subject_ids: ["Revisá las materias antes de aprobar."] },
        },
      },
    })

    render(<StudentReportsSection studentId={3} canManage canSeeContent onAccommodationCreated={vi.fn()} />)
    await userEvent.click(await screen.findByRole("button", { name: "Revisar" }))
    await userEvent.click(await screen.findByRole("button", { name: "Aprobar" }))

    expect(await screen.findByText("Una de las materias propuestas ya no existe. Revisá las materias antes de aprobar.")).toBeInTheDocument()
  })
})
