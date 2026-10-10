import { describe, expect, it } from "vitest"
import {
  accommodationAppliesToAssessment,
  describeAccommodationScope,
  formatSinceDate,
  joinSpanishList,
} from "./accommodationScope"

describe("accommodationScope", () => {
  it("joins Spanish lists, switching «y» to «e» before an /i/ sound", () => {
    expect(joinSpanishList(["Matemática"])).toBe("Matemática")
    expect(joinSpanishList(["Idioma Español", "Literatura"])).toBe("Idioma Español y Literatura")
    expect(joinSpanishList(["Ciencias", "Matemática", "Inglés"])).toBe("Ciencias, Matemática e Inglés")
    expect(joinSpanishList(["Geografía", "Historia"])).toBe("Geografía e Historia")
  })

  it("shows the year only when the date is not from the current year", () => {
    const today = new Date(2026, 9, 10)
    expect(formatSinceDate("2026-04-02", today)).toBe("02/04")
    expect(formatSinceDate("2025-04-02", today)).toBe("02/04/2025")
  })

  it("describes a general accommodation as applying in every subject", () => {
    expect(
      describeAccommodationScope(
        { starts_on: "2026-03-15", subjects: [], assessment_types: null },
        new Date(2026, 9, 10),
      ),
    ).toBe("desde el 15/03 — en todas las materias")
  })

  it("applies to an assessment only when both its subject and its type are in scope", () => {
    const math = { id: 4, name: "Matemática", deleted: false }
    const writtenMath = { subject_id: 4, type: "written" as const }
    const oralMath = { subject_id: 4, type: "oral" as const }
    const writtenScience = { subject_id: 5, type: "written" as const }

    const general = { subjects: [], assessment_types: null }
    expect(accommodationAppliesToAssessment(general, writtenScience)).toBe(true)

    const mathOnly = { subjects: [math], assessment_types: [] }
    expect(accommodationAppliesToAssessment(mathOnly, oralMath)).toBe(true)
    expect(accommodationAppliesToAssessment(mathOnly, writtenScience)).toBe(false)

    const writtenMathOnly = { subjects: [math], assessment_types: ["written" as const] }
    expect(accommodationAppliesToAssessment(writtenMathOnly, writtenMath)).toBe(true)
    expect(accommodationAppliesToAssessment(writtenMathOnly, oralMath)).toBe(false)
  })
})
