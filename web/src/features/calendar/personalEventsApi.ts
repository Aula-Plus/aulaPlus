import { api } from "@/lib/api"
import type { CalendarItem, PersonalEventOptions } from "@/types"

/**
 * Events of each person's own calendar (screen 21, ClickUp 1dfd3mc56kp).
 * Who may edit, delete or leave one is decided server-side
 * (`CalendarEventPolicy`); they are read through the Calendar feed.
 */

export interface PersonalEventInput {
  title: string
  /** `YYYY-MM-DD`, school-local. */
  date: string
  /** `HH:MM` or null for an event without a time. */
  time: string | null
  location: string | null
  description: string | null
  /** Everyone invited besides the creator (the full list). */
  invitee_ids: number[]
  student_id: number | null
}

export async function fetchPersonalEventOptions(): Promise<PersonalEventOptions> {
  const { data } = await api.get<{ data: PersonalEventOptions }>("/api/v1/personal-events/options")
  return data.data
}

export async function createPersonalEvent(input: PersonalEventInput): Promise<CalendarItem> {
  const { data } = await api.post<{ data: CalendarItem }>("/api/v1/personal-events", input)
  return data.data
}

export async function updatePersonalEvent(id: number, input: Partial<PersonalEventInput>): Promise<CalendarItem> {
  const { data } = await api.patch<{ data: CalendarItem }>(`/api/v1/personal-events/${id}`, input)
  return data.data
}

export async function deletePersonalEvent(id: number): Promise<void> {
  await api.delete(`/api/v1/personal-events/${id}`)
}

/** «Quitar de mi calendario»: the event stays for the other participants. */
export async function leaveCalendarEvent(id: number): Promise<void> {
  await api.delete(`/api/v1/calendar-events/${id}/participation`)
}
