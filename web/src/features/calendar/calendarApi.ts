import { api } from "@/lib/api"
import type {
  CalendarItem,
  CalendarItemType,
  InstitutionalEvent,
  TeachingAssignment,
  TimetableSlot,
} from "@/types"

/**
 * Data layer of the Calendar (screen 21, ClickUp 1dfd3mc4bgh). What each
 * person sees is decided server-side (`CalendarFeed`); the SPA only renders.
 */

export async function fetchCalendar(
  from: string,
  to: string,
  types?: readonly CalendarItemType[],
): Promise<CalendarItem[]> {
  const { data } = await api.get<{ data: CalendarItem[] }>("/api/v1/calendar", {
    // axios serializes arrays as `types[]=…`, which Laravel reads as a list.
    params: { from, to, ...(types ? { types } : {}) },
  })
  return data.data
}

export interface InstitutionalEventInput {
  title: string
  /** `YYYY-MM-DD`, school-local. */
  date: string
  /** `HH:MM` or null for an event without a time. */
  time: string | null
  location: string | null
  description: string | null
}

export async function createInstitutionalEvent(input: InstitutionalEventInput): Promise<InstitutionalEvent> {
  const { data } = await api.post<{ data: InstitutionalEvent }>("/api/v1/calendar-events", input)
  return data.data
}

export async function updateInstitutionalEvent(
  id: number,
  input: Partial<InstitutionalEventInput>,
): Promise<InstitutionalEvent> {
  const { data } = await api.patch<{ data: InstitutionalEvent }>(`/api/v1/calendar-events/${id}`, input)
  return data.data
}

export async function deleteInstitutionalEvent(id: number): Promise<void> {
  await api.delete(`/api/v1/calendar-events/${id}`)
}

export interface ClassSchedule {
  start_time: string | null
  room: string | null
}

export async function updateClassSchedule(classSessionId: number, input: ClassSchedule): Promise<ClassSchedule> {
  const { data } = await api.patch<{ data: ClassSchedule }>(`/api/v1/class-sessions/${classSessionId}`, input)
  return data.data
}

/** What «Mis horarios» proposes for a class of a group-subject on a date, or null. */
export async function fetchSuggestedSlot(params: {
  group_id: number
  subject_id: number
  date: string
  class_session_id?: number
}): Promise<{ start_time: string; room: string | null } | null> {
  const { data } = await api.get<{ data: { start_time: string; room: string | null } | null }>(
    "/api/v1/class-sessions/suggested-slot",
    { params },
  )
  return data.data
}

export async function fetchTimetable(): Promise<{ slots: TimetableSlot[]; assignments: TeachingAssignment[] }> {
  const { data } = await api.get<{ data: TimetableSlot[]; meta: { assignments: TeachingAssignment[] } }>(
    "/api/v1/timetable-slots",
  )
  return { slots: data.data, assignments: data.meta.assignments }
}

export interface TimetableSlotInput {
  group_id: number
  subject_id: number
  weekday: number
  start_time: string
  room: string | null
}

export async function createTimetableSlot(input: TimetableSlotInput): Promise<TimetableSlot> {
  const { data } = await api.post<{ data: TimetableSlot }>("/api/v1/timetable-slots", input)
  return data.data
}

export async function updateTimetableSlot(id: number, input: Partial<TimetableSlotInput>): Promise<TimetableSlot> {
  const { data } = await api.patch<{ data: TimetableSlot }>(`/api/v1/timetable-slots/${id}`, input)
  return data.data
}

export async function deleteTimetableSlot(id: number): Promise<void> {
  await api.delete(`/api/v1/timetable-slots/${id}`)
}
