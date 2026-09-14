import { api } from "@/lib/api"
import type {
  ScreeningTestApplication,
  ScreeningTestApplicationInput,
  ScreeningTestDesign,
  ScreeningTestDesignInput,
  ScreeningTestResult,
  ScreeningTestRosterEntry,
  ScreeningTestType,
  ScreeningTestTypeInput,
} from "@/types"

/**
 * Data layer for the screening-test module (backend Sesión 13 — docs/prompts/
 * 11-pruebas-de-sondeo.md; frontend docs/prompts/12-frontend-pruebas-de-
 * sondeo.md), kept in its own feature folder like the other domains. Contracts
 * verified against the real controllers/resources, not the spec draft.
 *
 * Every endpoint here wraps its payload in Laravel's `{ data: ... }` sleeve
 * (both the AnonymousResourceCollection listings and the single-resource
 * responses, including the roster which hand-rolls `response()->json(['data' =>
 * ...])`). The CSRF cookie is established once at login (see authApi), so the
 * mutations do not fetch it again.
 */

// ── Types & designs (design screen) ─────────────────────────────────────────

/** List the school's screening-test types with their in-force approved design. */
export async function fetchScreeningTestTypes(): Promise<ScreeningTestType[]> {
  const { data } = await api.get<{ data: ScreeningTestType[] }>("/api/v1/screening-test-types")
  return data.data
}

export async function createScreeningTestType(
  input: ScreeningTestTypeInput,
): Promise<ScreeningTestType> {
  const { data } = await api.post<{ data: ScreeningTestType }>(
    "/api/v1/screening-test-types",
    input,
  )
  return data.data
}

/**
 * Author a new design version for a type. Always inserts a fresh row left
 * `approved === null` (pending) — the backend keeps the whole history and never
 * mutates an earlier version.
 */
export async function createScreeningTestDesign(
  typeId: number,
  input: ScreeningTestDesignInput,
): Promise<ScreeningTestDesign> {
  const { data } = await api.post<{ data: ScreeningTestDesign }>(
    `/api/v1/screening-test-types/${typeId}/designs`,
    input,
  )
  return data.data
}

export async function approveScreeningTestDesign(designId: number): Promise<ScreeningTestDesign> {
  const { data } = await api.post<{ data: ScreeningTestDesign }>(
    `/api/v1/screening-test-designs/${designId}/approve`,
    {},
  )
  return data.data
}

export async function rejectScreeningTestDesign(designId: number): Promise<ScreeningTestDesign> {
  const { data } = await api.post<{ data: ScreeningTestDesign }>(
    `/api/v1/screening-test-designs/${designId}/reject`,
    {},
  )
  return data.data
}

// ── Applications, roster & results (application screen) ──────────────────────

export async function fetchGroupScreeningTestApplications(
  groupId: number,
): Promise<ScreeningTestApplication[]> {
  const { data } = await api.get<{ data: ScreeningTestApplication[] }>(
    `/api/v1/groups/${groupId}/screening-test-applications`,
  )
  return data.data
}

/**
 * Apply a screening test to a group. `application_date` is omitted from the body
 * when empty so the backend applies its own default rather than receiving an
 * empty string.
 */
export async function createScreeningTestApplication(
  groupId: number,
  input: ScreeningTestApplicationInput,
): Promise<ScreeningTestApplication> {
  const body: ScreeningTestApplicationInput = {
    screening_test_type_id: input.screening_test_type_id,
    ...(input.application_date ? { application_date: input.application_date } : {}),
  }
  const { data } = await api.post<{ data: ScreeningTestApplication }>(
    `/api/v1/groups/${groupId}/screening-test-applications`,
    body,
  )
  return data.data
}

/**
 * The printable code→student roster. Psychopedagogy only (403 otherwise) — the
 * single place code and full name are ever crossed.
 */
export async function fetchScreeningTestRoster(
  applicationId: number,
): Promise<ScreeningTestRosterEntry[]> {
  const { data } = await api.get<{ data: ScreeningTestRosterEntry[] }>(
    `/api/v1/screening-test-applications/${applicationId}/roster`,
  )
  return data.data
}

/** Results by code, no names. Psychopedagogy only. */
export async function fetchScreeningTestResults(
  applicationId: number,
): Promise<ScreeningTestResult[]> {
  const { data } = await api.get<{ data: ScreeningTestResult[] }>(
    `/api/v1/screening-test-applications/${applicationId}/results`,
  )
  return data.data
}

/**
 * Load a score onto a result. The backend computes and persists the traffic
 * -light `color` against the type's current approved design and returns the
 * updated result — the client renders that colour as-is, never recomputing it.
 */
export async function updateScreeningTestResult(
  resultId: number,
  score: number,
): Promise<ScreeningTestResult> {
  const { data } = await api.patch<{ data: ScreeningTestResult }>(
    `/api/v1/screening-test-results/${resultId}`,
    { score },
  )
  return data.data
}
