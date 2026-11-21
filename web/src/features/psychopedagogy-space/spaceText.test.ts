import { describe, expect, it } from "vitest"
import { companionSummary, formatDays, formatHours } from "./spaceText"

describe("spaceText", () => {
  it("writes the days in week order, joined in Spanish", () => {
    expect(formatDays(["thursday", "tuesday"])).toBe("Martes y jueves")
    expect(formatDays(["friday", "monday", "wednesday"])).toBe("Lunes, miércoles y viernes")
    expect(formatDays(["monday"])).toBe("Lunes")
  })

  it("summarizes a companion without the reason", () => {
    expect(formatHours(4.5)).toBe("4,5 h")
    expect(
      companionSummary({ id: 1, name: "Romina Silva", weekly_hours: 6, days: ["tuesday", "thursday"], reason: "x" }),
    ).toBe("Romina Silva · 6 h semanales · Martes y jueves")
  })
})
