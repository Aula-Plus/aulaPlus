import { render, screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, describe, expect, it, vi } from "vitest"
import type { AccommodationProposal, ReportDocument, ReportDocumentDetail } from "@/types"
import { StudentReportsSection } from "./StudentReportsSection"
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

  it("lets direction see the proposals but not decide them", async () => {
    vi.spyOn(reportsApi, "fetchReports").mockResolvedValue([report])
    vi.spyOn(reportsApi, "fetchReport").mockResolvedValue(detail)

    render(<StudentReportsSection studentId={3} canManage={false} canSeeContent onAccommodationCreated={vi.fn()} />)
    await userEvent.click(await screen.findByRole("button", { name: "Revisar" }))

    expect(await screen.findByText("Lectura en voz alta")).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Aprobar" })).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Confirmar y enviar a la IA" })).not.toBeInTheDocument()
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

    expect(upload).toHaveBeenCalledWith(3, { file, useAi: false })
  })
})
