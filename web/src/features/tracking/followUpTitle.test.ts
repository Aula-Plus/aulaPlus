import { describe, expect, it } from "vitest"
import { followUpTitle } from "./followUpTitle"

describe("followUpTitle", () => {
  it("names who a proposal's review task comes from (ClickUp 1dfd3mc55zu)", () => {
    const base = { accommodation_id: 7, title: null, description: "" }
    expect(followUpTitle({ ...base, accommodation_proposed_as: "director" })).toBe(
      "Revisar propuesta de ajuste de Dirección",
    )
    expect(followUpTitle({ ...base, accommodation_proposed_as: "teacher" })).toBe(
      "Revisar propuesta de ajuste de un docente",
    )
    expect(followUpTitle(base)).toBe("Revisar propuesta de ajuste de Dirección")
  })

  it("uses the title, or the description, of any other follow-up", () => {
    expect(followUpTitle({ accommodation_id: null, title: "Llamar a la familia", description: "x" })).toBe(
      "Llamar a la familia",
    )
    expect(followUpTitle({ accommodation_id: null, title: null, description: "Revisar" })).toBe("Revisar")
  })
})
