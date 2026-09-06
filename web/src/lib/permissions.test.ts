import { describe, expect, it } from "vitest"
import {
  canApproveAccommodation,
  canDeleteGroup,
  canDeleteStudent,
  canManageGroups,
  canManageStudents,
  canProposeBarrierAccommodation,
  canResolveAlert,
  canValidateBarrierAccommodation,
  canViewAdoptionDashboard,
  canViewClinicalProfileUX,
  canViewStudentHistory,
  hasAnyRole,
  hasRole,
  isDirector,
  isPsychopedagogue,
  isSchoolWideStaff,
  isTeacher,
} from "./permissions"
import type { Role, User } from "@/types"

function userWith(...roles: Role[]): User {
  return { id: 1, name: "Test", email: "test@escuela.test", roles }
}

const director = userWith("director")
const psychopedagogue = userWith("psychopedagogue")
const teacher = userWith("teacher")

// Each predicate → expected result per role and for a null user. The expected
// values are the role portion of the audited backend Policies; if a Policy
// changes, this table must too.
const cases: {
  name: string
  fn: (user: User | null) => boolean
  director: boolean
  psychopedagogue: boolean
  teacher: boolean
}[] = [
  { name: "isDirector", fn: isDirector, director: true, psychopedagogue: false, teacher: false },
  {
    name: "isPsychopedagogue",
    fn: isPsychopedagogue,
    director: false,
    psychopedagogue: true,
    teacher: false,
  },
  { name: "isTeacher", fn: isTeacher, director: false, psychopedagogue: false, teacher: true },
  {
    name: "isSchoolWideStaff",
    fn: isSchoolWideStaff,
    director: true,
    psychopedagogue: true,
    teacher: false,
  },
  {
    name: "canManageGroups",
    fn: canManageGroups,
    director: true,
    psychopedagogue: false,
    teacher: false,
  },
  {
    name: "canDeleteGroup",
    fn: canDeleteGroup,
    director: true,
    psychopedagogue: false,
    teacher: false,
  },
  {
    name: "canManageStudents",
    fn: canManageStudents,
    director: true,
    psychopedagogue: true,
    teacher: false,
  },
  {
    name: "canDeleteStudent",
    fn: canDeleteStudent,
    director: true,
    psychopedagogue: false,
    teacher: false,
  },
  {
    name: "canViewClinicalProfileUX",
    fn: canViewClinicalProfileUX,
    director: true,
    psychopedagogue: true,
    teacher: false,
  },
  {
    name: "canResolveAlert",
    fn: canResolveAlert,
    director: true,
    psychopedagogue: true,
    teacher: false,
  },
  {
    name: "canApproveAccommodation",
    fn: canApproveAccommodation,
    director: true,
    psychopedagogue: true,
    teacher: false,
  },
  {
    name: "canViewStudentHistory",
    fn: canViewStudentHistory,
    director: true,
    psychopedagogue: true,
    teacher: false,
  },
  {
    name: "canViewAdoptionDashboard",
    fn: canViewAdoptionDashboard,
    director: true,
    psychopedagogue: false,
    teacher: false,
  },
  {
    name: "canProposeBarrierAccommodation",
    fn: canProposeBarrierAccommodation,
    director: false,
    psychopedagogue: true,
    teacher: true,
  },
]

describe("permissions", () => {
  describe.each(cases)("$name", ({ fn, ...expected }) => {
    it("matches the policy for a director", () => {
      expect(fn(director)).toBe(expected.director)
    })
    it("matches the policy for a psychopedagogue", () => {
      expect(fn(psychopedagogue)).toBe(expected.psychopedagogue)
    })
    it("matches the policy for a teacher", () => {
      expect(fn(teacher)).toBe(expected.teacher)
    })
    it("is false for a null user", () => {
      expect(fn(null)).toBe(false)
    })
  })

  describe("hasRole / hasAnyRole", () => {
    it("hasRole matches a single role", () => {
      expect(hasRole(director, "director")).toBe(true)
      expect(hasRole(teacher, "director")).toBe(false)
      expect(hasRole(null, "director")).toBe(false)
    })

    it("hasAnyRole matches any of the given roles", () => {
      expect(hasAnyRole(psychopedagogue, ["director", "psychopedagogue"])).toBe(true)
      expect(hasAnyRole(teacher, ["director", "psychopedagogue"])).toBe(false)
      expect(hasAnyRole(null, ["director"])).toBe(false)
    })

    it("supports users holding multiple roles", () => {
      const both = userWith("teacher", "director")
      expect(isDirector(both)).toBe(true)
      expect(isSchoolWideStaff(both)).toBe(true)
      expect(isTeacher(both)).toBe(true)
    })
  })

  describe("canValidateBarrierAccommodation (four-eyes rule)", () => {
    it("allows school-wide staff who did not propose the link", () => {
      expect(canValidateBarrierAccommodation(director, 99)).toBe(true)
      expect(canValidateBarrierAccommodation(psychopedagogue, 99)).toBe(true)
    })

    it("denies the person who proposed the link, even if school-wide staff", () => {
      // director/psychopedagogue fixtures both have id 1.
      expect(canValidateBarrierAccommodation(director, 1)).toBe(false)
      expect(canValidateBarrierAccommodation(psychopedagogue, 1)).toBe(false)
    })

    it("denies a teacher regardless of who proposed it", () => {
      expect(canValidateBarrierAccommodation(teacher, 99)).toBe(false)
    })

    it("is false for a null user", () => {
      expect(canValidateBarrierAccommodation(null, 99)).toBe(false)
    })
  })
})
