import { act, render, screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { AxiosError, AxiosHeaders } from "axios"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import type { Subject, SyllabusTextStatus } from "@/types"
import { SYLLABUS_MAX_POLLS, SYLLABUS_POLL_INTERVAL_MS, SubjectSyllabus } from "./SubjectSyllabus"
import * as subjectsApi from "./subjectsApi"

vi.mock("./subjectsApi")

function subjectWith(status: SyllabusTextStatus, canView = true): Subject {
  return {
    id: 7,
    name: "Ayuda Social",
    short_code: null,
    color: null,
    in_catalog: false,
    syllabus: {
      name: "Programa.pdf",
      size: 1000,
      uploaded_at: "2026-10-10T12:00:00Z",
      text_status: status,
      can_view: canView,
    },
  }
}

describe("SubjectSyllabus", () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

  it("shows «Procesando…» while pending and refetches until the text is ready", async () => {
    vi.useFakeTimers()
    vi.mocked(subjectsApi.fetchSubject)
      .mockResolvedValueOnce(subjectWith("pending"))
      .mockResolvedValueOnce(subjectWith("ready"))
    const onChange = vi.fn()
    const { rerender } = render(<SubjectSyllabus subject={subjectWith("pending")} canManage onChange={onChange} />)

    expect(screen.getByText("Procesando el PDF…")).toBeInTheDocument()
    expect(screen.queryByText(/No pudimos leer el texto/)).not.toBeInTheDocument()

    await act(() => vi.advanceTimersByTimeAsync(SYLLABUS_POLL_INTERVAL_MS))
    expect(onChange).not.toHaveBeenCalled()
    await act(() => vi.advanceTimersByTimeAsync(SYLLABUS_POLL_INTERVAL_MS))
    expect(subjectsApi.fetchSubject).toHaveBeenCalledTimes(2)
    expect(onChange).toHaveBeenCalledWith(subjectWith("ready"))

    rerender(<SubjectSyllabus subject={subjectWith("ready")} canManage onChange={onChange} />)
    expect(screen.getByText(/La IA usa este programa/)).toBeInTheDocument()
    await act(() => vi.advanceTimersByTimeAsync(SYLLABUS_POLL_INTERVAL_MS * 3))
    expect(subjectsApi.fetchSubject).toHaveBeenCalledTimes(2)
  })

  it("stops polling after a bounded number of attempts", async () => {
    vi.useFakeTimers()
    vi.mocked(subjectsApi.fetchSubject).mockResolvedValue(subjectWith("pending"))
    render(<SubjectSyllabus subject={subjectWith("pending")} canManage={false} onChange={vi.fn()} />)

    await act(() => vi.advanceTimersByTimeAsync(SYLLABUS_POLL_INTERVAL_MS * (SYLLABUS_MAX_POLLS + 5)))

    expect(subjectsApi.fetchSubject).toHaveBeenCalledTimes(SYLLABUS_MAX_POLLS)
    expect(screen.getByText(/Seguimos procesando el PDF/)).toBeInTheDocument()
  })

  it("says the PDF could not be read only when the extraction failed", () => {
    render(<SubjectSyllabus subject={subjectWith("failed")} canManage={false} onChange={vi.fn()} />)

    expect(screen.getByText(/No pudimos leer el texto de este PDF/)).toBeInTheDocument()
    expect(subjectsApi.fetchSubject).not.toHaveBeenCalled()
  })

  it("opens the tab synchronously on click, then points it at the temporary URL", async () => {
    let resolveUrl: (url: string) => void = () => {}
    vi.mocked(subjectsApi.fetchSyllabusUrl).mockReturnValue(new Promise((resolve) => (resolveUrl = resolve)))
    const tab = { opener: {} as unknown, location: { href: "" }, close: vi.fn() }
    const open = vi.spyOn(window, "open").mockReturnValue(tab as unknown as Window)
    render(<SubjectSyllabus subject={subjectWith("ready")} canManage={false} onChange={vi.fn()} />)

    await userEvent.click(screen.getByRole("button", { name: "Ver programa" }))

    // Opened before the URL arrived (Safari blocks a window.open after an await).
    expect(open).toHaveBeenCalledWith("", "_blank")
    expect(tab.opener).toBeNull()
    expect(tab.location.href).toBe("")

    resolveUrl("https://files.test/x")
    await waitFor(() => expect(tab.location.href).toBe("https://files.test/x"))
    expect(tab.close).not.toHaveBeenCalled()
  })

  it("closes the tab and shows an error when the URL can't be fetched", async () => {
    vi.mocked(subjectsApi.fetchSyllabusUrl).mockRejectedValue(new Error("403"))
    const tab = { opener: {}, location: { href: "" }, close: vi.fn() }
    vi.spyOn(window, "open").mockReturnValue(tab as unknown as Window)
    render(<SubjectSyllabus subject={subjectWith("ready")} canManage={false} onChange={vi.fn()} />)

    await userEvent.click(screen.getByRole("button", { name: "Ver programa" }))

    expect(await screen.findByRole("alert")).toHaveTextContent("No pudimos abrir el programa.")
    expect(tab.close).toHaveBeenCalled()
  })

  it("offers a plain link when the browser blocked the new tab", async () => {
    vi.mocked(subjectsApi.fetchSyllabusUrl).mockResolvedValue("https://files.test/x")
    vi.spyOn(window, "open").mockReturnValue(null)
    render(<SubjectSyllabus subject={subjectWith("ready")} canManage={false} onChange={vi.fn()} />)

    await userEvent.click(screen.getByRole("button", { name: "Ver programa" }))

    const link = await screen.findByRole("link", { name: "Abrir el programa" })
    expect(link).toHaveAttribute("href", "https://files.test/x")
    expect(link).toHaveAttribute("rel", "noopener noreferrer")
  })

  it("tells direction not to include student data, and shows the storage error from the backend", async () => {
    const response = {
      status: 503,
      statusText: "Service Unavailable",
      headers: {},
      config: { headers: new AxiosHeaders() },
      data: { message: "Por ahora no se pueden subir programas: falta configurar el almacenamiento de archivos." },
    }
    vi.mocked(subjectsApi.uploadSyllabus).mockRejectedValue(
      new AxiosError("503", "ERR_BAD_RESPONSE", undefined, undefined, response),
    )
    render(<SubjectSyllabus subject={{ ...subjectWith("ready"), syllabus: null }} canManage onChange={vi.fn()} />)

    expect(screen.getByText(/no debe incluir datos de alumnos/)).toBeInTheDocument()

    const pdf = new File(["%PDF-1.4"], "Programa.pdf", { type: "application/pdf" })
    await userEvent.upload(screen.getByLabelText("Programa curricular de Ayuda Social"), pdf)

    expect(await screen.findByRole("alert")).toHaveTextContent(/falta configurar el almacenamiento/)
  })
})
