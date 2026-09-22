import { describe, expect, it } from "vitest"

import { formatShortDate } from "./utils"

describe("formatShortDate", () => {
  it("returns a dash for absent or invalid values", () => {
    expect(formatShortDate(null)).toBe("—")
    expect(formatShortDate(undefined)).toBe("—")
    expect(formatShortDate("not-a-date")).toBe("—")
  })

  it("keeps date-only strings on the intended calendar day (no UTC off-by-one)", () => {
    // 2026-09-16 must render as the 16th, not the 15th, regardless of the
    // runner's timezone — the regression this guards against.
    expect(formatShortDate("2026-09-16")).toContain("16")
    expect(formatShortDate("2026-09-16")).toContain("sept")
  })

  it("formats full timestamps by their local calendar day", () => {
    expect(formatShortDate("2026-09-16T12:00:00Z")).toContain("16")
  })
})
