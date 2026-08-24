import type { Role, User } from "@/types"

/**
 * Client-side role/permission helpers for the SPA.
 *
 * IMPORTANT — this is a UX layer, not a security boundary. The real
 * authorization decision always lives in the backend Policies
 * (`api/app/Policies/*`), enforced server-side and never trusting the client
 * (see `CLAUDE.md` → "Security rules"). These helpers only decide which UI to
 * render (hide a button, hide a field) so users are not shown affordances that
 * the API would reject anyway.
 *
 * Each predicate mirrors the **role portion** of the matching Policy method.
 * The Policies also apply per-record tenant/ownership checks
 * (`sharesSchool`, `teachesGroup`, `teachesStudent`) that depend on data the
 * frontend does not have; those cannot be reproduced here and are deliberately
 * left to the server. When a helper says it mirrors `FooPolicy::bar`, it means
 * "the role-based half of that method" — the server still runs the whole thing.
 *
 * Kept intentionally in sync with `App\Enums\Role` (English identifiers) and
 * the two reference Policies audited for Session 7:
 *   - GroupPolicy:   create/update/delete → director only.
 *   - StudentPolicy: create/update → school-wide (director + psychopedagogue);
 *                    delete → director only;
 *                    viewClinicalProfile → school-wide.
 */

/**
 * Roles with automatic, school-wide visibility of groups and students.
 * Mirror of `App\Enums\Role::schoolWideValues()`.
 */
export const SCHOOL_WIDE_ROLES: readonly Role[] = ["director", "psychopedagogue"]

/** True when the user holds the given role. */
export function hasRole(user: User | null | undefined, role: Role): boolean {
  return user?.roles.includes(role) ?? false
}

/** True when the user holds at least one of the given roles. */
export function hasAnyRole(user: User | null | undefined, roles: readonly Role[]): boolean {
  return user?.roles.some((role) => roles.includes(role)) ?? false
}

/** Mirror of `$user->hasRole('director')`. */
export function isDirector(user: User | null | undefined): boolean {
  return hasRole(user, "director")
}

/**
 * Mirror of `$user->hasAnyRole(Role::schoolWideValues())` — director or
 * psychopedagogue. These roles get school-wide access; a teacher does not.
 */
export function isSchoolWide(user: User | null | undefined): boolean {
  return hasAnyRole(user, SCHOOL_WIDE_ROLES)
}

// ── Groups ────────────────────────────────────────────────────────────────

/** Mirror of `GroupPolicy::create` (role portion): director only. */
export function canCreateGroup(user: User | null | undefined): boolean {
  return isDirector(user)
}

/**
 * Mirror of `GroupPolicy::update`/`delete` (role portion): director only.
 * (The server additionally requires the group to share the user's school.)
 */
export function canEditGroup(user: User | null | undefined): boolean {
  return isDirector(user)
}

// ── Students ──────────────────────────────────────────────────────────────

/** Mirror of `StudentPolicy::create` (role portion): school-wide roles. */
export function canCreateStudent(user: User | null | undefined): boolean {
  return isSchoolWide(user)
}

/**
 * Mirror of `StudentPolicy::update` (role portion): school-wide roles.
 * (The server additionally requires the student to share the user's school.)
 */
export function canEditStudent(user: User | null | undefined): boolean {
  return isSchoolWide(user)
}

/**
 * Mirror of `StudentPolicy::delete` (role portion): **director only** — note
 * this is narrower than create/update, which a psychopedagogue may also do.
 * (The server additionally requires the student to share the user's school.)
 */
export function canDeleteStudent(user: User | null | undefined): boolean {
  return isDirector(user)
}

/**
 * Mirror of `StudentPolicy::viewClinicalProfile` (role portion): school-wide
 * roles. Gates the sensitive clinical/learning-profile fields
 * (learning_profile, individual_profile, tracking_notes, related_documents)
 * — both showing them and letting them be edited, since editing a student is
 * itself a school-wide action. A teacher who can view a student never sees or
 * edits these fields.
 */
export function canAccessClinicalProfile(user: User | null | undefined): boolean {
  return isSchoolWide(user)
}

// ── Institutional tracking (Sesión 8) ───────────────────────────────────────

/**
 * Mirror of `SchoolPolicy::viewAdoptionDashboard` (role portion): director
 * only. The server additionally requires the school to be the user's own
 * (`$user->school_id === $school->id`), which the frontend cannot re-derive —
 * it only ever requests its own school's dashboard.
 */
export function canViewAdoptionDashboard(user: User | null | undefined): boolean {
  return isDirector(user)
}

/**
 * Mirror of `AlertPolicy::resolve` (role portion), which delegates to
 * `StudentPolicy::viewClinicalProfile`: school-wide roles. Alerts are only
 * ever returned to those roles anyway, so this gates the resolve affordance to
 * match. The server re-checks same-school ownership per alert.
 */
export function canResolveAlert(user: User | null | undefined): boolean {
  return isSchoolWide(user)
}
