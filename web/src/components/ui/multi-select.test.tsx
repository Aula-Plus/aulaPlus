import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { describe, expect, it, vi } from "vitest"
import { MultiSelect } from "./multi-select"

const options = [
  { id: 1, label: "Ana Ruiz" },
  { id: 2, label: "Zoe Diaz" },
]

describe("MultiSelect", () => {
  it("shows a placeholder when nothing is selected", () => {
    render(<MultiSelect options={options} selected={[]} onChange={vi.fn()} />)

    expect(screen.getByText("Seleccionar…")).toBeInTheDocument()
  })

  it("shows a chip for each selected option", () => {
    render(<MultiSelect options={options} selected={[1]} onChange={vi.fn()} />)

    expect(screen.getByText("Ana Ruiz")).toBeInTheDocument()
    expect(screen.queryByText("Zoe Diaz")).not.toBeInTheDocument()
  })

  it("adds an option to the selection when it is clicked", async () => {
    const onChange = vi.fn()
    render(<MultiSelect options={options} selected={[]} onChange={onChange} />)

    await userEvent.click(screen.getByTestId("multi-select-trigger"))
    await userEvent.click(await screen.findByRole("button", { name: "Ana Ruiz" }))

    expect(onChange).toHaveBeenCalledWith([1])
  })

  it("removes an option from the selection when it is clicked again", async () => {
    const onChange = vi.fn()
    render(<MultiSelect options={options} selected={[1, 2]} onChange={onChange} />)

    await userEvent.click(screen.getByTestId("multi-select-trigger"))
    await userEvent.click(await screen.findByRole("button", { name: "Ana Ruiz" }))

    expect(onChange).toHaveBeenCalledWith([2])
  })
})
