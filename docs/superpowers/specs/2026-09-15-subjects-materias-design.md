# Design — Subjects (Materias)

**Date:** 2026-09-15
**Status:** Approved (brainstorm), pending implementation plans
**Scope:** Introduce a first-class `Subject` entity, assign teachers to subjects
per group, tie assessments to a subject, and slice the student tracking profile
into general vs. per-subject statistics.

## Problem

Aula+ has no first-class notion of a school subject ("materia"). Today:

- `AnnualPlan.subject` is a **free-text string** column — not an entity, not
  validated, not reusable.
- The global `CurricularItem` hierarchy has a `Subject`-type node, but it belongs
  to a shared `CurricularFramework` (e.g. "ANEP EBI"), not to a school, and
  teachers are not assigned to it.
- `group_teacher` links a teacher to a group with **no subject** — a teacher just
  "leads" the group.
- `Assessment` belongs to a group + teacher (+ curricular items); it has no
  subject.
- The student tracking profile (`StudentTrackingController`,
  `PerformanceTimelineBuilder`) aggregates a student's data purely by group
  membership — everything is "general," nothing is sliced by subject.

We want: each teacher teaches one or more subjects; each assessment is of a
subject; the student tracking profile shows some statistics general and some
per-subject.

## Decisions (from brainstorm)

| # | Decision |
|---|----------|
| A1 | `Subject` is **school-owned** (tenant-scoped via `BelongsToSchool`), managed by directors. |
| A2 | New tenant `Subject` is separate from the global framework hierarchy (leave it untouched). Migrate `AnnualPlan.subject` string → FK to `Subject`. Keep the door open to link a `Subject` to a framework `CurricularItem` later. |
| B1 | Teacher↔subject is modeled **per group assignment**: `group_teacher` gains `subject_id` (row = "teacher T teaches subject S to group G"). The flat "subjects a teacher teaches" is **derived** (distinct `subject_id` across their rows). |
| B1-sub | **Option A (uniform):** always one row per subject — a primary homeroom teacher gets one `group_teacher` row per subject explicitly. No nullable-`subject_id` "teaches everything" shortcut. |
| B2 | A group's subject set is **implied by assignments** — no separate `group_subject` table for now. |
| C1 | `Assessment.subject_id` is **required** and validated: subject belongs to the school AND the teacher is assigned that subject in the target group. |
| C2 | **No production data** → `subject_id` is NOT NULL from the start; no nullable phase, no backfill. |
| D1 | **General:** active accommodations, active barriers, open alerts, recent comments, total assessment count, overall average. **Per-subject:** average score + assessment count (per subject the student has results in). |
| D2 | The performance chart gains a **subject selector**, default "Todas las materias" (one subject at a time; not overlaid lines). |
| D3 | Accommodations and barriers **stay general** (student-level). Per-assessment overrides already cover the granular case. |
| E | **No subject-level access narrowing.** Group-based visibility for the whole academic profile stays as-is; the clinical-profile gate is untouched. Subject only labels/filters academic data. |

## Data model

### `subjects` (new, tenant-scoped)

| column | type | notes |
|--------|------|-------|
| id | pk | |
| school_id | fk → schools, not null | via `BelongsToSchool` global scope + auto-fill |
| name | string, not null | e.g. "Matemática" (Spanish content, entered by the school) |
| short_code | string, nullable | e.g. "MAT" — for compact badges |
| color | string, nullable | for chart line / badge tint |
| timestamps, soft deletes | | |

- Unique per school: `(school_id, name)`.
- Model: `App\Models\Subject` — `use BelongsToSchool, Auditable, HasFactory`.

### `group_teacher` (modify)

- Add `subject_id` FK → `subjects`, **not null** (Option A).
- Unique key becomes `(group_id, teacher_id, subject_id)`.
- A secondary teacher of two subjects in one group = two rows. A primary
  homeroom teacher = one row per subject.

### `assessments` (modify)

- Add `subject_id` FK → `subjects`, **not null**.

### `annual_plans` (modify)

- Add `subject_id` FK → `subjects`, not null; **drop** the `subject` string column.
- Update `App\Actions\AI\ApplyProposal::applyAnnualPlan` to resolve/require a
  `subject_id` from the proposal `input_parameters` instead of `$params['subject']`.

## Model relationships & helpers

- `Subject`: `belongsTo(School)`; `hasMany(Assessment)`; `belongsToMany(User)` and
  `belongsToMany(Group)` through `group_teacher` (for "who teaches this subject").
