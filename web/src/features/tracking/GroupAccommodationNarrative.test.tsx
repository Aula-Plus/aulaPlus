import { act, render, screen, waitFor } from "@testing-library/react"
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
  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

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
    expect(screen.getByText("Borrador (solo lo ve psicopedagogía)")).toBeInTheDocument()
    await userEvent.click(screen.getByRole("button", { name: /publicar para los docentes/i }))
    expect(publish).toHaveBeenCalledWith(7, 9)
  })

  it("explains when an accommodation names a student instead of asking to retry", async () => {
    vi.spyOn(trackingApi, "fetchAccommodationNarrative").mockResolvedValue(
      view({
        can_generate: true,
        draft: { id: 9, status: "error", content: null, error_message: "x", name_in_data: true, outdated: false },
      }),
    )
    render(<GroupAccommodationNarrative groupId={7} canSummarise />)

    expect(await screen.findByText(/menciona el nombre de un alumno/i)).toBeInTheDocument()
    expect(screen.queryByText(/probá de nuevo/i)).not.toBeInTheDocument()
  })

  it("names the accommodation to fix, as redacted by the backend", async () => {
    vi.spyOn(trackingApi, "fetchAccommodationNarrative").mockResolvedValue(
      view({
        can_generate: true,
        draft: {
          id: 9,
          status: "error",
          content: null,
          error_message: "x",
          name_in_data: true,
          flagged_types: ["lectura en voz alta para …"],
          outdated: false,
        },
      }),
    )
    render(<GroupAccommodationNarrative groupId={7} canSummarise />)

    expect(await screen.findByText(/este ajuste menciona el nombre de un alumno/i)).toBeInTheDocument()
    expect(screen.getByText("«lectura en voz alta para …»")).toBeInTheDocument()
  })

  it("explains a generation that never finished and lets it be generated again", async () => {
    vi.spyOn(trackingApi, "fetchAccommodationNarrative").mockResolvedValue(
      view({
        can_generate: true,
        draft: { id: 9, status: "error", content: null, error_message: "x", stale: true, outdated: false },
      }),
    )
    render(<GroupAccommodationNarrative groupId={7} canSummarise />)

    expect(await screen.findByText(/la generación anterior no terminó/i)).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Generar nuevo resumen" })).toBeEnabled()
  })

  it("stops polling a generation that takes too long and lets it be retried", async () => {
    vi.useFakeTimers()
    const fetch = vi.spyOn(trackingApi, "fetchAccommodationNarrative").mockResolvedValue(
      view({
        can_generate: true,
        draft: { id: 9, status: "pending", content: null, error_message: null, outdated: false },
      }),
    )
    render(<GroupAccommodationNarrative groupId={7} canSummarise />)
    await act(() => vi.advanceTimersByTimeAsync(0))

    expect(screen.getByText("Generando resumen…")).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Generar nuevo resumen" })).toBeDisabled()

    // One poll at a time, so React re-renders between them as in a browser.
    for (let i = 0; i < 150; i++) await act(() => vi.advanceTimersByTimeAsync(2000))
    const calls = fetch.mock.calls.length
    expect(calls).toBeLessThan(100)
    for (let i = 0; i < 30; i++) await act(() => vi.advanceTimersByTimeAsync(2000))
    expect(fetch).toHaveBeenCalledTimes(calls)

    expect(screen.getByText(/está tardando más de lo esperado/i)).toBeInTheDocument()
    expect(screen.getByRole("button", { name: "Generar nuevo resumen" })).toBeEnabled()
  })

  it("disables generation when the group has no accommodations", async () => {
    vi.spyOn(trackingApi, "fetchAccommodationNarrative").mockResolvedValue(view({ can_generate: true }))
    render(<GroupAccommodationNarrative groupId={1} canSummarise={false} />)

    expect(await screen.findByRole("button", { name: "Generar resumen" })).toBeDisabled()
  })
})
