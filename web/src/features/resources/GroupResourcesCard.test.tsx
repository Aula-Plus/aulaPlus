import { render, screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { beforeEach, describe, expect, it, vi } from "vitest"
import { GroupResourcesCard } from "./GroupResourcesCard"
import * as resourcesApi from "./resourcesApi"
import type { GroupResources } from "./resourcesApi"

vi.mock("./resourcesApi")

const payload: GroupResources = {
  can_manage: true,
  can_add_own: true,
  school: [{ id: 1, name: "Huerta escolar", available: true }],
  group: [],
}

describe("GroupResourcesCard", () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(resourcesApi.fetchGroupResources).mockResolvedValue(payload)
  })

  it("lets the group teacher uncheck a school resource and add one for the whole group", async () => {
    vi.mocked(resourcesApi.setSchoolResourceAvailable).mockResolvedValue({
      ...payload,
      school: [{ id: 1, name: "Huerta escolar", available: false }],
    })
    vi.mocked(resourcesApi.createGroupResource).mockResolvedValue({
      ...payload,
      group: [{ id: 5, name: "Tele en el salón", visibility: "group", created_by_name: "Ana", can_remove: true }],
    })
    render(<GroupResourcesCard groupId={3} />)

    await userEvent.click(await screen.findByLabelText("Huerta escolar"))
    await waitFor(() => expect(resourcesApi.setSchoolResourceAvailable).toHaveBeenCalledWith(3, 1, false))

    expect(screen.getByLabelText("Para todo el grupo")).toBeChecked()
    await userEvent.type(screen.getByLabelText("Agregar un recurso del grupo"), "Tele en el salón")
    await userEvent.click(screen.getByRole("button", { name: "Agregar" }))
    expect(await screen.findByText("Tele en el salón")).toBeInTheDocument()
    expect(screen.getByText("· lo sumó Ana")).toBeInTheDocument()
    expect(resourcesApi.createGroupResource).toHaveBeenCalledWith(3, "Tele en el salón", "group")
  })

  it("lets a teacher add a resource only for themself", async () => {
    vi.mocked(resourcesApi.createGroupResource).mockResolvedValue({
      ...payload,
      group: [{ id: 6, name: "Mi parlante", visibility: "own", created_by_name: "Ana", can_remove: true }],
    })
    render(<GroupResourcesCard groupId={3} />)

    await userEvent.click(await screen.findByLabelText("Solo para mí"))
    expect(screen.getByText(/Entre los docentes, solo lo ves vos/)).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText("Agregar un recurso del grupo"), "Mi parlante")
    await userEvent.click(screen.getByRole("button", { name: "Agregar" }))

    expect(resourcesApi.createGroupResource).toHaveBeenCalledWith(3, "Mi parlante", "own")
    expect(await screen.findByText("· solo de Ana")).toBeInTheDocument()
    // The choice goes back to the whole group for the next one.
    expect(screen.getByLabelText("Para todo el grupo")).toBeChecked()
  })

  it("offers no own resources to Dirección and Psicopedagogía, and shows a teacher's own one without removing it", async () => {
    vi.mocked(resourcesApi.fetchGroupResources).mockResolvedValue({
      ...payload,
      can_add_own: false,
      group: [
        { id: 5, name: "Tele", visibility: "group", created_by_name: "Ana", can_remove: true },
        { id: 6, name: "Mi parlante", visibility: "own", created_by_name: "Ana", can_remove: false },
      ],
    })
    vi.mocked(resourcesApi.createGroupResource).mockResolvedValue(payload)
    render(<GroupResourcesCard groupId={3} />)

    expect(await screen.findByText("· solo de Ana")).toBeInTheDocument()
    expect(screen.queryByLabelText("Solo para mí")).not.toBeInTheDocument()
    expect(screen.getByText("Lo que sumes lo ven todos los docentes del grupo.")).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Quitar Tele" })).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Quitar Mi parlante" })).not.toBeInTheDocument()

    await userEvent.type(screen.getByLabelText("Agregar un recurso del grupo"), "Proyector")
    await userEvent.click(screen.getByRole("button", { name: "Agregar" }))
    expect(resourcesApi.createGroupResource).toHaveBeenCalledWith(3, "Proyector", "group")
  })

  it("is read-only when the server says so (closed year)", async () => {
    vi.mocked(resourcesApi.fetchGroupResources).mockResolvedValue({ ...payload, can_manage: false, can_add_own: false })
    render(<GroupResourcesCard groupId={3} />)

    expect(await screen.findByLabelText("Huerta escolar")).toBeDisabled()
    expect(screen.queryByLabelText("Agregar un recurso del grupo")).not.toBeInTheDocument()
  })
})
