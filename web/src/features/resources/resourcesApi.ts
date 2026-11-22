import { api } from "@/lib/api"

/**
 * «Recursos disponibles» (screen 23 P23-REC-02 and the group's card «Del
 * colegio y del grupo», PA-085 — ClickUp 1dfd3mc4bh4).
 */

export interface SchoolResource {
  id: number
  name: string
}

/** `group`: every teacher of the group sees it; `own`: only the teacher who added it (Dirección and Psicopedagogía too). */
export type ResourceVisibility = "group" | "own"

export interface GroupAddedResource {
  id: number
  name: string
  visibility: ResourceVisibility
  created_by_name: string | null
  can_remove: boolean
}

export interface GroupResources {
  can_manage: boolean
  /** Only a teacher of the group adds resources just for themself. */
  can_add_own: boolean
  school: { id: number; name: string; available: boolean }[]
  group: GroupAddedResource[]
}

export async function fetchSchoolResources(): Promise<SchoolResource[]> {
  const { data } = await api.get<{ data: SchoolResource[] }>("/api/v1/school-resources")
  return data.data
}

export async function createSchoolResource(name: string): Promise<SchoolResource> {
  const { data } = await api.post<{ data: SchoolResource }>("/api/v1/school-resources", { name })
  return data.data
}

export async function deleteSchoolResource(id: number): Promise<void> {
  await api.delete(`/api/v1/school-resources/${id}`)
}

export async function fetchGroupResources(groupId: number): Promise<GroupResources> {
  const { data } = await api.get<{ data: GroupResources }>(`/api/v1/groups/${groupId}/resources`)
  return data.data
}

export async function setSchoolResourceAvailable(groupId: number, resourceId: number, available: boolean): Promise<GroupResources> {
  const { data } = await api.put<{ data: GroupResources }>(`/api/v1/groups/${groupId}/resources/school/${resourceId}`, {
    available,
  })
  return data.data
}

export async function createGroupResource(
  groupId: number,
  name: string,
  visibility: ResourceVisibility = "group",
): Promise<GroupResources> {
  const { data } = await api.post<{ data: GroupResources }>(`/api/v1/groups/${groupId}/resources`, { name, visibility })
  return data.data
}

export async function deleteGroupResource(groupId: number, resourceId: number): Promise<GroupResources> {
  const { data } = await api.delete<{ data: GroupResources }>(`/api/v1/groups/${groupId}/resources/${resourceId}`)
  return data.data
}
