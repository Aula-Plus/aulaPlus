# Backend review — 2026-09-13

Unsupervised deep backend review of the eight Sesión 6–13 feature branches, each
an open PR against `develop` (none merged). For every branch: full test suite
run (`php artisan test`, in-memory SQLite — no Sail/Docker), deep review against
the branch's own spec file and the `CLAUDE.md` hard requirements (multi-tenancy,
authorization, input validation, field-level clinical-data access, PII/secret
logging, English-in-code/Spanish-in-UI, correctness and optimality), then
concrete defects and safe optimizations fixed and pushed to the same branch.
**No branch was merged.** Subjective/design-level changes were recorded as
recommendations rather than applied.

## Method notes

- The branches are **stacked** (8→6, 10→6+8+9, 11→6+7+8+9, 12→7). Each branch's
  own contribution is exactly its tip commit, so review and fixes were scoped to
  that own diff; inherited lower-session code was reviewed on its own branch.
  A fix to a lower session therefore does **not** propagate to the branches that
  stack on it — it arrives when that PR merges/rebases. Cross-session items are
  called out below.
- A second automated session was pushing to some of these branches during the
  review. Its commits were integrated (fetch + build on top, never force-push);
  where it had already landed a fix, that is noted and not duplicated.

## Summary table

| Sesión | Branch (PR) | Tests before → after | Defects fixed | Optimizations applied |
|---|---|---|---|---|
| 6  | `feature/sesion-06-evaluaciones-resultados` (#59) | 191 → 191 | 1 | 1 (+1 already landed) |
| 7  | `feature/sesion-07-seguimiento-programado` (#60) | 179 → 179 | 1 | 0 |
| 8  | `feature/sesion-08-ajustes-categoria-instancia` (#63) | 206 → 207 | 1 | 1 |
| 9  | `feature/sesion-09-comentarios-alcance` (#61) | 173 → 173 | 0 | 0 |
| 10 | `feature/sesion-10-linea-tiempo-alumno` (#65) | 222 → 222 | 0 | 1 |
| 11 | `feature/sesion-11-perfil-de-grupo` (#66) | 241 → 241 | 0 | 1 |
| 12 | `feature/sesion-12-grupos-listado` (#64) | 188 → 188 | 0 | 0 |
| 13 | `feature/sesion-13-pruebas-de-sondeo` (#62) | 198 → 199 | 1 | 1 (+1 already landed) |

**MISSING branches:** none — all eight branches existed on `origin` and were reviewed.

All suites green and Pint clean on every branch after the fixes. Overall verdict:
the eight branches are high quality and security-conscious — tenancy, authorization,
field-level clinical gating, comment visibility (`author_only`/`visible_to`),
FormRequest validation and audit redaction were correct across the board. No
cross-tenant leak, no unauthenticated privileged endpoint, and no allow-all
policy was introduced by any of the eight sessions. Findings were a handful of
concrete defects and safe optimizations plus design/product recommendations.

---

## Sesión 6 — Evaluaciones y resultados (PR #59)

**Tests:** 191 → 191.

**Defects fixed**
- English-in-code guardrail: the group-membership validation failure message in
  `StoreAssessmentResultsRequest` was in Spanish (`'El alumno no pertenece…'`).
  Replaced with English, matching the sibling `GenerateAIProposalRequest`.
  (Invisible to the suite — tests assert on the field key, not the message.)

**Optimizations applied**
- Added index `(school_id, administered_at)` on `assessments`. The Sesión 10
  student-performance-timeline endpoint both filters and orders by
  `administered_at` within a school, and the column shipped index-less.
- The N+1 in the batch validator (`studentBelongsToGroup` ran ~2N membership
  queries) was **already resolved** by a prior `perf(api): resolve enrolled
  student ids once` commit on the branch — not duplicated.

**Open recommendations**
- `AssessmentResultPolicy::viewAny()` returns bare `true`. Dead code (no endpoint
  calls it) and consistent with the pre-existing policy convention, but the one
  place touching guardrail #4's "no allow-all".
- `score` is a `decimal:2` cast → serialised as a JSON string (`"85.50"`); the
  Perfil de alumno chart will need `parseFloat`, or cast to float at the resource.
- The `->after('variant_number')` migration hint is MySQL-only (ignored on
  PostgreSQL). Cosmetic.

## Sesión 7 — Seguimiento programado / ScheduledFollowUp (PR #60)

**Tests:** 179 → 179.

**Defects fixed**
- English-in-code guardrail: the model docblock and the routes comment quoted the
  Spanish product phrase *"se programa, no vence solo"*, each already paraphrased
  in English on the same line. Dropped the redundant Spanish quote (comment-only).

**Optimizations applied** — none warranted.

**Open recommendations**
- `resolve` isn't guarded against re-resolution (silently overwrites
  `resolved_by_id`/`resolved_at`); consider short-circuiting (return / 409).
- `due_date` accepts past dates (born overdue) — add `after_or_equal:today` if
  backfill isn't intended.
- `ScheduledFollowUpPolicy::view` is defined but unused (no single-follow-up GET).
- Consider `(school_id, resolved, due_date)` index to anticipate Sesión 11's
  group-wide overdue listing (partially overlaps the current
  `(school_id, student_id)`).

## Sesión 8 — Categoría de ajuste + override por instancia (PR #63)

**Tests:** 206 → 207 (added 1 regression test).

**Defects fixed**
- Duplicate override → 500. A repeat POST for the same `(accommodation,
  assessment)` pair hit the DB unique constraint as an unhandled `QueryException`.
  Added a scoped `Rule::unique` on `assessment_id` so a duplicate returns a clean
  422; regression test asserts 422 (not 500) and no second row.

**Optimizations applied**
- Added index on `deactivated_by_id` (FK columns aren't auto-indexed on
  PostgreSQL). The two indexes backing current queries were already present.

**Open recommendations**
- **Listing gate deviates from the spec's letter (borderline, no real leak
  today).** Spec §2 names the `view-clinical-profile` gate (director/psychopedagogue
  only) for the listing, but the controller authorizes `AssessmentPolicy::view`,
  which also admits the owning teacher (who reads the clinical `reason`). Because
  `create` is owner-only, a teacher only reads `reason` values they authored, so
  no cross-role leak today — but the guarantee breaks if `create` is loosened.
  The spec is internally contradictory here (its Policy paragraph says
  `view` = shares-school) and an existing test intentionally asserts the owning
  teacher can list, so this was left for a product decision rather than silently
  changing behavior.
- `AccommodationPolicy::create`/`update` remain school-wide for any role
  (explicitly out of scope for this session) — flag for a later hardening pass.
- `reason` accepts unbounded text — consider an explicit `max:`.

## Sesión 9 — Comentarios / alcance "solo quien escribe" (PR #61)

**Tests:** 173 → 173. **No changes required — clean and secure.**

**Verified**
- `author_only` scope enforced in server code across all three comment-surfacing
  paths (`StudentCommentController::index`, `GroupCommentController::index` via the
  `visibleToRole` DB scope, and `StudentTrackingResource` via a per-viewer
  `isVisibleTo` re-filter applied outside the app cache). DB scope and PHP method
  share the same truth table; migration default `author_only = false` avoids NULL
  three-valued-logic gaps. `comments_count` role-gated by dropping the key.

**Open recommendations**
- `comments_count` reveals the *existence* (not content) of `author_only` notes
  to school-wide roles — spec-sanctioned (§2) and asserted by a test; flagged so
  the trade-off is conscious.
- Pre-existing (widened, not introduced): student tracking applies
  `RECENT_COMMENTS_LIMIT` before visibility filtering, so a viewer can get fewer
  than 10 visible comments. Not a leak.

## Sesión 10 — Línea de tiempo de alumno (PR #65)

**Tests:** 222 → 222. **No defects.**

**Optimizations applied**
- `PerformanceTimelineBuilder::inRange` re-parsed the `from`/`to` strings on every
  call (once per accommodation, audit log, override, barrier, comment). Parse the
  two window bounds once in `marks()` and pass the resolved `Carbon` bounds down —
  behavior identical.

**Verified**
- Tenant isolation real (`SchoolScope.qualifyColumn` prevents the ambiguous
  `school_id` join error); clinical marks gated by `view-clinical-profile`;
  `author_only`/`visible_to` honored; no PII/content leaked; bounded query count.

**Open recommendations**
- `assessments.administered_at` index is being added on the Sesión 6 branch
  (#59); arrives here on rebase/merge (not added here to avoid diverging the
  inherited migration).
- `calendar_events.start_at` unindexed (minor); deactivation audit query could be
  DB-window-bounded (left as-is to avoid PHP/SQL window-semantics mismatch); no
  max width on the `from`/`to` window.

## Sesión 11 — Perfil de grupo / agregadores (PR #66)

**Tests:** 241 → 241. **No defects.**

**Optimizations applied**
- Removed an N+1 in `ScheduledFollowUpController::indexForGroup`: the per-row
  `$user->can('view', $followUp)` runs `teachesStudent()` (a `whereHas → exists`
  query) once per follow-up. It is provably redundant — the FormRequest already
  enforces `GroupPolicy::view` and every follow-up belongs to a student in the
  group, so the check was always true. Removed the per-row filter (and its dead
  `student` eager-load); access stays gated at the group level. Behavior unchanged.

**Verified**
- Aggregate endpoints emit no `student_id`/names (asserted at string level);
  clinical marks gated; `concerning_comment` honors `visible_to` + `author_only`;
  zero cross-tenant leakage.

**Open recommendations (design-level, not applied)**
- Several aggregates are computed in PHP after `->get()`; the deactivation path
  loads all `Updated` audit logs across full history when no window is given.
  Push `GROUP BY` + `COUNT(DISTINCT student_id)` into SQL and always bound the
  audit query by the window.
- No max width on the `from`/`to` window.
- The clinical-gate check iterates all students but reduces to a
  student-independent role check — simplify to `hasAnyRole(schoolWideValues())`.

## Sesión 12 — Grupos listado / active_tracking_count (PR #64)

**Tests:** 188 → 188. **No changes required — correct and efficient.**

**Verified**
- Not an N+1 (fixed query count regardless of group count, dedicated test);
  zero cross-tenant leakage (qualified `SchoolScope` on every join side);
  role-scoped; union-of-distinct-students correct; `isEffective()` replicated in
  SQL incl. pending-approval exclusion; no PII; indexes adequate.

**Open recommendations (spec §1 does not require these — product questions)**
- `trackingPairs` joins `group_student` on `group_id` only, not `school_year`, so
  a prior-year enrolment is counted toward the current listing.
- Soft-deleted (departed) students are still counted (queries never touch the
  `students` table so its soft-delete scope isn't applied).
- The PHP-union could collapse into a single SQL `UNION` + `COUNT(DISTINCT …)` at
  large scale (design-level).

## Sesión 13 — Pruebas de sondeo / screening tests (PR #62)

**Tests:** 198 → 199 (added 1 regression test).

**Defects fixed**
- Roster not year-scoped → duplicate codes for one student.
  `CreateScreeningTestApplication` built the roster from an unscoped
  `$group->students()`. Because `group_student` is historized per `school_year`
  (unique on `student_id + school_year`), a student enrolled in the same group a
  prior year appears as a second pivot row → a second `ScreeningTestResult` with a
  second sequential `code` (inflated `results_count` **and** a de-anonymisation
  weakness). Scoped the roster to the group's `school_year`, matching the
  `Student::groupForYear()` / `StudentController` convention. Regression test added.

**Optimizations applied**
- Added index on `screening_test_results.student_id` (FK not auto-indexed on
  PostgreSQL).
- The type-listing N+1 (`currentApprovedDesign()` per type) was **already
  resolved** by a prior `perf(api): avoid N+1 when listing screening-test types`
  commit on the branch — not duplicated.

**Verified**
- All four new tables carry `school_id`; all four models `BelongsToSchool +
  Auditable`; teacher excluded module-wide; `code → student` mapping is
  psychopedagogue-only with the director deliberately excluded; results resource
  never emits `student_id`/name; `student_id` excluded from the audit diff; color
  persisted at load time and not re-derived on read.

**Open recommendations**
- Codes zero-padded to 2 digits and sorted as strings → `"100"` sorts before
  `"99"` past 99 students. Pad to 3 or `orderByRaw('code::int')`.

---

## Cross-cutting notes

- **FK indexing on PostgreSQL:** three new tables shipped FK columns without an
  index (PostgreSQL, unlike MySQL, does not auto-index FKs). Added where relevant
  (`assessment_results` via `administered_at` on the parent; `screening_test_results.student_id`;
  `accommodation_instance_overrides.deactivated_by_id`). Worth a lint/checklist
  item for future migrations.
- **English-in-code:** two sessions introduced Spanish in code (a validation
  message in Sesión 6, comments in Sesión 7). Both fixed. The rest of the
  codebase already keeps code/comments English.
- **No merges performed.** Each PR is left green and ready for human review.
