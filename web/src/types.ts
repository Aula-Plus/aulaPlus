export type Role = "teacher" | "director" | "psychopedagogue"

/** User-facing Spanish labels for roles (the app UI is in Spanish). */
export const roleLabels: Record<Role, string> = {
  teacher: "Docente",
  director: "Director",
  psychopedagogue: "Psicopedagogo",
}

export interface School {
  id: number
  name: string
}

export interface User {
  id: number
  name: string
  email: string
  roles: Role[]
  school?: School
}

export interface Group {
  id: number
  name: string
  level: string | null
  school_year: number
  group_profile: unknown | null
  related_documents: unknown | null
  teachers: { id: number; name: string }[]
  /**
   * How many students in the group have active tracking (an effective
   * accommodation, an active barrier, an open alert, or an unresolved
   * scheduled follow-up) — aggregate only, never names a student. See
   * `docs/prompts/22-grupos-listado.md` §1.
   *
   * Optional because the backend only computes it for the groups listing
   * (`GET /api/groups`), where it is always a number (`0`, never null). It is
   * omitted from the show/create/update responses (`GroupResource` guards it
   * with `when()`), so the shared `Group` type marks it optional.
   */
  active_tracking_count?: number
}

export interface Student {
  id: number
  full_name: string
  photo_url: string | null
  birth_date: string | null
  enrollment_year: number
  has_therapeutic_companion: boolean
  groups: { id: number; name: string; school_year: number }[]
  // Absent from the JSON (not null) when the viewer lacks
  // view-clinical-profile on this student.
  learning_profile?: unknown
  tracking_notes?: string
  individual_profile?: unknown
  related_documents?: unknown
}

// ── Institutional tracking (Sesión 8) ──────────────────────────────────────
// Mirrors the backend Session 4 API (docs/prompts/04-seguimiento-
// institucional.md) — the aggregator/tracking endpoints under /api/v1.
// Field-level gating happens server-side: clinical fields (accommodations,
// barriers, alerts) are simply ABSENT from the JSON for a viewer who lacks
// view-clinical-profile, so their detail arrays are optional here while the
// counts are always present.

/** Author-set tone of a comment. Mirror of `App\Enums\CommentTone`. */
export type CommentTone = "positive" | "neutral" | "concerning"

export const commentToneLabels: Record<CommentTone, string> = {
  positive: "Positivo",
  neutral: "Neutral",
  concerning: "Preocupante",
}

export interface Comment {
  id: number
  author_id: number
  /** Polymorphic base name, e.g. "Student" or "Group". */
  commentable_type: string
  commentable_id: number
  content: string
  tone: CommentTone | null
  /**
   * Roles allowed to see the comment, or null when visible to everyone who
   * can see the parent record. Never an empty array from the API.
   */
  visible_to: Role[] | null
  /**
   * When `true`, the comment is visible only to its `author_id` — stricter than
   * any `visible_to` value (which the backend forces to `null` in that case).
   * Mutually exclusive with `visible_to` (docs/prompts/19-comentarios-alcance.md
   * §1). The backend already filters comments by viewer, so an `author_only`
   * comment reaches the client only when the viewer is its own author.
   */
  author_only: boolean
  created_at: string | null
}

/** Mirror of `App\Enums\AlertType`. */
export type AlertType = "performance" | "behavior" | "planning_attendance"

export const alertTypeLabels: Record<AlertType, string> = {
  performance: "Rendimiento",
  behavior: "Conducta",
  planning_attendance: "Planificación / asistencia",
}

/** Mirror of `App\Enums\AlertSeverity`. */
export type AlertSeverity = "low" | "medium" | "high"

export const alertSeverityLabels: Record<AlertSeverity, string> = {
  low: "Baja",
  medium: "Media",
  high: "Alta",
}

export interface Alert {
  id: number
  student_id: number
  type: AlertType
  severity: AlertSeverity
  description: string
  resolved: boolean
  resolved_by_id: number | null
  resolved_at: string | null
  created_at: string | null
}

