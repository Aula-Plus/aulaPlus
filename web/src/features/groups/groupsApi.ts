import { api } from "@/lib/api"
import type { EducationLevels, Group, GroupLevelSetting } from "@/types"

export interface GroupInput {
  name: string
  education_level_id: number
  school_grade_id: number | null
  school_year: number
}

export interface GroupLevelInput {
  education_level_id: number
  school_grade_id: number | null
}

export async function fetchGroups(): Promise<Group[]> {
  const { data } = await api.get<{ data: Group[] }>("/api/groups")
  return data.data
}

export async function fetchGroup(id: number): Promise<Group> {
  const { data } = await api.get<{ data: Group }>(`/api/groups/${id}`)
  return data.data
}

export async function createGroup(input: GroupInput): Promise<Group> {
  const { data } = await api.post<{ data: Group }>("/api/groups", input)
  return data.data
}

export async function updateGroup(id: number, input: GroupInput): Promise<Group> {
  const { data } = await api.put<{ data: Group }>(`/api/groups/${id}`, input)
  return data.data
}

export async function fetchEducationLevels(): Promise<EducationLevels> {
  const { data } = await api.get<EducationLevels>("/api/v1/education-levels")
  return data
}

/** Direction, or psychopedagogy when direction allowed it. */
export async function updateGroupLevel(id: number, input: GroupLevelInput): Promise<Group> {
  const { data } = await api.put<{ data: Group }>(`/api/v1/groups/${id}/level`, input)
  return data.data
}

export async function fetchGroupLevelSetting(): Promise<GroupLevelSetting> {
  const { data } = await api.get<{ data: GroupLevelSetting }>("/api/v1/group-level-setting")
  return data.data
}

export async function updateGroupLevelSetting(enabled: boolean): Promise<GroupLevelSetting> {
  const { data } = await api.put<{ data: GroupLevelSetting }>("/api/v1/group-level-setting", {
    psychopedagogue_can_set_group_level: enabled,
  })
  return data.data
}

export async function deleteGroup(id: number): Promise<void> {
  await api.delete(`/api/groups/${id}`)
}

export interface Teacher {
  id: number
  name: string
}

export async function fetchTeachers(): Promise<Teacher[]> {
  const { data } = await api.get<{ data: Teacher[] }>("/api/teachers")
  return data.data
}
