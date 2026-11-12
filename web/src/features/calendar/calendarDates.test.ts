import { describe, expect, it } from "vitest"
import { addDays, mondayOf, rangeFor, shiftAnchor, viewTitle, weekdayDayMonth } from "./calendarDates"

describe("calendarDates", () => {
  it("starts weeks on Monday", () => {
    // 2026-12-15 is a Tuesday; 2026-12-20 a Sunday.
    expect(mondayOf("2026-12-15")).toBe("2026-12-14")
    expect(mondayOf("2026-12-20")).toBe("2026-12-14")
    expect(mondayOf("2026-12-14")).toBe("2026-12-14")
  })

  it("covers whole weeks in the month view and the month in the list", () => {
    expect(rangeFor("month", "2026-12-15")).toEqual({ from: "2026-11-30", to: "2027-01-03" })
    expect(rangeFor("week", "2026-12-15")).toEqual({ from: "2026-12-14", to: "2026-12-20" })
    expect(rangeFor("list", "2026-12-15")).toEqual({ from: "2026-12-01", to: "2026-12-31" })
  })

  it("moves by week or by month, across years", () => {
    expect(shiftAnchor("week", "2026-12-29", 1)).toBe("2027-01-05")
    expect(shiftAnchor("month", "2026-12-15", 1)).toBe("2027-01-01")
    expect(shiftAnchor("list", "2026-01-31", -1)).toBe("2025-12-01")
    expect(addDays("2026-02-28", 1)).toBe("2026-03-01")
  })

  it("writes titles in Spanish", () => {
    expect(viewTitle("month", "2026-11-20")).toBe("Noviembre 2026")
    expect(viewTitle("week", "2026-11-20")).toBe("Semana del 16/11 al 22/11")
    expect(weekdayDayMonth("2026-12-15")).toBe("Martes 15/12")
  })
})