/** Mirror of `App\Enums\AssessmentType`. */
export type AssessmentType = "written" | "assignment" | "project" | "oral" | "submission"

export const assessmentTypeLabels: Record<AssessmentType, string> = {
  written: "Escrita",
  assignment: "Tarea",
  project: "Proyecto",
  oral: "Oral",
  submission: "Entrega",
}

/** Compact assessment shape used by the student tracking view. */
export interface AssessmentSummary {
  id: number
  group_id: number
  type: AssessmentType
  variant_number: number | null
  created_at: string | null
}

/**
 * Full Assessment representation returned by the CRUD endpoints
 * (`GET/POST /api/v1/groups/{group}/assessments`,
 * `PATCH /api/v1/assessments/{assessment}`). Mirror of `AssessmentResource`
 * (backend Sesión 6, docs/prompts/13-evaluaciones-resultados.md §1) — the
 * superset of the compact {@see AssessmentSummary} above. `variant_number`
 * belongs to the Bloque 2 curricular flow and is not set by this session's
 * plain form, but it is part of the resource so it is typed here.
 */
export interface Assessment {
  id: number
  group_id: number
  teacher_id: number
  type: AssessmentType
  purpose: string | null
  duration_minutes: number | null
  variant_number: number | null
  /** ISO date (`YYYY-MM-DD`). */
  administered_at: string | null
  created_at: string | null
}

/**
 * One student's result on an assessment
 * (`GET/POST /api/v1/assessments/{assessment}/results`). Mirror of
 * `AssessmentResultResource` (backend Sesión 6). `score` is a decimal (0–999.99,
 * up to two places) serialized by the backend; feedback is optional.
 */
export interface AssessmentResult {
  id: number
  assessment_id: number
  student_id: number
  score: number
  feedback: string | null
  created_by_id: number | null
  created_at: string | null
  updated_at: string | null
}

/**
 * Student performance timeline (backend Sesión 10 — docs/prompts/20-linea-
 * tiempo-alumno.md; frontend docs/prompts/26-frontend-perfil-de-alumno.md).
 * Feeds the Perfil de alumno chart: the `results` performance line plus a
 * single `marks` array whose *content* varies by authorization (clinical gate
 * + comment visibility, both decided server-side) while the key is always
 * present. The client never filters marks by role — it renders exactly what the
 * response brings.
 */
export interface PerformanceResultPoint {
  assessment_id: number
  assessment_type: AssessmentType
  /** ISO date (`YYYY-MM-DD`) — the parent assessment's `administered_at`. */
  administered_at: string
  score: number
}

export type PerformanceMarkType =
  | "accommodation_activated"
  | "accommodation_deactivated"
  | "accommodation_instance_override"
  | "barrier_registered"
  | "concerning_comment"
  | "calendar_event"

export const performanceMarkTypeLabels: Record<PerformanceMarkType, string> = {
  accommodation_activated: "Adaptación activada",
  accommodation_deactivated: "Adaptación desactivada",
  accommodation_instance_override: "Adaptación desactivada para esta evaluación",
  barrier_registered: "Barrera registrada",
  concerning_comment: "Comentario preocupante",
  calendar_event: "Evento de calendario",
}

/**
 * A distinct colour per mark type for the Perfil de alumno chart (spec §5: "un
 * color distinto por type … no colores repetidos entre tipos"). Lives here,
 * next to the labels, so Sesión 15 (Perfil de grupo) reuses the same palette
 * this session fixes instead of reinventing one (docs/prompts/26 §Bloquea a).
 */
export const performanceMarkColors: Record<PerformanceMarkType, string> = {
  accommodation_activated: "#16a34a", // green
  accommodation_deactivated: "#dc2626", // red
  accommodation_instance_override: "#d97706", // amber
  barrier_registered: "#7c3aed", // violet
  concerning_comment: "#db2777", // pink
  calendar_event: "#0891b2", // cyan
}

