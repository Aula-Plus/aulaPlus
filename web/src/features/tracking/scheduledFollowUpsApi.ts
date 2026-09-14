import { api } from "@/lib/api"
import type { ScheduledFollowUp } from "@/types"

/**
 * Data layer for scheduled follow-ups (backend Sesión 7 — docs/prompts/17-
 * seguimiento-programado.md; frontend docs/prompts/23-frontend-seguimiento-
 * programado.md). Kept in its own module instead of piling onto trackingApi.ts,
 * which already concentrates comments, alerts, approval and barriers — one file
 * per domain (same criterion left open by docs/prompts/09 §3).
 *
 * Every response is wrapped in `{ data: ... }` by the Laravel Resources, like
 * the rest of the tracking API. The CSRF cookie is established once at login
 * (see authApi), so the mutations here do not fetch it again.
 */

/**
 * List a student's scheduled follow-ups. Passing `resolved` narrows the result
 * with `?resolved=true|false`; omitting it returns every follow-up (resolved
 * and pending) — the default filter is a client concern, the backend does not
 * impose one (spec §1).
 */
export async function fetchScheduledFollowUps(
  studentId: number,
  resolved?: boolean,
): Promise<ScheduledFollowUp[]> {
  const { data } = await api.get<{ data: ScheduledFollowUp[] }>(
    `/api/v1/students/${studentId}/scheduled-follow-ups`,
    resolved === undefined ? undefined : { params: { resolved } },
  )
  return data.data
}

export async function createScheduledFollowUp(
  studentId: number,
  input: { description: string; due_date: string },
): Promise<ScheduledFollowUp> {
  const { data } = await api.post<{ data: ScheduledFollowUp }>(
    `/api/v1/students/${studentId}/scheduled-follow-ups`,
    input,
  )
  return data.data
}

/**
 * Resolve a follow-up. `resolution_note` is optional free text; when the user
 * leaves it empty we OMIT the field entirely rather than send
 * `resolution_note: ""` (spec §6) — `undefined` is dropped from the JSON body
 * by axios.
 */
export async function resolveScheduledFollowUp(
  id: number,
  resolutionNote?: string,
): Promise<ScheduledFollowUp> {
  const { data } = await api.post<{ data: ScheduledFollowUp }>(
    `/api/v1/scheduled-follow-ups/${id}/resolve`,
    resolutionNote ? { resolution_note: resolutionNote } : {},
  )
  return data.data
}
