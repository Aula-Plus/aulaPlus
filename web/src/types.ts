export type Role = "teacher" | "director" | "psychopedagogue"

/** User-facing Spanish labels for roles (the app UI is in Spanish). */
export const roleLabels: Record<Role, string> = {
  teacher: "Docente",
  director: "Director",
  psychopedagogue: "Psicopedagogo",
}

export type UserStatus = "pending" | "active" | "disabled"

/** User-facing Spanish labels for the staff lifecycle states. */
export const userStatusLabels: Record<UserStatus, string> = {
  pending: "Pendiente",
  active: "Activo",
  disabled: "Desactivado",
}

/** A staff member as shown on the director-only "Usuarios" screen. */
export interface ManagedUser {
  id: number
  name: string
  email: string
  roles: Role[]
  status: UserStatus
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

/** A level or grade from the fixed list: `key` is stable, `label` is only shown. */
export interface LevelRef {
  id: number
  key: string
  label: string
}

/** `GET /api/v1/education-levels`: each level with the grades it suggests. */
export interface EducationLevelOption extends LevelRef {
  grades: LevelRef[]
}

export interface EducationLevels {
  data: EducationLevelOption[]
  /** How many of the viewer's groups still have no level. */
  groups_without_level: number
}

/** `GET /api/v1/group-level-setting`. */
export interface GroupLevelSetting {
  psychopedagogue_can_set_group_level: boolean
  /** Whether the viewer may choose a group's level (direction, or delegated psychopedagogy). */
  can_set_level: boolean
}

export interface Group {
  id: number
  name: string
  /** Old free text, kept as it was. The level that counts is `education_level`. */
  level: string | null
  /** Chosen from the fixed list; `null` = "Sin nivel" (groups created before levels existed). */
  education_level: LevelRef | null
  /** Optional grade; only suggests a level, never forces it. */
  school_grade: LevelRef | null
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

/** A school-editable comment category (replaces the old comment "tone"). */
export interface CommentCategory {
  id: number
  name: string
  position: number
}

/**
 * Recurrence mark on a student: several comments of one category in a short
 * time (thresholds are the school's). Only psychopedagogy/direction receive it.
 * A mark to look at, not an alert.
 */
export interface CommentTrend {
  category_id: number
  category_name: string
  count: number
  authors: number
  days: number
}

export interface Comment {
  id: number
  author_id: number
  /** Present on the student comment list only. */
  author?: { id: number; name: string; role: Role | null } | null
  /** Polymorphic base name, e.g. "Student" or "Group". */
  commentable_type: string
  commentable_id: number
  content: string
  /**
   * Optional, several allowed; empty = "Sin categoría". Each carries the
   * author's signal for it (ClickUp 86e3qkwpn); `null` on observations written
   * before signals existed.
   */
  categories?: { id: number; name: string; signal?: CommentSignal | null }[]
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
  /**
   * «Elegir personas» (ClickUp 86e3qkwnv): per role, the specific people the
   * author picked. Only sent back to the author.
   */
  visible_to_users?: Partial<Record<Role, number[]>> | null
  created_at: string | null
}

/**
 * Mirror of `App\Enums\CommentSignal` — the signal an author picks for each
 * category of an observation (ClickUp 86e3qkwpn). Mandatory per category.
 */
export type CommentSignal = "improvement" | "difficulty" | "no_change"

export const commentSignalLabels: Record<CommentSignal, string> = {
  improvement: "Señal de mejora",
  difficulty: "Señal de dificultad",
  no_change: "Sin cambio observado",
}

/** Mirror of `App\Enums\AlertType`. */
export type AlertType = "performance" | "planning_attendance" | "escalated_overdue" | "difficulty_signal"

export const alertTypeLabels: Record<AlertType, string> = {
  performance: "Rendimiento",
  planning_attendance: "Planificación / asistencia",
  escalated_overdue: "Alerta escalada con plazo vencido",
  difficulty_signal: "Señal de dificultad",
}

/** Mirror of `App\Enums\AlertOutcome` — the three ways out of an alert (ClickUp 86e3jpzdp). */
export type AlertOutcome = "owned" | "handed_off" | "observing"

/** Button labels, phrased in first person as on screen 11 of the living document. */
export const alertOutcomeActionLabels: Record<AlertOutcome, string> = {
  owned: "Me ocupo yo",
  handed_off: "Se la paso a otro rol",
  observing: "La dejo en observación",
}

/** State labels once a way out was chosen. */
export const alertOutcomeLabels: Record<AlertOutcome, string> = {
  owned: "En curso",
  handed_off: "Pasada a otro rol",
  observing: "En observación",
}

export interface AlertPerson {
  id: number
  name: string | null
}

/**
 * Mirror of `App\Enums\AlertActionKind`: a way out chosen, a note by whoever
 * has the alert, or the deadline arriving with the alert still pending
 * («Llegó a su fecha sin salida»).
 */
export type AlertActionKind = "outcome" | "note" | "overdue"

/** One step of an alert's thread. */
export interface AlertActionEntry {
  id: number
  kind: AlertActionKind
  /** Only on `outcome` steps. */
  outcome: AlertOutcome | null
  /** `null` on an `overdue` step: written by the daily run, not by a person. */
  actor: AlertPerson | null
  /** Who became responsible (`outcome`) or who had it (`overdue`); `null` on a note. */
  assignee: AlertPerson | null
  due_on: string | null
  note: string | null
  created_at: string | null
}

/**
 * A follow-up linked to an alert, as the alert lists it: state and deadline
 * only; its content is read through the follow-up endpoints.
 */
export interface AlertFollowUpLink {
  id: number
  due_date: string | null
  resolved: boolean
  is_overdue: boolean
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
  /** Present on the "my alerts" list (`GET /api/v1/alerts`). */
  student_name?: string | null
  /** The subject a sustained-low-performance alert was met in (ClickUp 86e3jpzcv). */
  subject_id?: number | null
  subject_name?: string | null
  /** «Señal de dificultad» alerts: the category the signals were counted in (ClickUp 86e3qkwpn). */
  comment_category_id?: number | null
  comment_category_name?: string | null
  type: AlertType
  severity: AlertSeverity
  description: string
  /** `YYYY-MM-DD` the configured condition was met on, when known. */
  condition_met_on?: string | null
  /** Who the alert reached first; `null` on alerts created before 86e3jpzcv. */
  recipients?: AlertRecipient[] | null
  /** Legacy escalated alerts (no longer created): the alert whose deadline passed. */
  source_alert_id?: number | null
  /** The way out chosen (ClickUp 86e3jpzdp), `null` while none was. */
  outcome?: AlertOutcome | null
  /** The person responsible — «la tiene X desde el dd/mm». */
  assignee?: AlertPerson | null
  outcome_by?: AlertPerson | null
  outcome_at?: string | null
  due_on?: string | null
  is_overdue?: boolean
  /**
   * «Llegó a su fecha sin salida»: when the daily run found the current
   * deadline passed with the alert still pending. The same alert — no second
   * one is created.
   */
  overdue_at?: string | null
  actions?: AlertActionEntry[]
  /** Follow-ups created from this alert («seguimiento vinculado»). */
  follow_ups?: AlertFollowUpLink[]
  /**
   * Whether the current user has the alert now (they are the one responsible
   * once a way out was chosen): it is «a tu cargo» for them.
   */
  is_in_charge?: boolean
  /** What the current user may do, decided by `AlertPolicy`. */
  can?: { act: boolean; note?: boolean; resolve: boolean; link_follow_up?: boolean }
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
  subject_id: number
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
  subject_id: number
  subject_name: string | null
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
 * A school subject ("materia"). Mirror of `SubjectResource` (backend Sesión 1).
 * School-owned catalog managed by directors.
 */
export interface Subject {
  id: number
  name: string
  short_code: string | null
  color: string | null
  /** Whether the Aula+ team already loaded it into the curricular catalog (ClickUp 86e3dt6ag). */
  in_catalog?: boolean
  /** Curricular program PDF metadata; the file itself is reached via a temporary URL. */
  syllabus?: SubjectSyllabus | null
}

export type SyllabusTextStatus = "pending" | "ready" | "failed"

export interface SubjectSyllabus {
  name: string
  size: number | null
  uploaded_at: string | null
  /**
   * Text extraction for the AI assistant (queued after each upload): `pending`
   * until it runs, then `ready` (the AI uses it) or `failed` (scanned,
   * encrypted or unreadable PDF — stored, but the AI doesn't use it).
   */
  text_status: SyllabusTextStatus
  /** Decided by `SubjectPolicy::viewSyllabus` for the current user. */
  can_view: boolean
}

/**
 * One teacher-subject assignment row of a group (backend Sesión 2). Mirror of
 * `GroupTeacherAssignmentResource`.
 */
export interface GroupTeacherAssignment {
  teacher_id: number
  teacher_name: string
  subject_id: number
  subject_name: string | null
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
  | "calendar_event"

export const performanceMarkTypeLabels: Record<PerformanceMarkType, string> = {
  accommodation_activated: "Ajuste activado",
  accommodation_deactivated: "Ajuste desactivado",
  accommodation_instance_override: "Ajuste desactivado para esta evaluación",
  barrier_registered: "Barrera registrada",
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

/** Where a direction accommodation proposal stands (`App\Enums\AccommodationReviewStatus`). */
export type AccommodationReviewStatus = "pending" | "returned" | "confirmed" | "withdrawn" | "rejected"

export const accommodationReviewStatusLabels: Record<AccommodationReviewStatus, string> = {
  pending: "Pendiente de confirmación de Psicopedagogía",
  returned: "Devuelta para corregir",
  confirmed: "Confirmada por Psicopedagogía",
  withdrawn: "Retirada por Dirección",
  rejected: "Rechazada por Psicopedagogía",
}

/** One step of a proposal's review (`AccommodationReviewEventResource`). */
export type AccommodationReviewAction =
  | "submitted"
  | "edited"
  | "returned"
  | "resubmitted"
  | "confirmed"
  | "withdrawn"
  | "rejected"

export const accommodationReviewActionLabels: Record<AccommodationReviewAction, string> = {
  submitted: "Envió la propuesta a revisión",
  edited: "Editó la propuesta",
  returned: "La devolvió para corregir",
  resubmitted: "La reenvió a revisión",
  confirmed: "La confirmó",
  withdrawn: "La retiró",
  rejected: "La rechazó",
}

export interface AccommodationReviewEvent {
  id: number
  action: AccommodationReviewAction
  note: string | null
  /** `edited` only: the API names of the fields changed (never their content). */
  changed_fields: string[] | null
  user: StaffMember | null
  created_at: string | null
}

/** Spanish names of the accommodation fields an `edited` step may list. */
export const accommodationFieldLabels: Record<string, string> = {
  type: "Nombre del ajuste",
  description: "Descripción",
  focus_area: "Área de enfoque",
  category: "Tipo de ajuste",
  starts_on: "Vigente desde",
  subject_ids: "Materias donde aplica",
  assessment_types: "Evaluaciones",
  origin: "Origen",
  active: "Activa",
  llm_rule: "Regla para el asistente",
}

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
 * AI text summary of the group's accommodations. `published` is what teachers
 * read; `draft` (psychopedagogy only) is the latest generation awaiting review.
 * `outdated` means the accommodations changed after the text was generated.
 */
export interface AccommodationNarrativeView {
  can_generate: boolean
  /** False while the environment has no AI provider configured: generating is disabled. */
  ai_available: boolean
  published: {
    id: number
    content: string
    published_at: string | null
    published_by: string | null
    outdated: boolean
  } | null
  draft: {
    id: number
    status: "pending" | "draft" | "error"
    content: string | null
    error_message: string | null
    /** The accommodation data itself names a student: nothing was sent to the AI. */
    name_in_data?: boolean
    /** Accommodation types that name a student, with the name redacted ("…"). */
    flagged_types?: string[]
    /** A generation that never finished (reported as `error`): it can be retried. */
    stale?: boolean
    outdated: boolean
  } | null
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
  | "calendar_event"

export const groupPerformanceMarkTypeLabels: Record<GroupPerformanceMarkType, string> = {
  accommodation_activated: "Ajustes activados",
  accommodation_deactivated: "Ajustes desactivados",
  barrier_registered: "Barreras registradas",
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
  | (GroupPerformanceMarkBase & {
      type: "calendar_event"
      calendar_event_id: number
      title: string
    })

export interface GroupPerformanceTimeline {
  results: GroupPerformanceResultPoint[]
  marks: GroupPerformanceMark[]
}

/** A subject an accommodation is restricted to (`AccommodationSubjectResource`). */
export interface AccommodationSubjectRef {
  id: number
  name: string
  /**
   * Soft-deleted since it was attached. The accommodation stays scoped to it
   * (it never turns general); the edit form shows it as «(eliminada)».
   */
  deleted: boolean
}

/**
 * Where and since when an accommodation applies (ClickUp 86e3dpbwy follow-up):
 *   - `starts_on`: ISO date (`YYYY-MM-DD`) it is in force from.
 *   - `subjects`: the subjects it is restricted to; empty = every subject.
 *   - `assessment_types`: the assessment types it is restricted to; null = every type.
 */
export interface AccommodationScope {
  starts_on: string | null
  subjects: AccommodationSubjectRef[]
  assessment_types: AssessmentType[] | null
}

export interface Accommodation extends AccommodationScope {
  id: number
  student_id: number
  type: string
  /**
   * `null` for pre-existing rows created before `category` was required:
   * `AccommodationResource` serializes `$this->category?->value`, so the field
   * is nullable on read even though every new write must set it (see
   * {@see AccommodationInput}).
   */
  category: AccommodationCategory | null
  active: boolean
  /**
   * ISO date the accommodation was switched off, recorded by the server (PO,
   * ClickUp 86e3d1bwa: no user-entered end date). `null` while it is in force.
   */
  deactivated_on: string | null
  description: string | null
  focus_area: string | null
  /**
   * «Origen» (ClickUp 86e3qkwkk): free text psychopedagogy writes. Only this
   * full record (psychopedagogy and direction) carries it; never a teacher.
   */
  origin: string | null
  is_effective: boolean
  /**
   * Direction proposal circuit (ClickUp 86e3qkwkk): `null` when psychopedagogy
   * created it directly; otherwise where the proposal stands. Only `null` or
   * `confirmed` ever reach a teacher or count as in force.
   */
  review_status: AccommodationReviewStatus | null
  proposed_by_id: number | null
  proposed_by_name: string | null
  /** Who confirmed, withdrew or rejected the proposal, and when. */
  reviewed_by_id: number | null
  reviewed_by_name: string | null
  reviewed_at: string | null
  /** Comment/reason of the step that left it returned, withdrawn or rejected. */
  review_note: string | null
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
  /** Psychopedagogy only on edit; direction never switches one on or off. */
  active?: boolean
  /**
   * «Vigente desde», ISO date (`YYYY-MM-DD`). The form sends it (today
   * by default); the backend also defaults it to today when absent.
   */
  starts_on?: string
  /** Empty = a general accommodation (every subject). */
  subject_ids: number[]
  /** Empty = every assessment type (stored as null). */
  assessment_types: AssessmentType[]
  /** «Origen»: psychopedagogy only (the server rejects it from direction). */
  origin?: string | null
  /**
   * Direction proposals only (ClickUp 86e3qkwkk): the review deadline and who
   * reviews it — the whole psychopedagogy role or the people picked.
   */
  review_due_date?: string
  review_recipients?: "role" | "people"
  reviewer_ids?: number[]
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
/**
 * One subject's aggregated academic stats for a student (backend Sesión 4 —
 * `StudentTrackingController::subjectAggregates`). `average` is the mean of the
 * student's scores in that subject; `assessment_count` how many contributed.
 */
export interface SubjectStat {
  subject_id: number
  subject_name: string
  average: number
  assessment_count: number
}

/** Team-facing summary of the technical report (no diagnosis); confirmed by psychopedagogy. */
export interface TeamSummary {
  strengths: string
  difficulties: string
  adjustments: string
  confirmed_at: string
}

/** A short label for a current accommodation/barrier — all a teacher gets (no clinical detail). */
export interface SupportChip {
  id: number
  label: string
}

/**
 * An accommodation chip: the name plus since when and where it applies, so a
 * teacher knows whether it applies in their class. The server only sends a
 * teacher the general ones and those scoped to a subject they teach the
 * student — never origin, validator or review date.
 */
export interface AccommodationChip extends SupportChip, AccommodationScope {}

export interface StudentTracking {
  student: Student
  /** `null` until psychopedagogy writes and confirms it. */
  team_summary: TeamSummary | null
  support_chips: { accommodations: AccommodationChip[]; barriers: SupportChip[] }
  recent_assessments: AssessmentSummary[]
  /** Mean of every scored assessment, across subjects; null when there are none. */
  overall_average: number | null
  /** Per-subject averages (backend Sesión 4); empty when no scores exist. */
  by_subject: SubjectStat[]
  /** Scored instances the viewer may see (already filtered by the server), newest first. */
  grades: StudentGrade[]
  /**
   * True when `grades` was cut to the most recent ones (server limit: 100);
   * `by_subject`/`overall_average` always cover every visible grade.
   */
  grades_truncated: boolean
  grades_view: GradesView
  accommodations?: Accommodation[]
  accommodations_count: number
  barriers?: Barrier[]
  barriers_count: number
  recent_comments: Comment[]
  /** Absent for a teacher: only psychopedagogy/direction get the recurrence mark. */
  comment_trends?: CommentTrend[]
  /**
   * The viewer's OWN "solo quien escribe" notes on this student reached the
   * school's trend threshold. Computed per viewer server-side, so it is only
   * ever `true` for that author. Optional for older cached responses.
   */
  private_notes_signal?: boolean
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

/** "Ayuda y sugerencias": the two paths of the support form. */
export type SupportMessageKind = "issue" | "improvement"

/** Body for `POST /support-messages`. Role and school are resolved server-side. */
export interface SupportMessageInput {
  kind: SupportMessageKind
  message: string
  /** Route path of the originating screen, e.g. "/alumnos/3/seguimiento". */
  screen?: string | null
  /** Generic name of that screen for the email, e.g. "Perfil del alumno". Never PII. */
  screen_name?: string | null
}

/** One weekly bucket of the adoption dashboard time series (Monday-start). */
export interface WeeklySeriesPoint {
  week_start: string
  count: number
}

/**
 * Director-only pilot-adoption dashboard
 * (`GET /api/v1/schools/{id}/adoption-dashboard`). Rates are percentages 0–100.
 * The login indicators were removed (ClickUp 86e3qkxtc): `null` means the
 * school has no record of the step yet («dato no disponible»), never 0.
 */
export interface AdoptionDashboard {
  teacher_started_rate_30d: number | null
  teacher_planning_rate_30d: number
  data_since: JourneyDataSince
  weekly_content_series: WeeklySeriesPoint[]
  weekly_content_by_type: WeeklyContentByTypePoint[]
}

/** One weekly bucket of content created, split by type (stacked chart). */
export interface WeeklyContentByTypePoint {
  week_start: string
  annual_plans: number
  class_sessions: number
  assessments: number
}

/** Per-type counts: programs, classes, assessments (no projects yet). */
export interface ContentCounts<T = number> {
  annual_plans: T
  class_sessions: T
  assessments: T
}

/**
 * One row of the director-only "Por docente" tab. Plain counts for the
 * current month — deliberately no totals, scores or login timestamps.
 * `month_counts` are confirmed creations; `month_attempts` are AI generation
 * attempts (one per journey), `null` while there is no record of them.
 */
export interface AdoptionTeacherRow {
  id: number
  name: string
  subjects: string[]
  month_counts: ContentCounts
  month_attempts: ContentCounts<number | null>
}

/** Coarse last-login range shown by "Ver detalles de uso" (never day/time). */
export type LastLoginRange = "this_week" | "within_10_days" | "over_10_days" | "never"

/** A recorded step of a creation journey (backend `App\Enums\JourneyStep`). */
export type JourneyStep =
  | "form_opened"
  | "filling_started"
  | "generation_requested"
  | "regeneration_requested"
  | "generation_succeeded"
  | "generation_failed"
  | "creation_confirmed"
  | "draft_discarded"

/** The kind of content a journey is about (no projects until that entity exists). */
export type JourneyContentType = "annual_plan" | "unit" | "class_session" | "assessment"

/** Since when the school has data for each step (ISO), or `null` if never. */
export type JourneyDataSince = Record<JourneyStep, string | null>

/** Fixed failure categories of a generation (never the technical error). */
export type JourneyFailureReason =
  | "not_configured"
  | "provider_unreachable"
  | "provider_error"
  | "invalid_response"

export interface JourneyStepRecord {
  step: JourneyStep
  /** ISO date-time of the step. */
  at: string
  failure_reason?: JourneyFailureReason
}

/**
 * One creation journey behind a teacher's counts
 * (`GET …/adoption-dashboard/teachers/{id}/journeys`). `flow` is `ai`
 * (assistant), `manual` (form + Guardar) or `null` when no earlier step was
 * recorded. `content` is present only if Dirección may open it.
 */
export interface CreationJourney {
  id: string
  content_type: JourneyContentType
  flow: "ai" | "manual" | null
  started_at: string
  confirmed: boolean
  last_step: JourneyStepRecord
  steps: JourneyStepRecord[]
  title: string | null
  group_name: string | null
  subject_name: string | null
  content: { type: JourneyContentType; id: number; group_id: number | null } | null
}

/**
 * Shown wherever a step or figure has no record. Never «no intentó»: missing
 * data is not evidence the teacher didn't do something.
 */
export const NOT_AVAILABLE_LABEL = "dato no disponible"

export const journeyStepLabels: Record<JourneyStep, string> = {
  form_opened: "Abrió el formulario",
  filling_started: "Empezó a completar",
  generation_requested: "Pidió generar con el asistente",
  regeneration_requested: "Pidió un cambio o reintentó",
  generation_succeeded: "Recibió la propuesta (pendiente de confirmar)",
  generation_failed: "La generación falló",
  creation_confirmed: "Guardó / confirmó la creación",
  draft_discarded: "Descartó el borrador",
}

export const journeyContentTypeLabels: Record<JourneyContentType, string> = {
  annual_plan: "Programa",
  unit: "Unidad",
  class_session: "Clase",
  assessment: "Evaluación",
}

export const journeyFailureReasonLabels: Record<JourneyFailureReason, string> = {
  not_configured: "El asistente no estaba disponible en ese momento.",
  provider_unreachable: "No se pudo conectar con el asistente.",
  provider_error: "El asistente tuvo un problema y no pudo responder.",
  invalid_response: "El asistente respondió en un formato que no se pudo usar.",
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
  /**
   * Clinical detail: only sent to a viewer with clinical access
   * (psychopedagogy/direction); absent for a teacher. A teacher also only gets
   * the linked accommodations they may see (general or of a subject they teach).
   */
  description?: string | null
  focus_area?: string | null
  proposed_by_id: number
  validated: boolean
  validated_by_id: number | null
}

/** Mirror of `App\Enums\AuditAction`. */
export type AuditAction = "created" | "updated" | "deleted"

/** How the change history names each action ("altas, modificaciones o bajas"). */
export const auditActionLabels: Record<AuditAction, string> = {
  created: "Alta",
  updated: "Modificación",
  deleted: "Baja",
}

/** Mirror of `App\Enums\AuditOrigin`. `system` entries have `user_id === null`. */
export type AuditOrigin = "user" | "system"

export const auditOriginLabels: Record<AuditOrigin, string> = {
  user: "Usuario",
  system: "Sistema",
}

/** Mirror of `App\Enums\StudentFileSection`: the parts of a student's file. */
export type StudentFileSection =
  | "student_data"
  | "summary"
  | "learning_profile"
  | "documents"
  | "accommodations"
  | "barriers"
  | "observations"
  | "reports"
  | "follow_ups"
  | "alerts"

export const studentFileSectionLabels: Record<StudentFileSection, string> = {
  student_data: "Datos del alumno",
  summary: "Resumen para el equipo",
  learning_profile: "Perfil de aprendizaje",
  documents: "Documentos",
  accommodations: "Ajustes",
  barriers: "Barreras",
  observations: "Observaciones",
  reports: "Informes",
  follow_ups: "Seguimientos programados",
  alerts: "Alertas",
}

/**
 * One changed field of a history entry. An addition has `before: null`, a
 * removal `after: null`. `redacted` fields are reported as changed but their
 * value is never sent (e.g. the private path of a report file).
 */
export interface StudentHistoryChange {
  field: string
  before?: unknown
  after?: unknown
  redacted?: boolean
}

/**
 * One entry of a student's change history (`GET /api/v1/students/{id}/history`).
 * Mirror of `StudentHistoryEntryResource`. The server only sends entries the
 * viewer may read under the original record's own permissions.
 */
export interface StudentHistoryEntry {
  id: number
  created_at: string | null
  action: AuditAction
  section: StudentFileSection | null
  origin: AuditOrigin
  /** `null` for system changes (no person behind them). */
  actor: { id: number; name: string; role: Role | null } | null
  element: { type: string; id: number; label: string | null }
  changes: StudentHistoryChange[]
}

/** A person who appears in the history — options of the "Persona" filter. */
export interface StudentHistoryActor {
  id: number
  name: string
  role: Role | null
}

export interface StudentHistoryPage extends Paginated<StudentHistoryEntry> {
  actors: StudentHistoryActor[]
}

export interface StudentHistoryFilters {
  user_id?: number
  section?: StudentFileSection
  action?: AuditAction
  /** ISO-8601 instant (start of the viewer's local day). */
  from?: string
  /** ISO-8601 instant (end of the viewer's local day). */
  to?: string
}

/** A colleague as exposed by `StaffMemberResource`: id, name and role only. */
export interface StaffMember {
  id: number
  name: string
  role: Role | null
}

/**
 * An extract of an observation that psychopedagogy shared («Compartir con»,
 * `CommentShareResource`). A recipient gets only the extract text: the link to
 * the original (`comment_id`) and the recipient list reach its author only.
 */
export interface CommentShare {
  id: number
  student_id: number
  author_id: number
  content: string
  comment_id?: number
  recipients?: StaffMember[]
  created_at: string | null
}

/**
 * One unread notice: a follow-up assigned to you, an alert of yours that
 * reached its deadline without a way out, an accommodation proposal returned
 * to you for corrections, an extract psychopedagogy shared with you, or a
 * screening design change. Ids, names and dates only.
 */
export interface AppNotification {
  id: string
  data:
    | FollowUpAssignedNotice
    | AlertOverdueNotice
    | AccommodationProposalReturnedNotice
    | CommentSharedNotice
    | ScreeningDesignNotice
  created_at: string | null
}

export interface FollowUpAssignedNotice {
  type: "follow_up_assigned"
  follow_up_id: number
  student_id: number
  due_date: string
  /** Made general responsible of the follow-up or responsible of one stage. */
  assigned_as?: "general_responsible" | "stage_responsible"
  stage_id?: number | null
}

/** Psychopedagogy returned my accommodation proposal for corrections (ClickUp 86e3qkwkk). */
export interface AccommodationProposalReturnedNotice {
  type: "accommodation_proposal_returned"
  accommodation_id: number
  student_id: number
}

/**
 * «Psicopedagogía te compartió un extracto» (ClickUp 86e3qkwnv): the stored
 * payload holds ids only; the server adds `student_name` at read time when the
 * caller may see the student.
 */
export interface CommentSharedNotice {
  type: "comment_shared"
  comment_share_id: number
  student_id: number
  student_name?: string | null
}

/** An alert you have reached its deadline without a way out («Llegó a su fecha sin salida»). */
export interface AlertOverdueNotice {
  type: "alert_overdue"
  alert_id: number
  student_id: number
  due_date: string | null
}

/** A screening's design changed (to Dirección) or was reverted (to its author). */
export interface ScreeningDesignNotice {
  type: "screening_design_changed" | "screening_design_reverted"
  screening_test_type_id: number
  screening_test_type_name: string | null
  design_id: number
}

/**
 * A scheduled follow-up on a student (`ScheduledFollowUpResource`, backend
 * Sesión 7 — docs/prompts/17-seguimiento-programado.md). The concrete face of
 * the rule "nothing expires on its own, someone scheduled it".
 */
export interface ScheduledFollowUp {
  id: number
  student_id: number
  /** Only on the caller's own pending list (`GET /scheduled-follow-ups/mine`). */
  student?: { id: number; full_name: string }
  /** Required for new follow-ups; older ones were backfilled from the description. */
  title?: string | null
  /** The alert it was created from («seguimiento vinculado»), if any. */
  alert_id?: number | null
  /**
   * Set only on psychopedagogy's review task for a direction accommodation
   * proposal (ClickUp 86e3qkwkk): its title is rendered from this, its
   * description is empty, and it closes with the proposal (never by hand).
   */
  accommodation_id?: number | null
  description: string
  /** General deadline: the latest due date among the stages (server-computed). */
  due_date: string
  created_by_id: number
  /** Active general responsibles (one or more). */
  general_responsibles?: StaffMember[]
  /** Responsible person — the creator unless they picked someone else. */
  assigned_to_id?: number | null
  assigned_to?: StaffMember | null
  /** People, besides the responsible one, who also follow it. */
  shared_with?: StaffMember[]
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

// ── Follow-up stages, people and history (ClickUp 86e3qkwna) ─────────────

export type FollowUpStageStatus = "pending" | "done" | "split"

export const followUpStageStatusLabels: Record<FollowUpStageStatus, string> = {
  pending: "Pendiente",
  done: "Cerrada",
  split: "Subdividida",
}

export interface FollowUpStageNote {
  id: number
  body: string
  author: StaffMember | null
  created_at: string | null
}

/** One stage ("etapa"). `can` flags only drive the UI; the backend re-checks. */
export interface FollowUpStage {
  id: number
  parent_stage_id: number | null
  description: string
  responsible: StaffMember | null
  due_date: string
  status: FollowUpStageStatus
  conclusion: string | null
  closed_by: StaffMember | null
  closed_at: string | null
  created_by_id: number | null
  created_at: string | null
  notes: FollowUpStageNote[]
  can: { update: boolean; split: boolean; close: boolean; add_note: boolean }
}

export type FollowUpMemberRole = "general_responsible" | "participant"

export interface FollowUpMember {
  user: StaffMember | null
  role: FollowUpMemberRole
  active: boolean
  has_pending_stage: boolean
  withdrawn_at: string | null
  withdrawal_reason: string | null
  removed_at: string | null
  removed_by_id: number | null
  removal_reason: string | null
}

export interface FollowUpEvent {
  id: number
  type: string
  actor: StaffMember | null
  subject_user: StaffMember | null
  stage_id: number | null
  data: Record<string, unknown> | null
  note: string | null
  created_at: string | null
}

/** Full record of a follow-up as the caller sees it (`GET /scheduled-follow-ups/{id}`). */
export interface ScheduledFollowUpDetail extends ScheduledFollowUp {
  participants: StaffMember[]
  members: FollowUpMember[]
  stages: FollowUpStage[]
  events: FollowUpEvent[]
  viewer: {
    user_id: number
    is_member: boolean
    active: boolean
    role: FollowUpMemberRole | null
    left_at: string | null
    hidden: boolean
    is_general_responsible: boolean
  }
  can: {
    add_stage: boolean
    add_participant: boolean
    manage_general_responsibles: boolean
    withdraw: boolean
    withdraw_requires_successor: boolean
    resolve: boolean
    hide: boolean
  }
}

// ── Screening tests / pruebas de sondeo (Sesión 17) ─────────────────────────
// Mirrors the backend Session 13 API (docs/prompts/11-pruebas-de-sondeo.md;
// frontend docs/prompts/12-frontend-pruebas-de-sondeo.md) under /api/v1. This
// is a school-wide, psychopedagogy-led module; teachers never see the catalog
// or the designs. A saved design rules at once (screen 08) and Dirección can
// revert it; results are anonymised BY CODE and never carry a student name
// (that mapping lives only in the roster endpoint).

/**
 * The colour of a screening band (mirror of `App\Enums\ScreeningColor`). The
 * school picks one per band; the band's name and meaning are its own text.
 * English identifiers in code; the Spanish labels below are the only
 * user-facing text, per CLAUDE.md.
 */
export type ScreeningColor = "red" | "orange" | "yellow" | "green" | "blue"

export const screeningColors: ScreeningColor[] = ["red", "orange", "yellow", "green", "blue"]

export const screeningColorLabels: Record<ScreeningColor, string> = {
  red: "Rojo",
  orange: "Naranja",
  yellow: "Amarillo",
  green: "Verde",
  blue: "Azul",
}

/** Levels a screening's population can cover (mirror of `App\Enums\SchoolLevel`). */
export type SchoolLevel = "primary" | "lower_secondary" | "upper_secondary"

export const schoolLevels: SchoolLevel[] = ["primary", "lower_secondary", "upper_secondary"]

export const schoolLevelLabels: Record<SchoolLevel, string> = {
  primary: "Primaria",
  lower_secondary: "Ciclo Básico",
  upper_secondary: "Bachillerato",
}

/** How often a screening is taken (mirror of `App\Enums\ScreeningFrequency`). */
export type ScreeningFrequency = "yearly" | "twice_yearly" | "every_few_years" | "on_demand"

export const screeningFrequencies: ScreeningFrequency[] = [
  "yearly",
  "twice_yearly",
  "every_few_years",
  "on_demand",
]

export const screeningFrequencyLabels: Record<ScreeningFrequency, string> = {
  yearly: "Una vez por año",
  twice_yearly: "Dos veces por año",
  every_few_years: "Cada varios años",
  on_demand: "Cuando el colegio quiera",
}

/**
 * One band of a design: name, colour, the score it goes up to (inclusive;
 * `null` only on an open-ended last band) and its mandatory meaning, written
 * by the school (never by the AI).
 */
export interface ScreeningBand {
  name: string
  color: ScreeningColor
  max: number | null
  meaning: string | null
}

/**
 * One version of a screening's design (mirror of `ScreeningTestDesignResource`).
 * A saved design rules right away; Dirección can revert the one in force
 * (`reverted_at`) and the previous one rules again.
 */
export interface ScreeningTestDesign {
  id: number
  screening_test_type_id: number
  bands: ScreeningBand[]
  reason: string | null
  approved: boolean | null
  created_by_id: number | null
  created_by_name?: string | null
  reverted_at: string | null
  reverted_by_id: number | null
  reverted_by_name?: string | null
  created_at?: string | null
  updated_at?: string | null
}

/**
 * A screening the school takes (mirror of `ScreeningTestTypeResource`).
 * `current_design` is the design in force and `previous_design` the one that
 * ruled right before it ("Volver atrás" goes back to it).
 */
export interface ScreeningTestType {
  id: number
  name: string
  area: string | null
  levels: SchoolLevel[]
  frequency: ScreeningFrequency | null
  frequency_years: number | null
  teacher_visible: boolean
  active: boolean
  created_by_id: number | null
  current_design: ScreeningTestDesign | null
  previous_design: ScreeningTestDesign | null
  created_at?: string | null
  updated_at?: string | null
}

/** One band's count in the impact preview (counts only, never names). */
export interface ScreeningImpactBand {
  name: string
  color: ScreeningColor
  max: number | null
  count: number
}

/** `POST /screening-test-types/{type}/impact`. */
export interface ScreeningImpact {
  students: number
  /** Groups of the school without a level: they count in no screening's impact view. */
  groups_without_level: number
  current: ScreeningImpactBand[] | null
  proposed: ScreeningImpactBand[]
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
  /**
   * Whether it counts in its screening's impact view, from the level and
   * population stored when it was applied (or last recalculated).
   */
  counts_in_impact?: boolean
  /** Last «Recalcular con el nivel actual»: when and by whom (null if never). */
  recalculated_at?: string | null
  recalculated_by_name?: string | null
  created_at?: string | null
  updated_at?: string | null
}

/**
 * One result of an application, the ANONYMISED by-code view (mirror of
 * `ScreeningTestResultResource`). It deliberately never carries `student_id` or
 * a name — the code→student mapping is exposed solely by the roster endpoint.
 * `band`/`color` are the reading when the score was loaded (design version
 * `screening_test_design_id`); `current_band`/`current_color` are the reading
 * with the design in force, and `band_changed` says whether they differ.
 */
export interface ScreeningTestResult {
  id: number
  screening_test_application_id: number
  code: string
  score: number | string | null
  screening_test_design_id: number | null
  band: string | null
  color: ScreeningColor | null
  current_design_id: number | null
  current_band: string | null
  current_color: ScreeningColor | null
  band_changed: boolean
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

/**
 * Body for `POST /screening-test-types` and `PATCH /screening-test-types/{id}`.
 * `frequency_years` is required only for `every_few_years`.
 */
export interface ScreeningTestTypeInput {
  name?: string
  area?: string | null
  active?: boolean
  levels?: SchoolLevel[]
  frequency?: ScreeningFrequency | null
  frequency_years?: number | null
  teacher_visible?: boolean
}

/**
 * Body for `POST /screening-test-types/{type}/designs`: 2 to 5 bands with a
 * strictly increasing `max` (all but the last required) and a mandatory
 * meaning; `reason` is the optional "Motivo".
 */
export interface ScreeningTestDesignInput {
  bands: ScreeningBand[]
  reason?: string | null
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
 * Currently only the change history uses this — every other endpoint uses the
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

/**
 * An accommodation in force for one grade (name only — never clinical detail).
 */
export interface GradeAccommodation {
  id: number
  label: string
}

export interface StudentGrade {
  id: number
  assessment_id: number
  subject_id: number
  subject_name: string
  type: AssessmentType
  administered_at: string | null
  score: number | null
  /**
   * The accommodations that were in force on the instance's date for its
   * subject and type, minus any deactivated for that instance — only the ones
   * the viewer may see (server-side rule; PO ClickUp 86e3d1bwa).
   */
  accommodations: GradeAccommodation[]
}

export type GradesVisibilityMode = "own_subject" | "all_live" | "all_periodic"

/** Why a teacher sees fewer grades than exist (never any grade data). */
export interface GradesView {
  mode: GradesVisibilityMode
  restricted: boolean
  own_subject_ids: number[]
  /** Periodic mode: other subjects show grades loaded before this day. */
  cutoff_at: string | null
}

export interface GradesVisibilitySettings {
  mode: GradesVisibilityMode
  cutoff_months: number | null
  cutoff_anchor: string | null
  last_cutoff_at: string | null
}

// ── Alert settings (ClickUp 86e3jpzcv) ──────────────────────────────────────

/** Mirror of `App\Enums\AlertCondition`. */
export type AlertCondition = "consecutive_below" | "average_below" | "difficulty_signals"

/** Mirror of `App\Enums\AlertRecipient` — who an alert type reaches first. */
export type AlertRecipient = "teacher" | "psychopedagogue" | "director"

export const alertRecipientLabels: Record<AlertRecipient, string> = {
  teacher: "Docente de la materia",
  psychopedagogue: "Psicopedagogía",
  director: "Dirección",
}

/**
 * A school-configured alert condition (`AlertRuleResource`): sustained low
 * performance, or «Señal de dificultad» (ClickUp 86e3qkwpn), which uses only
 * `signal_count` and `period_days`.
 */
export interface AlertRule {
  id: number
  condition: AlertCondition
  /** `null` on a «Señal de dificultad» condition. */
  threshold: number | null
  consecutive_count: number | null
  /** «Señal de dificultad»: how many signals in one category. */
  signal_count?: number | null
  period_days: number | null
  /** `null` = applies to every subject. */
  subject_id: number | null
  active: boolean
  created_at: string | null
}

export interface AlertRoutingEntry {
  type: AlertType
  recipients: AlertRecipient[]
  /** The recipients the school may choose; teachers never get «Señal de dificultad». */
  allowed_recipients?: AlertRecipient[]
  /** `true` while the school hasn't changed the product default. */
  is_default: boolean
}

export interface AlertSettings {
  rules: AlertRule[]
  routing: AlertRoutingEntry[]
}

/**
 * Student reports uploaded by psychopedagogy and read by the AI
 * (`App\Enums\ReportDocumentStatus`, ClickUp 86e3jujgx).
 */
export type ReportStatus =
  | "processing"
  | "extraction_failed"
  | "ready"
  | "awaiting_review"
  | "interpreting"
  | "proposed"
  | "error"

export type ReportVisibility = "clinical" | "teachers"

export const reportVisibilityLabels: Record<ReportVisibility, string> = {
  clinical: "Psicopedagogía y Dirección",
  teachers: "Todos los que ven al alumno",
}

/** Shown next to the «teachers» option: text only, it does not block or detect content. */
export const reportTeachersVisibilityWarning =
  "Elegí esta opción solo si el archivo no tiene información clínica"

export const reportStatusLabels: Record<ReportStatus, string> = {
  processing: "Procesando",
  extraction_failed: "No se pudo leer el texto",
  ready: "Texto leído",
  awaiting_review: "Falta revisar el texto anonimizado",
  interpreting: "La IA está leyendo",
  proposed: "Propuestas para revisar",
  error: "La lectura con IA falló",
}

/** Teachers and direction only receive the first six fields; the rest is psychopedagogy's. */
export interface ReportDocument {
  id: number
  student_id: number
  name: string
  format: "pdf" | "docx"
  size: number
  visibility: ReportVisibility
  uploaded_at: string
  status?: ReportStatus
  use_ai?: boolean
  send_confirmed_at?: string | null
  error_message?: string | null
}

export type ProposalStatus = "pending" | "approved" | "modified" | "rejected"
export type ProposalBasis = "explicit" | "inferred" | "manual"

export interface AccommodationProposal {
  id: number
  report_document_id: number
  type: string
  category: AccommodationCategory
  description: string
  focus_area: string | null
  basis: ProposalBasis
  source_page: number | null
  source_fragment: string | null
  /** Subjects the report points to (empty = every subject). */
  subject_ids: number[]
  /** Whether the subjects are stated in the report or inferred; null when none were proposed. */
  subjects_basis: "explicit" | "inferred" | null
  status: ProposalStatus
  accommodation_id: number | null
  reviewed_at: string | null
}

export interface ReportDocumentDetail extends ReportDocument {
  anonymized_pages: string[] | null
  /** Whether the AI may read this report right now (all three switches on). */
  ai_available: boolean
  proposals: AccommodationProposal[]
}

export interface AiReportSetting {
  /** The school's own switch. */
  enabled: boolean
  /** False when Aula+ has the feature off for every school or the provider is not configured. */
  available: boolean
}