interface PerformanceMarkBase {
  /** ISO-8601 datetime (UTC) of the underlying event. */
  date: string
}

/**
 * Discriminated by `type` (same pattern the domain uses elsewhere, e.g.
 * `AuditAction`) so each variant carries only the fields the backend actually
 * sends for it — no single type with ten optional columns.
 */
export type PerformanceMark =
  | (PerformanceMarkBase & { type: "accommodation_activated"; accommodation_id: number })
  | (PerformanceMarkBase & { type: "accommodation_deactivated"; accommodation_id: number })
  | (PerformanceMarkBase & {
      type: "accommodation_instance_override"
      accommodation_id: number
      assessment_id: number
      reason: string
    })
  | (PerformanceMarkBase & { type: "barrier_registered"; barrier_id: number })
  | (PerformanceMarkBase & { type: "concerning_comment"; comment_id: number })
  | (PerformanceMarkBase & { type: "calendar_event"; calendar_event_id: number; title: string })

export interface StudentPerformanceTimeline {
  results: PerformanceResultPoint[]
  marks: PerformanceMark[]
}

/**
 * The three pedagogical categories of an accommodation (mirror of
 * `App\Enums\AccommodationCategory`, docs/prompts/18 §1) — independent of the
 * free-text `type`. English identifiers in code; the Spanish labels below are
 * the only user-facing text, per CLAUDE.md.
 */
export type AccommodationCategory = "access" | "content" | "criteria"

export const accommodationCategoryLabels: Record<AccommodationCategory, string> = {
  access: "Acceso",
  content: "Contenido",
  criteria: "Criterio",
}

/**
 * Perfil de grupo aggregates (backend Sesión 11 — docs/prompts/21-perfil-de-
 * grupo.md; frontend docs/prompts/27-frontend-perfil-de-grupo.md). All three
 * feed sections on the already-existing GroupTrackingPage; none is a roster.
 */

/**
 * One row of GET /groups/{group}/accommodations-summary: the group's active
 * accommodations aggregated by the free-text `type` (two accommodations may
 * share a `category` yet be different concrete adjustments). `student_count`
 * counts DISTINCT students. The endpoint never names a student — it is safe for
 * any role that can see the group, with no extra clinical gate (spec §6).
 */
export interface GroupAccommodationSummaryEntry {
  type: string
  category: AccommodationCategory
  student_count: number
}

/**
 * One point of the group performance line: one per Assessment of the group (not
 * per time window). `average_score` is the mean of its results; `results_count`
 * is how many students have a score loaded (may be less than the group size).
 */
export interface GroupPerformanceResultPoint {
  assessment_id: number
  assessment_type: AssessmentType
  /** ISO date (`YYYY-MM-DD`) — the parent assessment's `administered_at`. */
  administered_at: string
  average_score: number
  results_count: number
}

/**
 * The five group mark types. Unlike the student timeline (Sesión 14) there is
 * NO `accommodation_instance_override` — the group aggregate is a count by
 * (type, date), never a per-student instance (spec §2 note).
 */
export type GroupPerformanceMarkType =
  | "accommodation_activated"
  | "accommodation_deactivated"
  | "barrier_registered"
  | "concerning_comment"
  | "calendar_event"

export const groupPerformanceMarkTypeLabels: Record<GroupPerformanceMarkType, string> = {
  accommodation_activated: "Adaptaciones activadas",
  accommodation_deactivated: "Adaptaciones desactivadas",
  barrier_registered: "Barreras registradas",
  concerning_comment: "Comentarios preocupantes",
  calendar_event: "Evento de calendario",
}

interface GroupPerformanceMarkBase {
  /** ISO date (`YYYY-MM-DD`) of the aggregated events. */
  date: string
}

/**
 * Discriminated by `type` like {@see PerformanceMark}, but every variant is an
 * AGGREGATE count — no `student_id`, no per-student identifier ever appears
 * (spec §2 note, red line of pantalla 3: "agregado por tipo, nunca nómina").
 */
