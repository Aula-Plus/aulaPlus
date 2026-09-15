import { api, ensureCsrfCookie } from "@/lib/api"
import type { ManagedUser, Role } from "@/types"

/**
 * Data layer for the "Usuarios" feature. Authenticated endpoints are
 * director-only (mirrored by `canManageUsers`); the two invitation endpoints
 * are public and token-gated (the invited user has no session yet).
 */

export interface CreateUserInput {
  name: string
  email: string
  role: Role
}

export interface UpdateUserInput {
  name?: string
  role?: Role
}

export async function fetchUsers(search?: string): Promise<ManagedUser[]> {
  const { data } = await api.get<{ data: ManagedUser[] }>("/api/v1/users", {
    params: search ? { search } : undefined,
  })
  return data.data
}

export async function fetchUser(id: number): Promise<ManagedUser> {
  const { data } = await api.get<{ data: ManagedUser }>(`/api/v1/users/${id}`)
  return data.data
}

export async function createUser(input: CreateUserInput): Promise<ManagedUser> {
  const { data } = await api.post<{ data: ManagedUser }>("/api/v1/users", input)
  return data.data
}

export async function updateUser(id: number, input: UpdateUserInput): Promise<ManagedUser> {
  const { data } = await api.patch<{ data: ManagedUser }>(`/api/v1/users/${id}`, input)
  return data.data
}

export async function disableUser(id: number): Promise<ManagedUser> {
  const { data } = await api.post<{ data: ManagedUser }>(`/api/v1/users/${id}/disable`)
  return data.data
}

export async function enableUser(id: number): Promise<ManagedUser> {
  const { data } = await api.post<{ data: ManagedUser }>(`/api/v1/users/${id}/enable`)
  return data.data
}

export async function resendInvitation(id: number): Promise<void> {
  await api.post(`/api/v1/users/${id}/resend-invitation`)
}

// ── Public invitation acceptance ────────────────────────────────────────────

export async function fetchInvitation(
  token: string,
): Promise<{ email: string; school_name: string }> {
  const { data } = await api.get<{ data: { email: string; school_name: string } }>(
    `/api/invitations/${token}`,
  )
  return data.data
}

export async function acceptInvitation(token: string, password: string): Promise<void> {
  // State-changing POST → ensure the XSRF cookie exists first.
  await ensureCsrfCookie()
  await api.post(`/api/invitations/${token}/accept`, {
    password,
    password_confirmation: password,
  })
}
