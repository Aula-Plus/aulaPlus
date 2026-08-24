import { describe, expect, it } from "vitest"
import {
  canAccessClinicalProfile,
  canApproveAccommodation,
  canCreateGroup,
  canCreateStudent,
  canDeleteStudent,
  canEditGroup,
  canEditStudent,
  canProposeBarrierAccommodation,
  canResolveAlert,
  canValidateBarrierAccommodation,
  canViewAdoptionDashboard,
  canViewStudentHistory,
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

  // Session 9 — approval flows and audit history. The expected values below
  // mirror the role portion of AccommodationPolicy::approve/reject,
  // BarrierPolicy::update (reused by AttachBarrierAccommodationRequest),
  // BarrierPolicy::validate, and StudentHistoryController's
  // 'view-clinical-profile' gate. If a Policy changes, these must too.
  describe("approval flows & history predicates (Sesión 9)", () => {
    it("only school-wide roles can approve/reject an accommodation", () => {
      expect(canApproveAccommodation(director)).toBe(true)
      expect(canApproveAccommodation(psychopedagogue)).toBe(true)
      expect(canApproveAccommodation(teacher)).toBe(false)
      expect(canApproveAccommodation(null)).toBe(false)
    })

    it("only teacher and psychopedagogue can propose a Barrier↔Accommodation link", () => {
      // Director is deliberately excluded — mirrors BarrierPolicy::update.
      expect(canProposeBarrierAccommodation(psychopedagogue)).toBe(true)
      expect(canProposeBarrierAccommodation(teacher)).toBe(true)
      expect(canProposeBarrierAccommodation(director)).toBe(false)
      expect(canProposeBarrierAccommodation(null)).toBe(false)
    })

    it("school-wide roles can validate a Barrier↔Accommodation link they did NOT propose", () => {
      const someoneElse = 42
      expect(canValidateBarrierAccommodation(director, someoneElse)).toBe(true)
      expect(canValidateBarrierAccommodation(psychopedagogue, someoneElse)).toBe(true)
      expect(canValidateBarrierAccommodation(teacher, someoneElse)).toBe(false)
      expect(canValidateBarrierAccommodation(null, someoneElse)).toBe(false)
    })

    it("blocks the proposer from validating their own link (four-eyes rule, UI half)", () => {
      // director/psychopedagogue.id === 1 above; passing 1 as proposedById.
      expect(canValidateBarrierAccommodation(director, director.id)).toBe(false)
      expect(canValidateBarrierAccommodation(psychopedagogue, psychopedagogue.id)).toBe(false)
    })

    it("only school-wide roles can view the student audit history", () => {
      expect(canViewStudentHistory(director)).toBe(true)
      expect(canViewStudentHistory(psychopedagogue)).toBe(true)
      expect(canViewStudentHistory(teacher)).toBe(false)
      expect(canViewStudentHistory(null)).toBe(false)
    })
  })
})
