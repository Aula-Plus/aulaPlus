import { render, screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter } from "react-router-dom"
import { afterEach, describe, expect, it, vi } from "vitest"
import { SupportPage } from "./SupportPage"
import * as supportApi from "./supportApi"

function renderPage() {
  return render(
    <MemoryRouter initialEntries={[{ pathname: "/ayuda", state: { from: "/alumnos/3" } }]}>
      <SupportPage />
    </MemoryRouter>,
  )
}

describe("SupportPage", () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it("warns not to write student data", () => {
    renderPage()
    expect(
      screen.getByText(/no escribas nombres de alumnos ni datos de salud/i),
    ).toBeInTheDocument()
  })

  it("sends the chosen kind, the message and the originating screen", async () => {
    const send = vi.spyOn(supportApi, "sendSupportMessage").mockResolvedValue()
    renderPage()

    await userEvent.click(screen.getByRole("radio", { name: /proponé una mejora/i }))
    await userEvent.type(screen.getByLabelText("Mensaje"), "Poder imprimir la ficha")
    await userEvent.click(screen.getByRole("button", { name: "Enviar" }))

    await waitFor(() =>
      expect(send).toHaveBeenCalledWith({
        kind: "improvement",
        message: "Poder imprimir la ficha",
        screen: "/alumnos/3",
      }),
    )
    expect(await screen.findByText(/recibimos tu mensaje/i)).toBeInTheDocument()
  })

  it("blocks a too-short message and shows an error when sending fails", async () => {
    const send = vi.spyOn(supportApi, "sendSupportMessage").mockRejectedValue(new Error("boom"))
    renderPage()

    await userEvent.type(screen.getByLabelText("Mensaje"), "hi")
    await userEvent.click(screen.getByRole("button", { name: "Enviar" }))
    expect(await screen.findByText(/mínimo 5 caracteres/i)).toBeInTheDocument()
    expect(send).not.toHaveBeenCalled()

    await userEvent.type(screen.getByLabelText("Mensaje"), " there friend")
    await userEvent.click(screen.getByRole("button", { name: "Enviar" }))
    expect(await screen.findByRole("alert")).toHaveTextContent(/no pudimos enviar/i)
  })
})
