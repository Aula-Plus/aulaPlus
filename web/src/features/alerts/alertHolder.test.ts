import { describe, expect, it } from "vitest"
import type { Alert } from "@/types"
import { alertHolderPhrase, formatDayMonth, inChargeLabel, isInChargeOfAlert } from "./alertHolder"

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

  it("formats dates as dd/mm, without shifting date-only values", () => {
    expect(formatDayMonth("2026-10-27")).toBe("27/10")
    expect(formatDayMonth("2026-01-05")).toBe("05/01")
    expect(formatDayMonth(null)).toBe("—")
    expect(formatDayMonth("not a date")).toBe("—")
  })

  it("is «a tu cargo» only once a way out was chosen and the viewer is responsible", () => {
    expect(isInChargeOfAlert({ ...base, is_in_charge: true })).toBe(false)
    expect(isInChargeOfAlert({ ...base, outcome: "handed_off", is_in_charge: false })).toBe(false)
    expect(isInChargeOfAlert({ ...base, outcome: "handed_off" })).toBe(false)
    expect(isInChargeOfAlert({ ...base, outcome: "handed_off", is_in_charge: true })).toBe(true)
  })

  it("reads «A tu cargo desde el dd/mm · plazo al dd/mm»", () => {
    expect(
      inChargeLabel({ ...base, outcome: "handed_off", outcome_at: "2026-10-20T12:00:00Z", due_on: "2026-10-27" }),
    ).toBe("A tu cargo desde el 20/10 · plazo al 27/10")
  })
})
