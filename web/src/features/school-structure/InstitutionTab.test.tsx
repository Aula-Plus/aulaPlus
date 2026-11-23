import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, describe, expect, it, vi } from "vitest"
import * as spaceApi from "@/features/psychopedagogy-space/psychopedagogySpaceApi"
import { InstitutionTab } from "./InstitutionTab"

const types = [
  { id: 1, name: "Reunión de profesores", position: 0 },
  { id: 2, name: "Entrevista con familia", position: 1 },
  { id: 3, name: "Reunión con equipo tratante", position: 2 },
]

describe("InstitutionTab", () => {
  afterEach(() => vi.restoreAllMocks())

  it("lets Dirección rename a sheet type and add a new one", async () => {
    vi.spyOn(spaceApi, "fetchMeetingSheetTypes").mockResolvedValue(types)
    const rename = vi
      .spyOn(spaceApi, "renameMeetingSheetType")
      .mockResolvedValue({ id: 1, name: "Reunión docente", position: 0 })
    const create = vi
      .spyOn(spaceApi, "createMeetingSheetType")
      .mockResolvedValue({ id: 4, name: "Ateneo clínico", position: 3 })
    render(<InstitutionTab />)

    const first = await screen.findByLabelText("Tipo 1")
    expect(first).toHaveValue("Reunión de profesores")
    expect(screen.queryByRole("button", { name: /Eliminar|Borrar/ })).not.toBeInTheDocument()

    await userEvent.clear(first)
    await userEvent.type(first, "Reunión docente")
    await userEvent.click(screen.getAllByRole("button", { name: "Renombrar" })[0])
    expect(rename).toHaveBeenCalledWith(1, "Reunión docente")

    await userEvent.type(screen.getByLabelText("Tipo nuevo"), "Ateneo clínico")
    await userEvent.click(screen.getByRole("button", { name: "Agregar tipo" }))
    expect(create).toHaveBeenCalledWith("Ateneo clínico")
    expect(await screen.findByLabelText("Tipo 4")).toHaveValue("Ateneo clínico")
  })
})
