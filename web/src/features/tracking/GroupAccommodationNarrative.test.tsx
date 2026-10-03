import { render, screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, describe, expect, it, vi } from "vitest"
import type { AccommodationNarrativeView } from "@/types"
import { GroupAccommodationNarrative } from "./GroupAccommodationNarrative"
import * as trackingApi from "./trackingApi"

function view(overrides: Partial<AccommodationNarrativeView> = {}): AccommodationNarrativeView {
  return { can_generate: false, published: null, draft: null, ...overrides }
}

const published = {
  id: 1,
  content: "En este grupo, 5 alumnos tienen tiempo extendido.",
  published_at: "2026-09-28T12:00:00Z",
  published_by: "Galia Cohen",
  outdated: false,
}

describe("GroupAccommodationNarrative", () => {
  afterEach(() => vi.restoreAllMocks())

  it("shows the published text with who and when, and warns when outdated", async () => {
    vi.spyOn(trackingApi, "fetchAccommodationNarrative").mockResolvedValue(
      view({ published: { ...published, outdated: true } }),
    )
    render(<GroupAccommodationNarrative groupId={1} canSummarise />)

    expect(await screen.findByText(published.content)).toBeInTheDocument()
    expect(screen.getByText(/Publicado por Galia Cohen/)).toBeInTheDocument()
    expect(screen.getByRole("status")).toHaveTextContent(/puede estar desactualizado/)
    expect(screen.queryByRole("button")).not.toBeInTheDocument()
  })

  it("renders nothing for a reader when nothing is published", async () => {
    const spy = vi.spyOn(trackingApi, "fetchAccommodationNarrative").mockResolvedValue(view())
    const { container } = render(<GroupAccommodationNarrative groupId={1} canSummarise />)
    await waitFor(() => expect(spy).toHaveBeenCalled())
    expect(container).toBeEmptyDOMElement()
  })

  it("lets psychopedagogy generate a summary", async () => {
    vi.spyOn(trackingApi, "fetchAccommodationNarrative").mockResolvedValue(view({ can_generate: true }))
    const generate = vi.spyOn(trackingApi, "generateAccommodationNarrative").mockResolvedValue()
    render(<GroupAccommodationNarrative groupId={7} canSummarise />)

    await userEvent.click(await screen.findByRole("button", { name: "Generar resumen" }))
    expect(generate).toHaveBeenCalledWith(7)
  })

  it("lets psychopedagogy read the draft and publish it", async () => {
    vi.spyOn(trackingApi, "fetchAccommodationNarrative").mockResolvedValue(
      view({
        can_generate: true,
        draft: { id: 9, status: "draft", content: "Borrador de prueba", error_message: null, outdated: false },
      }),
    )
    const publish = vi.spyOn(trackingApi, "publishAccommodationNarrative").mockResolvedValue()
    render(<GroupAccommodationNarrative groupId={7} canSummarise />)

    expect(await screen.findByText("Borrador de prueba")).toBeInTheDocument()
    await userEvent.click(screen.getByRole("button", { name: /publicar para los docentes/i }))
    expect(publish).toHaveBeenCalledWith(7, 9)
  })

  it("disables generation when the group has no accommodations", async () => {
    vi.spyOn(trackingApi, "fetchAccommodationNarrative").mockResolvedValue(view({ can_generate: true }))
    render(<GroupAccommodationNarrative groupId={1} canSummarise={false} />)

    expect(await screen.findByRole("button", { name: "Generar resumen" })).toBeDisabled()
  })
})
