import { api } from "@/lib/api"
import type {
  Accommodation,
  AccommodationInput,
  Alert,
  AuditLogEntry,
  BarrierAccommodationLink,
  Comment,
  CommentTone,
  GroupAccommodationSummaryEntry,
  GroupTracking,
  Paginated,
  Role,
  StudentTracking,
} from "@/types"

/**
 * Data layer for the institutional tracking feature (Sesión 8), talking to the
 * versioned backend endpoints under `/api/v1` (docs/prompts/04-seguimiento-
 * institucional.md). Every response is wrapped in `{ data: ... }` by the
 * Laravel Resources, matching the convention already used by studentsApi /
 * groupsApi. The CSRF cookie is established once at login (see authApi), so —
 * like those modules — the mutations here do not fetch it again.
 */

export interface CommentInput {
  content: string
  tone?: CommentTone | null
  /**
   * Roles allowed to see the comment.
   *
   * IMPORTANT (docs/prompts/08 §3): when the author selects no roles, this
   * field must be OMITTED or `null` — never `[]`. The backend treats an empty
   * array as "visible to nobody", which would hide the comment even from its
   * own author. Callers build the payload conditionally (see CommentsPanel);
   * `undefined` here is dropped from the JSON by axios, so omission is the
   * default, safe behaviour.
   */
  visible_to?: Role[] | null
  /**
   * The fourth, strictest scope (docs/prompts/19-comentarios-alcance.md §1):
   * when `true`, the comment is visible only to its author. Mutually exclusive
   * with `visible_to` — CommentsPanel never builds a payload carrying both (the
   * backend forces `visible_to` to null when this is true, but the client must
   * not send them as if independent).
   */
  author_only?: boolean
}

export async function fetchStudentTracking(studentId: number): Promise<StudentTracking> {
  const { data } = await api.get<{ data: StudentTracking }>(
    `/api/v1/students/${studentId}/tracking`,
  )
  return data.data
}

export async function fetchGroupTracking(groupId: number): Promise<GroupTracking> {
  const { data } = await api.get<{ data: GroupTracking }>(`/api/v1/groups/${groupId}/tracking`)
  return data.data
}

/**
 * Perfil de grupo — active accommodations aggregated by type (backend Sesión 11,
 * docs/prompts/27-frontend-perfil-de-grupo.md §3). The controller returns a
 * plain JSON array (no `{ data }` envelope, unlike the Resource-wrapped
 * endpoints above), so there is no `.data.data` unwrap here. Safe for any role
 * that can see the group — it never names a student, so no clinical gate.
 */
export async function fetchGroupAccommodationsSummary(
  groupId: number,
): Promise<GroupAccommodationSummaryEntry[]> {
  const { data } = await api.get<GroupAccommodationSummaryEntry[]>(
    `/api/v1/groups/${groupId}/accommodations-summary`,
  )
  return data
}

export async function fetchStudentComments(studentId: number): Promise<Comment[]> {
  const { data } = await api.get<{ data: Comment[] }>(`/api/v1/students/${studentId}/comments`)
  return data.data
}

export async function createStudentComment(
  studentId: number,
  input: CommentInput,
): Promise<Comment> {
  const { data } = await api.post<{ data: Comment }>(
    `/api/v1/students/${studentId}/comments`,
    input,
  )
  return data.data
}

export async function fetchGroupComments(groupId: number): Promise<Comment[]> {
  const { data } = await api.get<{ data: Comment[] }>(`/api/v1/groups/${groupId}/comments`)
  return data.data
}

export async function createGroupComment(groupId: number, input: CommentInput): Promise<Comment> {
  const { data } = await api.post<{ data: Comment }>(`/api/v1/groups/${groupId}/comments`, input)
  return data.data
}

export async function fetchStudentAlerts(studentId: number): Promise<Alert[]> {
  const { data } = await api.get<{ data: Alert[] }>(`/api/v1/students/${studentId}/alerts`)
  return data.data
}

export async function fetchGroupAlerts(groupId: number): Promise<Alert[]> {
  const { data } = await api.get<{ data: Alert[] }>(`/api/v1/groups/${groupId}/alerts`)
  return data.data
}

