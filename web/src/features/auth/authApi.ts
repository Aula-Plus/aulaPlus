import { api, ensureCsrfCookie, resetCsrfCookie } from "@/lib/api"
import type { User } from "@/types"

export interface LoginCredentials {
  email: string
  password: string
}

/** Authenticate and start a session. Returns the logged-in user. */
export async function login(credentials: LoginCredentials): Promise<User> {
  await ensureCsrfCookie()
  const { data } = await api.post<{ user: User }>("/api/login", credentials)
  return data.user
}

/** Fetch the currently authenticated user, or null if not logged in. */
export async function fetchCurrentUser(): Promise<User | null> {
  try {
    const { data } = await api.get<{ data: User }>("/api/me")
    return data.data
  } catch {
    return null
  }
}

/** Destroy the session. */
export async function logout(): Promise<void> {
  await ensureCsrfCookie()
  await api.post("/api/logout")
  resetCsrfCookie()
}
