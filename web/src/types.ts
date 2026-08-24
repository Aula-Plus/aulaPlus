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

export interface Accommodation {
  id: number
  student_id: number
  type: string
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
    comments_count: number
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