export async function resolveAlert(alertId: number): Promise<Alert> {
  const { data } = await api.post<{ data: Alert }>(`/api/v1/alerts/${alertId}/resolve`)
  return data.data
}

// ── Accommodation approval (Sesión 9) ─────────────────────────────────────
// Both endpoints return the updated AccommodationResource wrapped in `{ data }`.
// The 422 business-state precondition (docs/prompts/03 §3) is checked by the
// server; callers should only invoke these when the UI shows the buttons.

export async function approveAccommodation(accommodationId: number): Promise<Accommodation> {
  const { data } = await api.post<{ data: Accommodation }>(
    `/api/v1/accommodations/${accommodationId}/approve`,
  )
  return data.data
}

export async function rejectAccommodation(accommodationId: number): Promise<Accommodation> {
  const { data } = await api.post<{ data: Accommodation }>(
    `/api/v1/accommodations/${accommodationId}/reject`,
  )
  return data.data
}

// ── Accommodation create/edit + per-instance deactivation (Sesión 12) ─────
// docs/prompts/24-frontend-ajustes-categoria-instancia.md §1. Both write
// endpoints return the AccommodationResource wrapped in `{ data }`. `category`
// is required on create AND edit (the backend UpdateAccommodationRequest keeps
// it required), so `updateAccommodation` sends the full body, not a partial.

export async function createAccommodation(
  studentId: number,
  input: AccommodationInput,
): Promise<Accommodation> {
  const { data } = await api.post<{ data: Accommodation }>(
    `/api/v1/students/${studentId}/accommodations`,
    input,
  )
  return data.data
}

export async function updateAccommodation(
  accommodationId: number,
  input: AccommodationInput,
): Promise<Accommodation> {
  const { data } = await api.patch<{ data: Accommodation }>(
    `/api/v1/accommodations/${accommodationId}`,
    input,
  )
  return data.data
}

/**
 * Deactivate an accommodation for one assessment instance. The backend only
 * allows this to the teacher who owns the chosen assessment and answers 403
 * otherwise (docs/prompts/24 §5) — the SPA can't check ownership beforehand, so
 * the caller handles that error. Nothing in the SPA reads the created override
 * back (its only reader is the Sesión 14 timeline, which brings its own type),
 * so this returns void rather than speculating a shape into `types.ts`.
 */
export async function deactivateAccommodationForAssessment(
  accommodationId: number,
  input: { assessment_id: number; reason: string },
): Promise<void> {
  await api.post(`/api/v1/accommodations/${accommodationId}/instance-overrides`, input)
}

// ── Barrier ↔ Accommodation link (Sesión 9) ──────────────────────────────

export async function fetchBarrierAccommodations(
  barrierId: number,
): Promise<BarrierAccommodationLink[]> {
  const { data } = await api.get<{ data: BarrierAccommodationLink[] }>(
    `/api/v1/barriers/${barrierId}/accommodations`,
  )
  return data.data
}

export async function linkAccommodationToBarrier(
  barrierId: number,
  accommodationId: number,
): Promise<BarrierAccommodationLink> {
  const { data } = await api.post<{ data: BarrierAccommodationLink }>(
    `/api/v1/barriers/${barrierId}/accommodations`,
    { accommodation_id: accommodationId },
  )
  return data.data
}

export async function validateBarrierAccommodation(
  barrierId: number,
  accommodationId: number,
): Promise<BarrierAccommodationLink> {
  const { data } = await api.post<{ data: BarrierAccommodationLink }>(
    `/api/v1/barriers/${barrierId}/accommodations/${accommodationId}/validate`,
  )
  return data.data
}

// ── Student audit history (Sesión 9) ─────────────────────────────────────
// The only paginated endpoint in the frontend so far — return the whole
// Paginated<T> envelope (docs/prompts/09 §2), never just the data array, so
// callers do not have to re-implement pagination on top of `meta`.

export async function fetchStudentHistory(
  studentId: number,
  page = 1,
): Promise<Paginated<AuditLogEntry>> {
  const { data } = await api.get<Paginated<AuditLogEntry>>(
    `/api/v1/students/${studentId}/history`,
    { params: { page } },
  )
  return data
}
