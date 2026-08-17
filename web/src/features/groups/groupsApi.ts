import { api } from "@/lib/api"
import type { Group } from "@/types"

export interface GroupInput {
  name: string
  level?: string
  year?: string
  teacher_id?: number | null
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
