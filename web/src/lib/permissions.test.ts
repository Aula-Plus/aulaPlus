import { describe, expect, it } from "vitest"
import {
  canAccessClinicalProfile,
  canCreateGroup,
  canCreateStudent,
  canDeleteStudent,
  canEditGroup,
  canEditStudent,
  canResolveAlert,
  canViewAdoptionDashboard,
  hasAnyRole,
  hasRole,
  isDirector,
  isSchoolWide,
} from "./permissions"
import type { Role, User } from "@/types"

function userWith(...roles: Role[]): User {
  return { id: 1, name: "Test", email: "test@escuela.test", roles }
}

const director = userWith("director")
const psychopedagogue = userWith("psychopedagogue")
const teacher = userWith("teacher")

describe("permissions", () => {
  describe("low-level role helpers", () => {
    it("hasRole matches a single role", () => {
      expect(hasRole(director, "director")).toBe(true)
      expect(hasRole(teacher, "director")).toBe(false)
    })

    it("hasAnyRole matches any of the given roles", () => {
      expect(hasAnyRole(psychopedagogue, ["director", "psychopedagogue"])).toBe(true)
      expect(hasAnyRole(teacher, ["director", "psychopedagogue"])).toBe(false)
    })

    it("isDirector is true only for a director", () => {
      expect(isDirector(director)).toBe(true)
      expect(isDirector(psychopedagogue)).toBe(false)
      expect(isDirector(teacher)).toBe(false)
    })

    it("isSchoolWide is true for director and psychopedagogue only", () => {
      expect(isSchoolWide(director)).toBe(true)
      expect(isSchoolWide(psychopedagogue)).toBe(true)
      expect(isSchoolWide(teacher)).toBe(false)
    })

    it("returns false for a null or undefined user", () => {
      expect(hasRole(null, "director")).toBe(false)
      expect(isDirector(undefined)).toBe(false)
      expect(isSchoolWide(null)).toBe(false)
    })

    it("supports users holding multiple roles", () => {
      const both = userWith("teacher", "director")
      expect(isDirector(both)).toBe(true)
      expect(isSchoolWide(both)).toBe(true)
    })
  })

  // The expected values below are the role portion of the audited backend
  // Policies (GroupPolicy, StudentPolicy). If a Policy changes, these must too.
  describe("group permissions (mirror GroupPolicy)", () => {
    it("only a director can create or edit a group", () => {
      expect(canCreateGroup(director)).toBe(true)
      expect(canEditGroup(director)).toBe(true)

      for (const u of [psychopedagogue, teacher, null]) {
        expect(canCreateGroup(u)).toBe(false)
        expect(canEditGroup(u)).toBe(false)
      }
    })
  })

  describe("student permissions (mirror StudentPolicy)", () => {
    it("school-wide roles can create and edit a student", () => {
      expect(canCreateStudent(director)).toBe(true)
      expect(canCreateStudent(psychopedagogue)).toBe(true)
      expect(canEditStudent(director)).toBe(true)
      expect(canEditStudent(psychopedagogue)).toBe(true)
    })

    it("a teacher cannot create or edit a student", () => {
      expect(canCreateStudent(teacher)).toBe(false)
      expect(canEditStudent(teacher)).toBe(false)
    })

    it("only a director can delete a student (narrower than create/edit)", () => {
      expect(canDeleteStudent(director)).toBe(true)
      expect(canDeleteStudent(psychopedagogue)).toBe(false)
      expect(canDeleteStudent(teacher)).toBe(false)
    })

    it("only school-wide roles can access the clinical profile", () => {
      expect(canAccessClinicalProfile(director)).toBe(true)
      expect(canAccessClinicalProfile(psychopedagogue)).toBe(true)
      expect(canAccessClinicalProfile(teacher)).toBe(false)
    })

    it("returns false for a null user across every student predicate", () => {
      expect(canCreateStudent(null)).toBe(false)
      expect(canEditStudent(null)).toBe(false)
      expect(canDeleteStudent(null)).toBe(false)
      expect(canAccessClinicalProfile(null)).toBe(false)
    })
  })

  describe("tracking predicates (Sesión 8)", () => {
    it("only a director can view the adoption dashboard", () => {
      expect(canViewAdoptionDashboard(director)).toBe(true)
      expect(canViewAdoptionDashboard(psychopedagogue)).toBe(false)
      expect(canViewAdoptionDashboard(teacher)).toBe(false)
      expect(canViewAdoptionDashboard(null)).toBe(false)
    })

    it("only school-wide roles can resolve an alert", () => {
      expect(canResolveAlert(director)).toBe(true)
      expect(canResolveAlert(psychopedagogue)).toBe(true)
      expect(canResolveAlert(teacher)).toBe(false)
      expect(canResolveAlert(null)).toBe(false)
    })
  })
})
