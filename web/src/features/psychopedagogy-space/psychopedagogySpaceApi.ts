import { api } from "@/lib/api"
import type {
  MeetingSheet,
  MeetingSheetRow,
  MeetingSheetType,
  MyMeetingAction,
  ObservationScope,
  PsychopedagogyMeeting,
  PsychopedagogyMeetingKind,
  PsychopedagogyOverview,
  StaffMember,
  TherapeuticCompanion,
  Weekday,
} from "@/types"

// «Espacio de psicopedagogía» (screen 10, ClickUp 1dfd3mc4bge). The server
// decides what each role gets: 403 for any teacher, and Dirección's payloads
// never carry with whom, the text, the students discussed, the actions nor the
// companions' reason.

export async function fetchOverview(week?: string): Promise<PsychopedagogyOverview> {
  const { data } = await api.get<{ data: PsychopedagogyOverview }>("/api/v1/psychopedagogy/overview", {
    params: week ? { week } : {},
  })
  return data.data
}

export async function fetchMeetings(week?: string): Promise<PsychopedagogyMeeting[]> {
  const { data } = await api.get<{ data: PsychopedagogyMeeting[] }>("/api/v1/psychopedagogy/meetings", {
    params: week ? { week } : {},
  })
  return data.data
}

export interface MeetingInput {
  kind: PsychopedagogyMeetingKind
  meeting_sheet_type_id?: number | null
  scheduled_on: string
  start_time: string
  student_id?: number | null
  group_id?: number | null
  attendee_ids: number[]
  external_attendees?: string | null
}

export async function createMeeting(input: MeetingInput): Promise<PsychopedagogyMeeting> {
  const { data } = await api.post<{ data: PsychopedagogyMeeting }>("/api/v1/psychopedagogy/meetings", input)
  return data.data
}

export async function completeMeeting(
  id: number,
  input: { note?: string; scope?: ObservationScope } = {},
): Promise<PsychopedagogyMeeting> {
  const { data } = await api.post<{ data: PsychopedagogyMeeting }>(
    `/api/v1/psychopedagogy/meetings/${id}/complete`,
    input,
  )
  return data.data
}

export async function fetchStaff(): Promise<StaffMember[]> {
  const { data } = await api.get<{ data: StaffMember[] }>("/api/v1/psychopedagogy/staff")
  return data.data
}

export async function fetchCompanions(): Promise<TherapeuticCompanion[]> {
  const { data } = await api.get<{ data: TherapeuticCompanion[] }>("/api/v1/psychopedagogy/companions")
  return data.data
}

export interface CompanionInput {
  student_id?: number
  name: string
  weekly_hours: number
  days: Weekday[]
  reason?: string | null
}

export async function createCompanion(input: CompanionInput): Promise<TherapeuticCompanion> {
  const { data } = await api.post<{ data: TherapeuticCompanion }>("/api/v1/psychopedagogy/companions", input)
  return data.data
}

export async function updateCompanion(id: number, input: CompanionInput): Promise<TherapeuticCompanion> {
  const { student_id: _ignored, ...rest } = input
  void _ignored
  const { data } = await api.patch<{ data: TherapeuticCompanion }>(`/api/v1/psychopedagogy/companions/${id}`, rest)
  return data.data
}

export async function deleteCompanion(id: number): Promise<void> {
  await api.delete(`/api/v1/psychopedagogy/companions/${id}`)
}

export async function fetchSheets(): Promise<MeetingSheetRow[]> {
  const { data } = await api.get<{ data: MeetingSheetRow[] }>("/api/v1/psychopedagogy/sheets")
  return data.data
}

export async function fetchSheet(id: number): Promise<MeetingSheet> {
  const { data } = await api.get<{ data: MeetingSheet }>(`/api/v1/psychopedagogy/sheets/${id}`)
  return data.data
}

export interface SheetInput {
  meeting_sheet_type_id: number
  psychopedagogy_meeting_id?: number | null
  held_on: string
  group_id: number | null
  attendee_ids: number[]
  external_attendees: string | null
  student_ids: number[]
  content: string
  fragments: { student_id: number; content: string; scope: ObservationScope }[]
  actions: { description: string; owner_id: number; due_on: string }[]
}

export async function saveSheet(id: number | null, input: SheetInput): Promise<MeetingSheet> {
  const { data } = id
    ? await api.put<{ data: MeetingSheet }>(`/api/v1/psychopedagogy/sheets/${id}`, input)
    : await api.post<{ data: MeetingSheet }>("/api/v1/psychopedagogy/sheets", input)
  return data.data
}

export async function confirmSheet(id: number): Promise<MeetingSheet> {
  const { data } = await api.post<{ data: MeetingSheet }>(`/api/v1/psychopedagogy/sheets/${id}/confirm`)
  return data.data
}

export async function fetchMeetingSheetTypes(): Promise<MeetingSheetType[]> {
  const { data } = await api.get<{ data: MeetingSheetType[] }>("/api/v1/meeting-sheet-types")
  return data.data
}

export async function createMeetingSheetType(name: string): Promise<MeetingSheetType> {
  const { data } = await api.post<{ data: MeetingSheetType }>("/api/v1/meeting-sheet-types", { name })
  return data.data
}

export async function renameMeetingSheetType(id: number, name: string): Promise<MeetingSheetType> {
  const { data } = await api.patch<{ data: MeetingSheetType }>(`/api/v1/meeting-sheet-types/${id}`, { name })
  return data.data
}

export async function fetchMyMeetingActions(): Promise<MyMeetingAction[]> {
  const { data } = await api.get<{ data: MyMeetingAction[] }>("/api/v1/meeting-actions/mine")
  return data.data
}

export async function completeMeetingAction(id: number): Promise<MyMeetingAction> {
  const { data } = await api.post<{ data: MyMeetingAction }>(`/api/v1/meeting-actions/${id}/complete`)
  return data.data
}
