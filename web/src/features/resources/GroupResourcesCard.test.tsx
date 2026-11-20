import { render, screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { beforeEach, describe, expect, it, vi } from "vitest"
import { GroupResourcesCard } from "./GroupResourcesCard"
import * as resourcesApi from "./resourcesApi"

vi.mock("./resourcesApi")

const payload = {
  can_manage: true,
  school: [{ id: 1, name: "Huerta escolar", available: true }],
  group: [],
}

describe("GroupResourcesCard", () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(resourcesApi.fetchGroupResources).mockResolvedValue(payload)
  })

  it("lets the group teacher uncheck a school resource and add the group own", async () => {
    vi.mocked(resourcesApi.setSchoolResourceAvailable).mockResolvedValue({
      ...payload,
      school: [{ id: 1, name: "Huerta escolar", available: false }],
    })
    vi.mocked(resourcesApi.createGroupResource).mockResolvedValue({
      ...payload,
      group: [{ id: 5, name: "Tele en el salón", created_by_name: "Ana" }],
    })
    render(<GroupResourcesCard groupId={3} />)

    await userEvent.click(await screen.findByLabelText("Huerta escolar"))
    await waitFor(() => expect(resourcesApi.setSchoolResourceAvailable).toHaveBeenCalledWith(3, 1, false))

    await userEvent.type(screen.getByLabelText("Agregar un recurso del grupo"), "Tele en el salón")
    await userEvent.click(screen.getByRole("button", { name: "Agregar" }))
    expect(await screen.findByText("Tele en el salón")).toBeInTheDocument()
    expect(resourcesApi.createGroupResource).toHaveBeenCalledWith(3, "Tele en el salón")
  })

  it("is read-only when the server says so (Psicopedagogía)", async () => {
    vi.mocked(resourcesApi.fetchGroupResources).mockResolvedValue({ ...payload, can_manage: false })
    render(<GroupResourcesCard groupId={3} />)

    expect(await screen.findByLabelText("Huerta escolar")).toBeDisabled()
    expect(screen.queryByLabelText("Agregar un recurso del grupo")).not.toBeInTheDocument()
  })
})
