import { describe, expect, it } from "vitest"
import { plannerNote } from "./classesApi"

describe("plannerNote", () => {
  it("says the AI read the unit planner", () => {
    expect(plannerNote({ read: true, reason: null })).toMatch(/leyó el planificador de la unidad/)
  })

  it("explains a free class reads no planner", () => {
    expect(plannerNote({ read: false, reason: "class_without_unit" })).toMatch(/^Clase libre/)
  })

  it("shows nothing without planner data", () => {
    expect(plannerNote(null)).toBeNull()
  })
})
