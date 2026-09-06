import type { Role, User } from "@/types"

/**
 * Client-side role/permission helpers for the SPA.
 *
 * IMPORTANT — this is a UX layer, not a security boundary. The real
 * authorization decision always lives in the backend Policies
 * (`api/app/Policies/*`), enforced server-side and never trusting the client
 * (see `CLAUDE.md` → "Non-negotiable"). These helpers only decide which UI to
 * render (hide a button, hide a field); a helper must never be the reason
 * something is or isn't accessible.
 *
 * All functions are pure: they take `User | null` and never fetch or depend on
 * React context, so they are trivial to test. Each predicate mirrors the
 * **role portion** of the matching backend Policy. The Policies also apply
 * per-record tenant checks (`school_id` via `SchoolScope`) that the frontend
 * cannot and need not reproduce — the SPA only ever sees its own school's data.
 *
 * Kept in sync with `App\Enums\Role` (English identifiers).
 */

/**
 * Roles with automatic, school-wide visibility of groups and students.
 * Mirror of `App\Enums\Role::schoolWideValues()`.
 */
export const SCHOOL_WIDE_ROLES: readonly Role[] = ["director", "psychopedagogue"]

// ── Low-level role helpers ──────────────────────────────────────────────────

/** True when the user holds the given role. */
export function hasRole(user: User | null, role: Role): boolean {
  return user?.roles.includes(role) ?? false
}

/** True when the user holds at least one of the given roles. */
export function hasAnyRole(user: User | null, roles: readonly Role[]): boolean {
  return user?.roles.some((role) => roles.includes(role)) ?? false
}

/** Mirror of `$user->hasRole('director')`. */
export function isDirector(user: User | null): boolean {
  return hasRole(user, "director")
}

/** Mirror of `$user->hasRole('psychopedagogue')`. */
export function isPsychopedagogue(user: User | null): boolean {
  return hasRole(user, "psychopedagogue")
}

/** Mirror of `$user->hasRole('teacher')`. */
export function isTeacher(user: User | null): boolean {
  return hasRole(user, "teacher")
}

/**
 * Director or psychopedagogue. Mirror of
 * `$user->hasAnyRole(Role::schoolWideValues())` — the roles with school-wide
 * access. A teacher is not school-wide staff.
 */
export function isSchoolWideStaff(user: User | null): boolean {
  return hasAnyRole(user, SCHOOL_WIDE_ROLES)
}

// ── Groups ──────────────────────────────────────────────────────────────────

/** Create/edit a Group. Mirror of `GroupPolicy::create`/`update` (role portion): director only. */
export function canManageGroups(user: User | null): boolean {
  return isDirector(user)
}

/** Delete a Group. Mirror of `GroupPolicy::delete` (role portion): director only. */
export function canDeleteGroup(user: User | null): boolean {
  return isDirector(user)
}

// ── Students ────────────────────────────────────────────────────────────────

/** Create/edit a Student. Mirror of `StudentPolicy::create`/`update` (role portion): school-wide staff. */
export function canManageStudents(user: User | null): boolean {
  return isSchoolWideStaff(user)
}

/**
 * Delete a Student. Mirror of `StudentPolicy::delete` (role portion):
 * **director only** — note this is narrower than create/edit, which a
 * psychopedagogue may also do.
 */
export function canDeleteStudent(user: User | null): boolean {
  return isDirector(user)
}

/**
 * UX heuristic: whether to show the clinical-profile block in the student form.
 * Mirror of `StudentPolicy::viewClinicalProfile` (role portion): school-wide
 * staff. This does NOT filter data — the sensitive fields already arrive absent
 * from the backend when they don't apply (see `StudentResource`); this only
 * decides whether to render the edit inputs.
 */
export function canViewClinicalProfileUX(user: User | null): boolean {
  return isSchoolWideStaff(user)
}

// ── Forward-looking helpers (Sessions 8 & 9) ────────────────────────────────
// Added now, though nothing uses them yet, so the later sessions import them
// instead of re-deriving the same role logic (same rationale as
// `User::teachesGroup`/`teachesStudent` in `docs/prompts/02-roles-permisos.md` §3).

/**
 * Resolve an alert. Director or psychopedagogue.
 * Mirror of `AlertPolicy::resolve` → `StudentPolicy::viewClinicalProfile`.
 */
export function canResolveAlert(user: User | null): boolean {
  return isSchoolWideStaff(user)
}

/**
 * Approve/reject an accommodation. Director or psychopedagogue.
 * Mirror of `AccommodationPolicy::approve`.
 */
export function canApproveAccommodation(user: User | null): boolean {
  return isSchoolWideStaff(user)
}

/**
 * Roles allowed to propose a Barrier↔Accommodation link. Mirror of
 * `BarrierPolicy::update` (role portion, reused by
 * `AttachBarrierAccommodationRequest::authorize` — see the request class):
 * teacher and psychopedagogue. **Director is deliberately excluded** here to
 * match the backend Policy (BarrierPolicy explicitly does NOT list director
 * for create/update/delete — see the class docblock).
 */
export function canProposeBarrierAccommodation(user: User | null): boolean {
  return hasAnyRole(user, ["teacher", "psychopedagogue"])
}

/**
 * Validate a barrier↔accommodation link. Director or psychopedagogue, AND the
 * validator must not be the person who proposed it (four-eyes rule). Mirror of
 * `BarrierAccommodationController::validateLink`.
 */
export function canValidateBarrierAccommodation(
  user: User | null,
  proposedById: number,
): boolean {
  return isSchoolWideStaff(user) && user?.id !== proposedById
}

/**
 * View a student's tracking history. Director or psychopedagogue.
 * Mirror of `StudentHistoryController` (school-wide staff).
 */
export function canViewStudentHistory(user: User | null): boolean {
  return isSchoolWideStaff(user)
}

/**
 * View the adoption dashboard. Director only.
 * Mirror of `SchoolPolicy::viewAdoptionDashboard`.
 */
export function canViewAdoptionDashboard(user: User | null): boolean {
  return isDirector(user)
}
