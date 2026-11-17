import { describe, expect, it } from "vitest"
import { formatDay, sameDateNextYear } from "./schoolYearDates"

describe("schoolYearDates", () => {
  it("writes the day as dd/mm/yyyy", () => {
    expect(formatDay("2026-09-14")).toBe("14/09/2026")
    expect(formatDay(null)).toBe("—")
  })

  it("carries a date to the same day next year, 29/02 to 28/02", () => {
    expect(sameDateNextYear("2026-03-02")).toBe("2027-03-02")
    expect(sameDateNextYear("2028-02-29")).toBe("2029-02-28")
  })
})
