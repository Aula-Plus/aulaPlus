import { describe, expect, it } from "vitest"
import type { Alert } from "@/types"
import { alertHolderPhrase, joinNames } from "./alertHolder"

const base: Alert = {
  id: 1,
  student_id: 3,
  type: "performance",
  severity: "medium",
  description: "x",
  resolved: false,
  resolved_by_id: null,
  resolved_at: null,
  created_at: "2026-09-12T10:00:00Z",
}

describe("alertHolder", () => {
  it("joins names the Spanish way", () => {
    expect(joinNames(["Galia Cohen"])).toBe("Galia Cohen")
    expect(joinNames(["Galia Cohen", "Mariana López"])).toBe("Galia Cohen y Mariana López")
    expect(joinNames(["Galia Cohen", "Ignacio Pérez"])).toBe("Galia Cohen e Ignacio Pérez")
  })

  it("has no holder until a way out is chosen", () => {
    expect(alertHolderPhrase(base)).toBeNull()
  })

  it("names the person responsible and anyone it was shared with", () => {
    expect(
      alertHolderPhrase({
        ...base,
        outcome: "handed_off",
        assignee: { id: 3, name: "Galia Cohen" },
        collaborators: [{ id: 1, name: "Mariana López" }],
        outcome_at: "2026-09-13T10:00:00Z",
      }),
    ).toMatch(/^la tienen Galia Cohen y Mariana López desde el /)
  })
})
