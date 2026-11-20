import { render, screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { beforeEach, describe, expect, it, vi } from "vitest"
import type { MaterialRow } from "@/types"
import { MaterialsPage } from "./MaterialsPage"
import * as materialsApi from "./materialsApi"

vi.mock("./materialsApi")
vi.mock("@/features/auth/AuthContext", () => ({
  useAuth: () => ({ user: { id: 1, name: "Ana", email: "a@x.com", roles, school: { id: 1, name: "Colegio Modelo" } } }),
}))

let roles = ["teacher"]

const options = {
  subjects: [{ id: 3, name: "Matemática" }],
  grades: [{ id: 8, label: "5° de Primaria" }],
  groups: [{ id: 5, name: "5°A", grade_id: 8, subject_ids: [3] }],
}

function row(overrides: Partial<MaterialRow> = {}): MaterialRow {
  return {
    kind: "upload",
    id: 11,
    material_id: 11,
    assessment_id: null,
    title: "Fracciones equivalentes",
    type: "guide",
    tags: ["fracciones"],
    format: "pdf",
    group: { id: 5, name: "5°A" },
    owner_name: "Ana",
    is_mine: true,
    catalog_status: null,
    published_at: null,
    published_by_name: null,
    curricular_items: [],
    created_at: "2026-10-10T10:00:00Z",
    can: { publish: true, remove_from_catalog: false, delete: true },
    ...overrides,
  }
}

function page(data: MaterialRow[], current = 1, last = 1, total = data.length): materialsApi.MaterialsPage {
  return { data, meta: { current_page: current, last_page: last, total } }
}

function renderAt(path = "/materiales") {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <MaterialsPage />
    </MemoryRouter>,
  )
}

