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
// institucional.md), verified against the actual Resources — the aggregator/
// tracking endpoints under /api/v1. As with `Student.learning_profile`,
// clinical fields (accommodations, barriers, alerts) are ABSENT from the JSON
// (not null) for a viewer who lacks view-clinical-profile, so their detail
// arrays are optional (`?`) here while the counts are always present.

export type CommentTone = "positive" | "neutral" | "concerning"

export const commentToneLabels: Record<CommentTone, string> = {
  positive: "Positivo",
  neutral: "Neutral",
  concerning: "Preocupante",
}

export interface Comment {
  id: number
  author_id: number
  commentable_type: "Student" | "Group"
  commentable_id: number
  content: string
  tone: CommentTone | null
  visible_to: Role[] | null // null = visible para todos los roles
  created_at: string
}

export type AlertType = "performance" | "behavior" | "planning_attendance"
export type AlertSeverity = "low" | "medium" | "high"

export const alertTypeLabels: Record<AlertType, string> = {
  performance: "Rendimiento",
  behavior: "Comportamiento",
  planning_attendance: "Asistencia",
}

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
  created_at: string
}

export interface AssessmentSummary {
  id: number
  group_id: number
  type: string
  variant_number: number
  created_at: string
}

export interface Accommodation {
  id: number
  student_id: number
  type: string
  active: boolean
  description: string
  focus_area: string | null
  requires_external_approval: boolean
  approved: boolean | null
  is_effective: boolean
  created_by_id: number
  created_at: string
  updated_at: string
}

export interface Barrier {
  id: number
  description: string
  coping_strategy: string | null
  active: boolean
}

export interface StudentTracking {
  student: Student
  recent_assessments: AssessmentSummary[]
  accommodations?: Accommodation[] // ausente si el viewer no pasa view-clinical-profile
  accommodations_count: number // siempre presente, para todos los roles
  barriers?: Barrier[] // ídem
  barriers_count: number
  recent_comments: Comment[] // ya filtrados por visible_to en el backend
  alerts?: Alert[] // ídem
  open_alerts_count: number
}

export interface GroupTrackingStudentSummary {
  id: number
  full_name: string
  open_alerts_count: number
  has_active_accommodations: boolean
}

export interface GroupTracking {
  group: Group
  students: GroupTrackingStudentSummary[]
  trend: {
    period_days: number
    assessments_count: number
    comments_count: number
  }
}

export interface AdoptionDashboard {
  teacher_login_rate_30d: number
  teacher_planning_rate_30d: number
  weekly_login_series: { week_start: string; count: number }[]
  weekly_content_series: { week_start: string; count: number }[]
}