export type GroupPerformanceMark =
  | (GroupPerformanceMarkBase & {
      type: "accommodation_activated"
      accommodation_type: string
      count: number
    })
  | (GroupPerformanceMarkBase & {
      type: "accommodation_deactivated"
      accommodation_type: string
      count: number
    })
  | (GroupPerformanceMarkBase & { type: "barrier_registered"; count: number })
  | (GroupPerformanceMarkBase & { type: "concerning_comment"; count: number })
  | (GroupPerformanceMarkBase & {
      type: "calendar_event"
      calendar_event_id: number
      title: string
    })

export interface GroupPerformanceTimeline {
  results: GroupPerformanceResultPoint[]
  marks: GroupPerformanceMark[]
}

export interface Accommodation {
  id: number
  student_id: number
  type: string
  category: AccommodationCategory
  active: boolean
  description: string | null
  focus_area: string | null
  requires_external_approval: boolean
  /**
   * Approval state for accommodations that require external approval:
   *   - `null` → pending an approve/reject decision (only meaningful when
   *     `requires_external_approval` is true).
   *   - `true` → approved, `false` → rejected.
   * When `requires_external_approval` is false, `approved` is not part of
   * the effective vigency test — use `is_effective` instead.
   */
  approved: boolean | null
  is_effective: boolean
  created_by_id: number | null
  created_at: string | null
  updated_at: string | null
}

/**
 * Body for creating/editing an Accommodation (docs/prompts/24 §2), matching
 * `StoreAccommodationRequest`/`UpdateAccommodationRequest`. `category` is
 * required on both create and edit (nullable in the DB only so pre-existing
 * factories keep working — every new write must set it).
 */
export interface AccommodationInput {
  type: string
  description: string
  focus_area: string
  category: AccommodationCategory
  requires_external_approval: boolean
  active: boolean
}

export interface Barrier {
  id: number
  description: string
  coping_strategy: string | null
  active: boolean
}

/**
 * Aggregated per-student tracking view (`GET /api/v1/students/{id}/tracking`).
 * `accommodations`, `barriers` and `alerts` are only present for a viewer with
 * view-clinical-profile; everyone else gets the `*_count` fields only.
 */
export interface StudentTracking {
  student: Student
  recent_assessments: AssessmentSummary[]
  accommodations?: Accommodation[]
  accommodations_count: number
  barriers?: Barrier[]
  barriers_count: number
  recent_comments: Comment[]
  alerts?: Alert[]
  open_alerts_count: number
}

/** Per-student summary row in the group tracking view (counts/booleans only). */
export interface GroupTrackingStudent {
  id: number
  full_name: string
  open_alerts_count: number
  has_active_accommodations: boolean
}

/** Aggregated per-group tracking view (`GET /api/v1/groups/{id}/tracking`). */
export interface GroupTracking {
  group: Group
  students: GroupTrackingStudent[]
  trend: {
    period_days: number
    assessments_count: number
    /**
     * Omitted entirely (not `0`, not `null`) for a viewer without a school-wide
     * role — the backend drops the key via `$this->when(...)`
     * (docs/prompts/19-comentarios-alcance.md §5).
     */
    comments_count?: number
  }
}

/** One weekly bucket of the adoption dashboard time series (Monday-start). */
export interface WeeklySeriesPoint {
  week_start: string
  count: number
}

/**
 * Director-only pilot-adoption dashboard
 * (`GET /api/v1/schools/{id}/adoption-dashboard`). Rates are percentages 0–100.
 */
export interface AdoptionDashboard {
  teacher_login_rate_30d: number
  teacher_planning_rate_30d: number
  weekly_login_series: WeeklySeriesPoint[]
  weekly_content_series: WeeklySeriesPoint[]
}

