import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { describe, expect, it, vi } from "vitest"
import { SingleSelect } from "./single-select"

const options = [
  { value: "1", label: "Ana Ruiz" },
  { value: "2", label: "Zoe Diaz" },
]

describe("SingleSelect", () => {
  it("shows a placeholder when nothing is selected", () => {
    render(
      <SingleSelect options={options} value="" onChange={vi.fn()} placeholder="Elegí…" />
    )

    expect(screen.getByText("Elegí…")).toBeInTheDocument()
  })

  it("shows the label of the selected option", () => {
    render(<SingleSelect options={options} value="2" onChange={vi.fn()} />)

    expect(screen.getByText("Zoe Diaz")).toBeInTheDocument()
  })

  it("calls onChange with the value when an option is clicked", async () => {
    const onChange = vi.fn()
    render(<SingleSelect options={options} value="" onChange={onChange} />)

    await userEvent.click(screen.getByTestId("single-select-trigger"))
    await userEvent.click(await screen.findByRole("button", { name: "Ana Ruiz" }))

    expect(onChange).toHaveBeenCalledWith("1")
  })

  it("filters options by the search query", async () => {
    render(<SingleSelect options={options} value="" onChange={vi.fn()} />)

    await userEvent.click(screen.getByTestId("single-select-trigger"))
    await userEvent.type(await screen.findByPlaceholderText("Buscar…"), "zoe")

    expect(screen.getByRole("button", { name: "Zoe Diaz" })).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Ana Ruiz" })).not.toBeInTheDocument()
  })

  it("shows an empty state when the search matches nothing", async () => {
    render(<SingleSelect options={options} value="" onChange={vi.fn()} />)

    await userEvent.click(screen.getByTestId("single-select-trigger"))
    await userEvent.type(await screen.findByPlaceholderText("Buscar…"), "xyz")

    expect(screen.getByText("Sin resultados")).toBeInTheDocument()
  })
})
