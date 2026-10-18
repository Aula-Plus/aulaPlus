import { render, screen } from "@testing-library/react"
import { describe, expect, it } from "vitest"
import { PrivateNotesSignal } from "./PrivateNotesSignal"

describe("PrivateNotesSignal", () => {
  it("shows the neutral private mark when active, naming no category or count", () => {
    render(<PrivateNotesSignal active />)

    const note = screen.getByRole("note", { name: "Señal privada" })
    expect(note).toHaveTextContent("Tenés varias notas privadas sobre este alumno en este período.")
    expect(note).toHaveTextContent("Solo vos ves esta marca.")
    expect(note.textContent).not.toMatch(/\d/)
  })

  it("renders nothing when inactive or absent", () => {
    const { container, rerender } = render(<PrivateNotesSignal active={false} />)
    expect(container).toBeEmptyDOMElement()

    rerender(<PrivateNotesSignal />)
    expect(container).toBeEmptyDOMElement()
  })
})