// ── Approval flows & traceability (Sesión 9) ────────────────────────────────
// Mirrors the backend Session 3 API (docs/prompts/03-flujos-aprobacion-
// trazabilidad.md). Only the shapes that reach the SPA are defined here —
// server-side workflow guards (four-eyes rule, business-state preconditions)
// remain the security boundary.

/**
 * An Accommodation as seen through a Barrier link
 * (`GET /api/v1/barriers/{barrier}/accommodations`). Mirror of
 * `BarrierAccommodationResource` — a subset of Accommodation's fields plus the
 * pivot columns (`proposed_by_id`, `validated`, `validated_by_id`).
 */
export interface BarrierAccommodationLink {
  /** The Accommodation's id — the link is uniquely keyed by (barrier, accommodation). */
  id: number
  type: string
  description: string | null
  focus_area: string | null
  proposed_by_id: number
  validated: boolean
  validated_by_id: number | null
}

/** Mirror of `App\Enums\AuditAction`. */
export type AuditAction = "created" | "updated" | "deleted"

export const auditActionLabels: Record<AuditAction, string> = {
  created: "Creado",
  updated: "Actualizado",
  deleted: "Eliminado",
}

/** Mirror of `App\Enums\AuditOrigin`. `system` entries have `user_id === null`. */
export type AuditOrigin = "user" | "system"

export const auditOriginLabels: Record<AuditOrigin, string> = {
  user: "Usuario",
  system: "Sistema",
}

/**
 * One row of the student audit timeline
 * (`GET /api/v1/students/{id}/history`). Mirror of `AuditLogResource`.
 *
 * `auditable_type` is the class-basename ("Accommodation" | "Barrier" |
 * "TechnicalReport"), not the fully-qualified PHP class. `changes` is the raw
 * `audit_logs.changes` JSON; its shape varies by action (a `created` row
 * carries the initial column values, an `updated` row carries
 * `{ before, after }` pairs), so it is typed loosely and rendered as
 * pretty-printed JSON in the UI rather than picked apart per action.
 */
export interface AuditLogEntry {
  id: number
  auditable_type: string
  auditable_id: number
  action: AuditAction
  /** `null` when `origin === "system"` (background jobs, seeders, etc.). */
  user_id: number | null
  origin: AuditOrigin
  changes: Record<string, unknown>
  created_at: string | null
}

/**
 * A scheduled follow-up on a student (`ScheduledFollowUpResource`, backend
 * Sesión 7 — docs/prompts/17-seguimiento-programado.md). The concrete face of
 * the rule "nothing expires on its own, someone scheduled it".
 */
export interface ScheduledFollowUp {
  id: number
  student_id: number
  description: string
  due_date: string
  created_by_id: number
  resolved: boolean
  resolved_by_id: number | null
  resolved_at: string | null
  resolution_note: string | null
  /**
   * Computed server-side (`!resolved && due_date <= today`, app timezone).
   * NEVER re-derive this condition in the client — read the boolean as-is.
   */
  is_overdue: boolean
  created_at: string | null
}

// ── Screening tests / pruebas de sondeo (Sesión 17) ─────────────────────────
// Mirrors the backend Session 13 API (docs/prompts/11-pruebas-de-sondeo.md;
// frontend docs/prompts/12-frontend-pruebas-de-sondeo.md) under /api/v1. This
// is a school-wide, psychopedagogy-led module; teachers never see any of it.
// The design/approval pair follows the same tri-state `approved` pattern as
// Accommodation; results are anonymised BY CODE and never carry a student name
// (that mapping lives only in the roster endpoint).

/**
 * The traffic-light band a screening score falls into (mirror of
 * `App\Enums\ScreeningColor`). English identifiers in code; the Spanish labels
 * below are the only user-facing text, per CLAUDE.md. The colour is always the
 * one the backend confirms on a loaded result — never recomputed on the client.
 */
export type ScreeningColor = "red" | "yellow" | "green"

export const screeningColorLabels: Record<ScreeningColor, string> = {
  red: "Rojo",
  yellow: "Amarillo",
  green: "Verde",
}