- `Group::teachers()` — keep, but the pivot now also carries `subject_id`
  (add to `withPivot`). `isLedBy` / `scopeVisibleTo` semantics unchanged
  (an `exists()` over the pivot still answers "does this user lead this group").
- `User::subjects()` — **derived**, distinct subjects across the user's
  `group_teacher` rows (flat capability list).
- `User::teachesSubjectInGroup(Group $group, Subject $subject): bool` — new,
  single source of truth for the assessment validation.
- `Assessment::subject()` — `belongsTo(Subject)`.
- `AnnualPlan::subject()` — `belongsTo(Subject)` (replaces the string accessor).

## Authorization

- `SubjectPolicy`: `create`/`update`/`delete` → **director** only;
  `viewAny`/`view` → any authenticated staff (pickers and filters need the list).
- Teacher-subject assignment endpoints: **director**-managed (attach/detach a
  `(teacher, subject)` to a group).
- `StoreAssessmentRequest` / `UpdateAssessmentRequest`: keep the existing
  role + `teachesGroup` layering; **add** `subject_id` required + a rule that the
  subject belongs to the school and `teachesSubjectInGroup($group, $subject)` is
  true. No new policy behavior — the group-specific check stays in the request
  layer, as the assessment spec already prescribes.
- No subject-level filtering of what a teacher can *read* (decision E).

## Tracking changes

- `StudentTrackingResource` gains a `by_subject` block: for each subject the
  student has `AssessmentResult`s in — `{ subject, average, assessment_count }`.
  General figures (accommodations, barriers, alerts, comments, total count,
  overall average) are unchanged. The controller's 60s raw-data cache and the
  per-request role-based filtering are preserved; the per-subject aggregation
  is computed from the same result set.
- `StudentPerformanceTimelineController` + `PerformanceTimelineBuilder::results()`
  accept an optional `subject_id` filter. The builder already joins `assessments`,
  so the filter is a `where('assessments.subject_id', …)`. `marks` are unaffected
  (they are not subject-scoped). `StudentPerformanceTimelineRequest` validates the
  optional `subject_id` (must belong to the school).
- Group profile "Programa" tab: **data plumbing only** this phase (expose subject
  coverage); the full tab UI is out of scope unless requested.

## Frontend surfaces

- **Subjects CRUD** screen (director): list + create/edit/delete, using the ui/
  design-standard components (`PageHeader`, `SectionCard`, `EmptyState`, etc.).
- **Teacher subject-assignment** UI: assign `(teacher, subject)` to a group.
- **Assessment form**: add a subject `<Select>` (required); options limited to the
  subjects the current teacher is assigned in the target group.
- **Student tracking profile**: per-subject stat cards + a subject filter that
  drives `StudentPerformanceChart.tsx` (default "Todas las materias").
- Spanish labels and any code→label mapping live in `web/src/types.ts`; code
  identifiers stay English.

## Delivery — nightly sessions

Each is a single-piece, independently runnable overnight session in the
`docs/prompts` style, shipping with tests (Pest for backend, Vitest/RTL for
frontend). Dependency order:

1. **Subject entity + CRUD + policy** (backend) — table, model, `SubjectPolicy`,
   controller, Form Requests, factory, tests.
2. **Teacher-subject assignments** (backend) — `subject_id` on `group_teacher`,
   relations/helpers (`teachesSubjectInGroup`, derived `User::subjects()`),
   director assignment endpoints, tests.
3. **`Assessment.subject_id` + AnnualPlan migration + validation** (backend) —
   assessment + annual_plan migrations, `ApplyProposal` update, request
   validation, `AssessmentResource`, factory, tests.
4. **Per-subject tracking + chart subject filter** (backend) — `by_subject` in
   `StudentTrackingResource`, `subject_id` filter in the timeline
   controller/builder + request, tests.
5. **Frontend: subjects admin + teacher assignment UI.**
6. **Frontend: assessment subject picker + per-subject tracking UI.**

## Out of scope

- Linking `Subject` to the global framework `CurricularItem` hierarchy.
- A `group_subject` curriculum table (subjects are implied by assignments).
- Subject-level access restrictions (decision E).
- The full group-profile "Programa" tab UI (data plumbing only).
- Nullable-`subject_id` "teaches everything" shortcut (rejected in favor of
  Option A).