describe("MaterialsPage", () => {
  beforeEach(() => {
    vi.clearAllMocks()
    roles = ["teacher"]
    vi.mocked(materialsApi.fetchMaterialOptions).mockResolvedValue(options)
    vi.mocked(materialsApi.fetchMaterials).mockResolvedValue(page([row()]))
    vi.mocked(materialsApi.fetchProgramItems).mockResolvedValue([])
  })

  it("shows nothing until both subject and grade are chosen (hard prefilter)", async () => {
    renderAt()
    expect(await screen.findByText("Elegí una materia y un nivel para ver los materiales.")).toBeInTheDocument()

    await userEvent.selectOptions(await screen.findByLabelText("Materia"), "3")
    expect(materialsApi.fetchMaterials).not.toHaveBeenCalled()
    await userEvent.selectOptions(screen.getByLabelText("Nivel"), "8")

    expect(await screen.findByText("Fracciones equivalentes")).toBeInTheDocument()
    expect(materialsApi.fetchMaterials).toHaveBeenCalledWith("generated", 3, 8)
    expect(screen.getByText("Solo tuyo")).toBeInTheDocument()
  })

  it("needs «Revisé que no tenga datos de alumnos» before publishing a file", async () => {
    vi.mocked(materialsApi.publishMaterial).mockResolvedValue(row({ catalog_status: "published" }))
    renderAt("/materiales?materia=3&nivel=8")
    await screen.findByText("Fracciones equivalentes")

    await userEvent.click(screen.getByRole("button", { name: "Proponer al catálogo" }))
    const dialog = await screen.findByRole("dialog")
    const publish = within(dialog).getByRole("button", { name: "Publicar" })
    expect(publish).toBeDisabled()

    await userEvent.click(within(dialog).getByLabelText("Revisé que no tenga datos de alumnos."))
    await userEvent.click(publish)

    await waitFor(() => expect(materialsApi.publishMaterial).toHaveBeenCalledWith(11))
  })

  it("opens the catalog for everyone and lets Psicopedagogía take a material out", async () => {
    roles = ["psychopedagogue"]
    vi.mocked(materialsApi.fetchMaterials).mockResolvedValue(
      page([
        row({
          group: null,
          is_mine: false,
          catalog_status: "published",
          published_by_name: "Ana",
          can: { publish: false, remove_from_catalog: true, delete: false },
        }),
      ]),
    )
    vi.mocked(materialsApi.removeFromCatalog).mockResolvedValue(row({ catalog_status: "removed" }))
    renderAt("/materiales?materia=3&nivel=8&solapa=institucional")

    expect(await screen.findByText("Catálogo institucional — Matemática, 5° de Primaria")).toBeInTheDocument()
    expect(materialsApi.fetchMaterials).toHaveBeenCalledWith("catalog", 3, 8)

    await userEvent.click(screen.getByRole("button", { name: "Sacar del catálogo" }))
    const dialog = await screen.findByRole("dialog")
    await userEvent.click(within(dialog).getByRole("button", { name: "Sacar del catálogo" }))
    await waitFor(() => expect(materialsApi.removeFromCatalog).toHaveBeenCalledWith(11))
  })

  it("loads the next page with «Ver más»", async () => {
    vi.mocked(materialsApi.fetchMaterials)
      .mockResolvedValueOnce(page([row()], 1, 2, 51))
      .mockResolvedValueOnce(page([row({ id: 12, material_id: 12, title: "Guía de decimales" })], 2, 2, 51))
    renderAt("/materiales?materia=3&nivel=8")
    await screen.findByText("Fracciones equivalentes")

    await userEvent.click(screen.getByRole("button", { name: "Ver más (1 de 51)" }))

    expect(await screen.findByText("Guía de decimales")).toBeInTheDocument()
    expect(screen.getByText("Fracciones equivalentes")).toBeInTheDocument()
    expect(materialsApi.fetchMaterials).toHaveBeenLastCalledWith("generated", 3, 8, 2)
    expect(screen.queryByRole("button", { name: /Ver más/ })).not.toBeInTheDocument()
  })

  it("shows the server's reason when an action is refused", async () => {
    vi.mocked(materialsApi.deleteMaterial).mockRejectedValue({
      response: { status: 403, data: { message: "Este material no es tuyo." } },
    })
    renderAt("/materiales?materia=3&nivel=8")
    await screen.findByText("Fracciones equivalentes")

    await userEvent.click(screen.getByRole("button", { name: "Eliminar" }))
    const dialog = await screen.findByRole("dialog")
    await userEvent.click(within(dialog).getByRole("button", { name: "Eliminar" }))

    expect(await screen.findByText("Este material no es tuyo.")).toBeInTheDocument()
  })

  it("does not offer Dirección an upload", async () => {
    roles = ["director"]
    renderAt()
    await screen.findByText("Elegí una materia y un nivel para ver los materiales.")
    expect(screen.queryByRole("button", { name: /Subir un recurso propio/ })).not.toBeInTheDocument()
  })

  it("uploads a material with the title help and says when the program has no elements", async () => {
    vi.mocked(materialsApi.uploadMaterial).mockResolvedValue(row())
    renderAt("/materiales?materia=3&nivel=8")
    await userEvent.click(await screen.findByRole("button", { name: /Subir un recurso propio/ }))

    expect(
      screen.getByText("Ponele palabras clave sobre los contenidos de este material para que sea fácil de ubicar."),
    ).toBeInTheDocument()
    expect(
      await screen.findByText("Todavía no hay elementos del programa cargados para esta materia."),
    ).toBeInTheDocument()

    const file = new File(["%PDF-1.4"], "guia.pdf", { type: "application/pdf" })
    await userEvent.upload(screen.getByLabelText("Archivo"), file)
    await userEvent.type(screen.getByLabelText("Título"), "Fracciones equivalentes")
    await userEvent.type(screen.getByLabelText("Etiquetas"), "fracciones, 5°")
    await userEvent.click(screen.getByRole("button", { name: "Guardar en mis materiales" }))

    await waitFor(() =>
      expect(materialsApi.uploadMaterial).toHaveBeenCalledWith(
        expect.objectContaining({
          groupId: 5,
          subjectId: 3,
          title: "Fracciones equivalentes",
          tags: ["fracciones", "5°"],
          publish: false,
        }),
      ),
    )
  })
})
