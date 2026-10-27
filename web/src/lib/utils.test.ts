import { describe, expect, it } from "vitest"

import { formatLongDate, formatShortDate } from "./utils"

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

describe("formatLongDate", () => {
  it("writes the day out as on screen 01", () => {
    expect(formatLongDate(new Date(2026, 10, 20))).toBe("Viernes 20 de noviembre de 2026")
    expect(formatLongDate(new Date(2026, 0, 1))).toBe("Jueves 1 de enero de 2026")
  })
})
