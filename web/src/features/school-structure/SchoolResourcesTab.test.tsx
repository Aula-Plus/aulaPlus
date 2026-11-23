import { render, screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { beforeEach, describe, expect, it, vi } from "vitest"
import { SchoolResourcesTab } from "./SchoolResourcesTab"
import * as resourcesApi from "@/features/resources/resourcesApi"

vi.mock("@/features/resources/resourcesApi")

describe("SchoolResourcesTab", () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(resourcesApi.fetchSchoolResources).mockResolvedValue([{ id: 1, name: "Huerta escolar" }])
  })

  it("adds and removes the school resources", async () => {
    vi.mocked(resourcesApi.createSchoolResource).mockResolvedValue({ id: 2, name: "Laboratorio de ciencias" })
    vi.mocked(resourcesApi.deleteSchoolResource).mockResolvedValue()
    render(<SchoolResourcesTab />)

    expect(await screen.findByText("Huerta escolar")).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText("Agregar un recurso"), "Laboratorio de ciencias")
    await userEvent.click(screen.getByRole("button", { name: "Agregar" }))
    expect(await screen.findByText("Laboratorio de ciencias")).toBeInTheDocument()

    await userEvent.click(screen.getByRole("button", { name: "Quitar Huerta escolar" }))
    await waitFor(() => expect(screen.queryByText("Huerta escolar")).not.toBeInTheDocument())
    expect(resourcesApi.deleteSchoolResource).toHaveBeenCalledWith(1)
  })
})
