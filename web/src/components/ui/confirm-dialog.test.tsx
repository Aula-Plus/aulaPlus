import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { describe, expect, it, vi } from "vitest"
import { ConfirmDialog } from "./confirm-dialog"
import { Button } from "./button"

describe("ConfirmDialog", () => {
  it("calls onConfirm when the user confirms", async () => {
    const onConfirm = vi.fn()

    render(
      <ConfirmDialog
        trigger={<Button>Eliminar</Button>}
        title="¿Eliminar?"
        description="Esta acción no se puede deshacer."
        onConfirm={onConfirm}
      />,
    )

    await userEvent.click(screen.getByRole("button", { name: "Eliminar" }))
    await userEvent.click(await screen.findByRole("button", { name: "Confirmar" }))

    expect(onConfirm).toHaveBeenCalledOnce()
  })

  it("does not call onConfirm when the user cancels", async () => {
    const onConfirm = vi.fn()

    render(
      <ConfirmDialog
        trigger={<Button>Eliminar</Button>}
        title="¿Eliminar?"
        description="Esta acción no se puede deshacer."
        onConfirm={onConfirm}
      />,
    )

    await userEvent.click(screen.getByRole("button", { name: "Eliminar" }))
    await userEvent.click(await screen.findByRole("button", { name: "Cancelar" }))

    expect(onConfirm).not.toHaveBeenCalled()
    expect(screen.queryByText("¿Eliminar?")).not.toBeInTheDocument()
  })
})
