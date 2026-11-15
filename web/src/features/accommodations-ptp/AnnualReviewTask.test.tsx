import { render, screen } from "@testing-library/react"
import { MemoryRouter } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import type { Role } from "@/types"
import * as ptpApi from "./accommodationsPtpApi"
import { AnnualReviewTask } from "./AnnualReviewTask"

function renderTask(role: Role) {
  return render(
    <MemoryRouter>
      <AnnualReviewTask user={{ id: 1, name: "Laura", email: "l@escuela.test", roles: [role] }} />
    </MemoryRouter>,
  )
}

describe("AnnualReviewTask", () => {
  afterEach(() => vi.restoreAllMocks())

  it("shows psychopedagogy one row with how many reviews are missing, opening the tab", async () => {
    vi.spyOn(ptpApi, "fetchPendingAnnualReviews").mockResolvedValue(13)

    renderTask("psychopedagogue")

    expect(await screen.findByText("Revisión anual: 13 alumnos")).toBeInTheDocument()
    expect(screen.getByRole("link", { name: "Abrir la revisión anual" })).toHaveAttribute(
      "href",
      "/ajustes-y-ptp?solapa=revision-anual",
    )
  })

  it("is not there when nothing is pending, nor for other roles", async () => {
    const fetch = vi.spyOn(ptpApi, "fetchPendingAnnualReviews").mockResolvedValue(0)

    const { container } = renderTask("psychopedagogue")
    await vi.waitFor(() => expect(fetch).toHaveBeenCalled())
    expect(container).toBeEmptyDOMElement()

    fetch.mockClear()
    const other = renderTask("director")
    expect(other.container).toBeEmptyDOMElement()
    expect(fetch).not.toHaveBeenCalled()
  })
})
