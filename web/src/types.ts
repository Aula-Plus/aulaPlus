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
  approved: boolean
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
