import { api } from "@/lib/api"
import type {
  AccommodationCategory,
  AccommodationInboxRow,
  AccommodationInboxStatus,
  AnnualReviewRow,
  InboxReportProposals,
} from "@/types"

// «Ajustes y PTP» (screen 06, ClickUp 1dfd3mc4bgc). Psychopedagogy only: the
// server answers 403 to anyone else (AccommodationPolicy::viewInbox,
// StudentAnnualReviewPolicy).

export interface InboxFilters {
  status?: AccommodationInboxStatus
  group_id?: number
  category?: AccommodationCategory | "none"
  page?: number
}

export interface AccommodationInbox {
  data: AccommodationInboxRow[]
  /** 50 accommodations per page (AccommodationInboxController::PER_PAGE). */
  meta: { current_page: number; last_page: number; total: number }
  report_proposals: InboxReportProposals[]
}

export async function fetchAccommodationInbox(filters: InboxFilters = {}): Promise<AccommodationInbox> {
  const { data } = await api.get<AccommodationInbox>("/api/v1/accommodation-inbox", { params: filters })
  return data
}

export async function fetchAnnualReviews(): Promise<{ rows: AnnualReviewRow[]; schoolYear: number }> {
  const { data } = await api.get<{ data: AnnualReviewRow[]; meta: { school_year: number } }>(
    "/api/v1/annual-reviews",
  )
  return { rows: data.data, schoolYear: data.meta.school_year }
}

/** How many students still lack this year's review (the one Inicio row). */
export async function fetchPendingAnnualReviews(): Promise<number> {
  const { data } = await api.get<{ data: { count: number } }>("/api/v1/annual-reviews/pending")
  return data.data.count
}

export async function markAnnualReview(
  studentId: number,
): Promise<{ reviewed_on: string; reviewed_by_name: string | null; school_year: number }> {
  const { data } = await api.post<{
    data: { reviewed_on: string; reviewed_by_name: string | null; school_year: number }
  }>(`/api/v1/students/${studentId}/annual-review`)
  return data.data
}
