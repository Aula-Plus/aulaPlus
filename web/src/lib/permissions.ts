import type { Group, Role, User } from "@/types"

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

// ── Subjects / materias (Sesión 1) ──────────────────────────────────────────

/**
 * Directors manage the school's subject catalog (mirror of SubjectPolicy).
 * UX-only gate — the backend enforces the real rule on every request.
 */
export function canManageSubjects(user: User | null): boolean {
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

// ── Assessments (Sesión 10) ─────────────────────────────────────────────────

/**
 * Create/edit an assessment and load its results for a group. Mirror of the
 * two-layered backend rule (docs/prompts/13-evaluaciones-resultados.md §1):
 * `AssessmentPolicy::create` requires the **teacher** role, and the
 * `StoreAssessmentRequest`/`AssessmentResultPolicy` additionally require the
 * teacher to actually lead the target group (`$user->teachesGroup($group)`).
 *
 * The frontend approximates that group-ownership check with the group's
 * `teachers` list (the only teacher↔group link the SPA has). Director and
 * psychopedagogue are intentionally excluded — they get read-only access to
 * scores, they never author assessments. Like every helper here this is UX
 * only; the Controller + Policy remain the security boundary.
 */
export function canManageAssessments(
  user: User | null,
  group: Pick<Group, "teachers"> | null,
): boolean {
  if (!user || !group) {
    return false
  }

  return isTeacher(user) && group.teachers.some((teacher) => teacher.id === user.id)
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

/**
 * Show the create/edit-accommodation UI (docs/prompts/24 §3). Deliberately
 * gated on the SAME roles that may already SEE accommodations
 * (`canViewClinicalProfileUX` → school-wide staff), not on the looser backend
 * `AccommodationPolicy::create`/`update` (which allow any authenticated role /
 * anyone sharing a school).
 *
 * This is a UX decision, not a bug fix: (a) the accommodations section only
 * exists for viewers with clinical access, so offering "create an accommodation"
 * to someone who cannot even see the existing ones makes no sense; (b) it keeps
 * field-level access role-consistent (CLAUDE.md security rule 11). The backend
 * Policy remains the security boundary and is intentionally left untouched —
 * hardening it is not this session's task.
 */
export function canManageAccommodations(user: User | null | undefined): boolean {
  return canViewClinicalProfileUX(user ?? null)
}

// ── Screening tests / pruebas de sondeo (Sesión 17) ─────────────────────────

/**
 * Manage the screening-test module: create a type, author/edit a design, apply
 * a test to a group, load results, and view the code→student roster. Mirror of
 * the role portion shared by `ScreeningTestTypePolicy::create`,
 * `ScreeningTestDesignPolicy::create`, `ScreeningTestApplicationPolicy::create`
 * /`viewRoster`/`viewResults` and `ScreeningTestResultPolicy::update` — all
 * **psychopedagogy only**. No helper here ever enables `teacher`: this module
 * has no teacher view at all (docs/prompts/12-frontend-pruebas-de-sondeo.md §3,
 * the same "línea roja" applied to PTP/ajustes in earlier sessions).
 */
export function canManageScreeningTests(user: User | null): boolean {
  return isPsychopedagogue(user)
}

/**
 * Approve/reject a screening-test design. Mirror of
 * `ScreeningTestDesignPolicy::approve`/`reject` (role portion): **director
 * only**. The mirror of `canApproveAccommodation`'s director half — but note
 * that helper also allows psychopedagogue, whereas design approval is stricter
 * (director alone), so this is its own predicate rather than a reuse.
 */
export function canApproveScreeningTestDesign(user: User | null): boolean {
  return isDirector(user)
}
