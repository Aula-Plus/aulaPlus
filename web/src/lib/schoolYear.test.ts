import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { getCurrentSchoolYear } from "./schoolYear"

describe("getCurrentSchoolYear", () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it("returns the current calendar year", () => {
    vi.setSystemTime(new Date("2026-08-20T12:00:00Z"))
    expect(getCurrentSchoolYear()).toBe(2026)
  })

  it("returns a different year when the system date changes", () => {
    vi.setSystemTime(new Date("2027-01-05T00:00:00Z"))
    expect(getCurrentSchoolYear()).toBe(2027)
  })
})
