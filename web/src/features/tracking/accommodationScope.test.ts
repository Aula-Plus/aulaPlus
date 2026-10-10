import { describe, expect, it } from "vitest"
import {
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
})
