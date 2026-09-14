import { fireEvent, render, screen, waitFor } from "@testing-library/react"
import { afterEach, describe, expect, it, vi } from "vitest"
import { ScreeningTestResultsEditor } from "./ScreeningTestResultsEditor"
import * as screeningApi from "./screeningTestsApi"
import type { ScreeningTestResult } from "@/types"

function result(overrides: Partial<ScreeningTestResult> = {}): ScreeningTestResult {
  return {
    id: 100,
    screening_test_application_id: 1,
    code: "A1",
    score: null,
    color: null,
    loaded_by_id: null,
    loaded_at: null,
    ...overrides,
  }
}

describe("ScreeningTestResultsEditor", () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("renders rows by code only — no student name reaches or is shown by the table", async () => {
    vi.spyOn(screeningApi, "fetchScreeningTestResults").mockResolvedValue([
      result({ id: 100, code: "A1" }),
      result({ id: 101, code: "B2" }),
    ])

    render(<ScreeningTestResultsEditor applicationId={1} />)

    expect(await screen.findByText("A1")).toBeInTheDocument()
    expect(screen.getByText("B2")).toBeInTheDocument()
    // The by-code editor must never surface a name; the results resource carries
    // none, so a plausible student name is nowhere in the DOM.
    expect(screen.queryByText(/juan|pérez|gómez/i)).not.toBeInTheDocument()
    // Inputs are labelled by code, not by name.
    expect(screen.getByLabelText("Puntaje del código A1")).toBeInTheDocument()
  })

  it("PATCHes the score on blur and paints the backend-confirmed colour (never recomputed)", async () => {
    vi.spyOn(screeningApi, "fetchScreeningTestResults").mockResolvedValue([result({ id: 100 })])
    const update = vi
      .spyOn(screeningApi, "updateScreeningTestResult")
      // A score of 8 with cutoffs {4,7} would be "green"; we return "red" on
      // purpose to prove the UI trusts the backend colour rather than deriving it.
      .mockResolvedValue(result({ id: 100, score: 8, color: "red" }))

    render(<ScreeningTestResultsEditor applicationId={1} />)

    const input = await screen.findByLabelText("Puntaje del código A1")
    fireEvent.change(input, { target: { value: "8" } })
    fireEvent.blur(input)

    await waitFor(() => expect(update).toHaveBeenCalledWith(100, 8))
    expect(await screen.findByText("Rojo")).toBeInTheDocument()
  })

  it("does not PATCH when the input is left empty", async () => {
    vi.spyOn(screeningApi, "fetchScreeningTestResults").mockResolvedValue([result({ id: 100 })])
    const update = vi.spyOn(screeningApi, "updateScreeningTestResult")

    render(<ScreeningTestResultsEditor applicationId={1} />)

    const input = await screen.findByLabelText("Puntaje del código A1")
    fireEvent.blur(input)

    expect(update).not.toHaveBeenCalled()
  })
})
