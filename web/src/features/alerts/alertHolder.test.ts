import { describe, expect, it } from "vitest"
import type { Alert } from "@/types"
import { alertHolderPhrase } from "./alertHolder"

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
  it("has no holder until a way out is chosen", () => {
    expect(alertHolderPhrase(base)).toBeNull()
  })

  it("names the one person who has it", () => {
    expect(
      alertHolderPhrase({
        ...base,
        outcome: "handed_off",
        assignee: { id: 3, name: "Galia Cohen" },
        outcome_at: "2026-09-13T10:00:00Z",
      }),
    ).toMatch(/^la tiene Galia Cohen desde el /)
  })
})
