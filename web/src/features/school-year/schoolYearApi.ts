import { api } from "@/lib/api"
import type { CalendarBody } from "@/types"

/**
 * Screen 26 «Año y calendario» (ClickUp 1dfd3mc4bh7). Direction only; the
 * server decides (SchoolYearPolicy) and returns the whole screen state after
 * every change.
 */

export type SchoolYearStatus = "open" | "closed"
export type PeriodState = "past" | "current" | "upcoming"
export type CarryAction = "same_date" | "other_date" | "skip"

export interface SchoolPeriod {
  id: number
  name: string
  /** YYYY-MM-DD */
  starts_on: string
  state: PeriodState
}

/**
 * One row of Períodos: a body of the school's offer (`curricular_framework_id`
 * null; `programs` lists its programs) or, once Cambridge or IB is split by
 * program — or for a program with no body —, one program.
 */
export interface PeriodCalendar {
  /** «body:cambridge» or «program:12». */
  key: string
  body: CalendarBody | null
  curricular_framework_id: number | null
  /** The program's name on a program row; null on a body row. */
  name: string | null
  programs: string[]
  /** «Separar por programa» is offered (Cambridge/IB with two programs or more). */
  can_split: boolean
  periods: SchoolPeriod[]
}

export interface GroupWriteWindow {
  id: number
  group: { id: number; name: string; school_year: number } | null
  reason: string
  until_on: string
  enabled_by_name: string | null
  enabled_at: string | null
  ended_at: string | null
  ended_by_name: string | null
  active: boolean
}

export interface SchoolYearState {
  year: {
    id: number
    year: number
    status: SchoolYearStatus
    closing_enabled_from: string | null
    can_close: boolean
    closed_at: string | null
    closed_by_name: string | null
    /** «Deshacer el cierre»: only while no later year exists. */
    can_reopen: boolean
    reopened_at: string | null
    reopened_by_name: string | null
  }
  next_year: number
  previous: { year: number; status: SchoolYearStatus; closed_at: string | null } | null
  active_groups: number
  carried_accommodations: number
  calendars: PeriodCalendar[]
  /** Bodies split by program this year. */
  split_bodies: CalendarBody[]
  closed_year_groups: { id: number; name: string; school_year: number }[]
  write_windows: GroupWriteWindow[]
}

export interface CarryDecision {
  id: number
  action: CarryAction
  starts_on?: string
}

type Payload = { data: SchoolYearState }

export async function fetchSchoolYear(): Promise<SchoolYearState> {
  const { data } = await api.get<Payload>("/api/v1/school-year")
  return data.data
}

export async function updateClosingDate(yearId: number, closingEnabledFrom: string): Promise<SchoolYearState> {
  const { data } = await api.put<Payload>(`/api/v1/school-years/${yearId}`, {
    closing_enabled_from: closingEnabledFrom,
  })
  return data.data
}

/** A period goes to a body row (`calendar_body`) or a program row (`curricular_framework_id`). */
export type PeriodTarget = { calendar_body: CalendarBody } | { curricular_framework_id: number }

export async function createPeriod(
  yearId: number,
  input: PeriodTarget & { name: string; starts_on: string },
): Promise<SchoolYearState> {
  const { data } = await api.post<Payload>(`/api/v1/school-years/${yearId}/periods`, input)
  return data.data
}

export async function updatePeriod(
  periodId: number,
  input: { name: string; starts_on: string },
): Promise<SchoolYearState> {
  const { data } = await api.patch<Payload>(`/api/v1/school-periods/${periodId}`, input)
  return data.data
}

export async function deletePeriod(periodId: number): Promise<void> {
  await api.delete(`/api/v1/school-periods/${periodId}`)
}

export async function closeYear(yearId: number): Promise<SchoolYearState> {
  const { data } = await api.post<Payload>(`/api/v1/school-years/${yearId}/close`, { confirm: true })
  return data.data
}

export async function reopenYear(yearId: number): Promise<SchoolYearState> {
  const { data } = await api.post<Payload>(`/api/v1/school-years/${yearId}/reopen`, { confirm: true })
  return data.data
}

/** «Separar por programa» (Cambridge, IB). */
export async function splitBody(yearId: number, body: CalendarBody): Promise<SchoolYearState> {
  const { data } = await api.post<Payload>(`/api/v1/school-years/${yearId}/calendar-bodies/${body}/split`)
  return data.data
}

/** Back to one row for the body (only when its programs have the same periods). */
export async function joinBody(yearId: number, body: CalendarBody): Promise<SchoolYearState> {
  const { data } = await api.post<Payload>(`/api/v1/school-years/${yearId}/calendar-bodies/${body}/join`)
  return data.data
}

export async function openNextYear(yearId: number, periods: CarryDecision[]): Promise<SchoolYearState> {
  const { data } = await api.post<Payload>(`/api/v1/school-years/${yearId}/open-next`, { periods })
  return data.data
}

export async function createWriteWindow(
  yearId: number,
  input: { group_id: number; reason: string; until_on: string },
): Promise<SchoolYearState> {
  const { data } = await api.post<Payload>(`/api/v1/school-years/${yearId}/write-windows`, input)
  return data.data
}

export async function endWriteWindow(windowId: number): Promise<SchoolYearState> {
  const { data } = await api.post<Payload>(`/api/v1/group-write-windows/${windowId}/end`)
  return data.data
}
