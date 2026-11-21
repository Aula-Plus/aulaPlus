import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, describe, expect, it, vi } from "vitest"
import * as spaceApi from "./psychopedagogySpaceApi"
import { MeetingActionsTask } from "./MeetingActionsTask"

const action = {
  id: 3,
  description: "Confirmar con Lucía si el tiempo extendido alcanza",
  due_on: "2026-11-27",
  done: false,
  meeting: { sheet_type_name: "Reunión de profesores", held_on: "2026-11-18" },
}

describe("MeetingActionsTask", () => {
  afterEach(() => vi.restoreAllMocks())

  it("lists the actions left to me with their deadline and lets me mark one done", async () => {
    vi.spyOn(spaceApi, "fetchMyMeetingActions").mockResolvedValue([action])
    const complete = vi.spyOn(spaceApi, "completeMeetingAction").mockResolvedValue({ ...action, done: true })
    render(<MeetingActionsTask />)

    expect(await screen.findByText(action.description)).toBeInTheDocument()
    expect(screen.getByText(/Plazo: .*2026 · De la reunión de profesores/)).toBeInTheDocument()

    await userEvent.click(screen.getByRole("button", { name: "Marcar hecha" }))

    expect(complete).toHaveBeenCalledWith(3)
    expect(screen.queryByText(action.description)).not.toBeInTheDocument()
  })

  it("is not there when nothing is left to me", async () => {
    const fetch = vi.spyOn(spaceApi, "fetchMyMeetingActions").mockResolvedValue([])
    const { container } = render(<MeetingActionsTask />)
    await vi.waitFor(() => expect(fetch).toHaveBeenCalled())
    expect(container).toBeEmptyDOMElement()
  })
})
