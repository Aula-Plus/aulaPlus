import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { CommentSettingsPage } from "./CommentSettingsPage"
import * as trackingApi from "./trackingApi"

describe("CommentSettingsPage", () => {
  beforeEach(() => {
    vi.spyOn(trackingApi, "fetchCommentCategories").mockResolvedValue([
      { id: 1, name: "Social", position: 0 },
    ])
    vi.spyOn(trackingApi, "fetchCommentTrendSettings").mockResolvedValue({ min_count: 4, days: 21 })
  })

  afterEach(() => vi.restoreAllMocks())

  it("adds a category and saves the trend threshold", async () => {
    const create = vi
      .spyOn(trackingApi, "createCommentCategory")
      .mockResolvedValue({ id: 2, name: "Salud", position: 1 })
    const save = vi
      .spyOn(trackingApi, "updateCommentTrendSettings")
      .mockResolvedValue({ min_count: 5, days: 30 })

    render(<CommentSettingsPage />)

    expect(await screen.findByDisplayValue("Social")).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText(/nueva categoría/i), "Salud")
    await userEvent.click(screen.getByRole("button", { name: /agregar/i }))
    expect(create).toHaveBeenCalledWith("Salud")
    expect(await screen.findByDisplayValue("Salud")).toBeInTheDocument()

    const count = screen.getByLabelText(/cantidad de comentarios/i)
    const days = screen.getByLabelText(/en cuántos días/i)
    await userEvent.clear(count)
    await userEvent.type(count, "5")
    await userEvent.clear(days)
    await userEvent.type(days, "30")
    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(save).toHaveBeenCalledWith({ min_count: 5, days: 30 })
    expect(await screen.findByText("Guardado.")).toBeInTheDocument()
  })
})
