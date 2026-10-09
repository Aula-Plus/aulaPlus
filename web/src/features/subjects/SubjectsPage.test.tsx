import { render, screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { describe, expect, it, vi, beforeEach } from "vitest"
import { SubjectsPage } from "./SubjectsPage"
import * as subjectsApi from "./subjectsApi"

vi.mock("./subjectsApi")
// `User.roles` is an array (see src/types.ts), so the mocked director carries
// `roles: ["director"]` — a bare `role` string would not satisfy isDirector.
vi.mock("@/features/auth/AuthContext", () => ({
  useAuth: () => ({ user: { id: 1, name: "Dir", email: "d@x.com", roles } }),
}))

let roles = ["director"]

describe("SubjectsPage", () => {
  beforeEach(() => {
    vi.clearAllMocks()
    roles = ["director"]
    vi.mocked(subjectsApi.fetchSubjects).mockResolvedValue([
      { id: 1, name: "Matemática", short_code: "MAT", color: null },
    ])
    vi.mocked(subjectsApi.createSubject).mockResolvedValue({
      id: 2,
      name: "Inglés",
      short_code: null,
      color: null,
    })
  })

  it("lists existing subjects", async () => {
    render(<SubjectsPage />)
    expect(await screen.findByText("Matemática")).toBeInTheDocument()
  })

  it("creates a subject", async () => {
    render(<SubjectsPage />)
    await screen.findByText("Matemática")

    await userEvent.type(screen.getByLabelText("Nombre"), "Inglés")
    await userEvent.click(screen.getByRole("button", { name: /crear materia/i }))

    await waitFor(() =>
      expect(subjectsApi.createSubject).toHaveBeenCalledWith(
        expect.objectContaining({ name: "Inglés" }),
      ),
    )
  })

  it("uploads the curricular program PDF right after creating the subject", async () => {
    vi.mocked(subjectsApi.uploadSyllabus).mockResolvedValue({
      id: 2, name: "Ayuda Social", short_code: null, color: null,
    })
    render(<SubjectsPage />)
    await screen.findByText("Matemática")

    const pdf = new File(["%PDF-1.4"], "Programa Ayuda Social 2027.pdf", { type: "application/pdf" })
    await userEvent.type(screen.getByLabelText("Nombre"), "Ayuda Social")
    await userEvent.upload(screen.getByLabelText(/Programa curricular \(PDF/), pdf)
    await userEvent.click(screen.getByRole("button", { name: /crear materia/i }))

    await waitFor(() => expect(subjectsApi.uploadSyllabus).toHaveBeenCalledWith(2, pdf))
  })

  it("rejects a non-PDF program before uploading", async () => {
    render(<SubjectsPage />)
    await screen.findByText("Matemática")

    const doc = new File(["x"], "programa.docx", { type: "application/msword" })
    await userEvent.type(screen.getByLabelText("Nombre"), "Ayuda Social")
    await userEvent.upload(screen.getByLabelText(/Programa curricular \(PDF/), doc, { applyAccept: false })
    await userEvent.click(screen.getByRole("button", { name: /crear materia/i }))

    expect(await screen.findByText("El programa tiene que ser un PDF.")).toBeInTheDocument()
    expect(subjectsApi.createSubject).not.toHaveBeenCalled()
  })

  it("lets a teacher open the program of a subject they teach, read-only", async () => {
    roles = ["teacher"]
    vi.mocked(subjectsApi.fetchSubjects).mockResolvedValue([
      {
        id: 1,
        name: "Matemática",
        short_code: null,
        color: null,
        in_catalog: false,
        syllabus: {
          name: "Programa 2027.pdf",
          size: 1000,
          uploaded_at: null,
          text_extracted: true,
          can_view: true,
        },
      },
    ])
    vi.mocked(subjectsApi.fetchSyllabusUrl).mockResolvedValue("https://files.test/x")
    const open = vi.spyOn(window, "open").mockReturnValue(null)
    render(<SubjectsPage />)

    expect(await screen.findByText("Programa: Programa 2027.pdf")).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: /crear materia/i })).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Reemplazar PDF" })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole("button", { name: "Ver programa" }))
    await waitFor(() => expect(open).toHaveBeenCalledWith("https://files.test/x", "_blank", "noopener,noreferrer"))
  })
})