/**
 * One version of a screening test's design (mirror of
 * `ScreeningTestDesignResource`). "Editing" a design creates a new version left
 * `approved === null` (pending director approval); `true`/`false` once decided.
 * The version used to compute new colours is the most recent approved one.
 */
export interface ScreeningTestDesign {
  id: number
  screening_test_type_id: number
  cutoff_low: number
  cutoff_high: number
  meaning_red: string
  meaning_yellow: string
  meaning_green: string
  /** `null` pending, `true` approved, `false` rejected. */
  approved: boolean | null
  approved_by_id: number | null
  created_by_id: number | null
  created_at?: string | null
  updated_at?: string | null
}

/**
 * A kind of screening test offered by the school (mirror of
 * `ScreeningTestTypeResource`). `current_design` is the in-force APPROVED design
 * only (the resource resolves `currentApprovedDesign()`); a pending or rejected
 * design is not carried here — the API exposes no listing of non-approved
 * designs, so a freshly created pending design is known to the client only as
 * the POST response that created it.
 */
export interface ScreeningTestType {
  id: number
  name: string
  active: boolean
  created_by_id: number | null
  current_design: ScreeningTestDesign | null
  created_at?: string | null
  updated_at?: string | null
}

/**
 * A screening test applied to a group (mirror of
 * `ScreeningTestApplicationResource`). `application_date` is an ISO date
 * (`YYYY-MM-DD`). `results_count` is only present on the listing (`withCount`),
 * hence optional.
 */
export interface ScreeningTestApplication {
  id: number
  screening_test_type_id: number
  group_id: number
  applied_by_id: number | null
  application_date: string | null
  results_count?: number
  created_at?: string | null
  updated_at?: string | null
}

/**
 * One result of an application, the ANONYMISED by-code view (mirror of
 * `ScreeningTestResultResource`). It deliberately never carries `student_id` or
 * a name — the code→student mapping is exposed solely by the roster endpoint.
 * `score`/`color` are `null` until a score is loaded; `color` is whatever the
 * backend computed against the in-force design, never derived on the client.
 */
export interface ScreeningTestResult {
  id: number
  screening_test_application_id: number
  code: string
  score: number | null
  color: ScreeningColor | null
  loaded_by_id: number | null
  loaded_at: string | null
}

/**
 * One row of the printable roster (`GET .../roster`) — the SINGLE place code
 * and full name are ever crossed, psychopedagogy only. Note the backend field
 * is `full_name` (not `student_full_name` as the spec §2 draft suggested); the
 * real `ScreeningTestApplicationController::roster` shape wins.
 */
export interface ScreeningTestRosterEntry {
  code: string
  student_id: number
  full_name: string | null
}

/** Body for `POST /screening-test-types` (`StoreScreeningTestTypeRequest`). */
export interface ScreeningTestTypeInput {
  name: string
  active?: boolean
}

/**
 * Body for `POST /screening-test-types/{type}/designs`
 * (`StoreScreeningTestDesignRequest`). All five fields are required; the backend
 * additionally enforces `cutoff_high >= cutoff_low`.
 */
export interface ScreeningTestDesignInput {
  cutoff_low: number
  cutoff_high: number
  meaning_red: string
  meaning_yellow: string
  meaning_green: string
}

/**
 * Body for `POST /groups/{group}/screening-test-applications`
 * (`StoreScreeningTestApplicationRequest`). `application_date` is nullable; we
 * omit it when empty rather than send `null`.
 */
export interface ScreeningTestApplicationInput {
  screening_test_type_id: number
  application_date?: string
}

/**
 * Wrapper for endpoints paginated with Laravel's default page paginator.
 * Currently only the audit history uses this — every other endpoint uses the
 * unpaginated `{ data: T[] }` sleeve. When a second paginated endpoint appears,
 * this stays as the canonical shape (do not re-derive it per feature).
 */
export interface Paginated<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    total: number
  }
}
