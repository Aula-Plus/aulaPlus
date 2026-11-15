import { api } from "@/lib/api"

/**
 * The signed-in person's own display preferences (ClickUp 1dfd3mc4zpk),
 * stored on the server so they follow the person across computers.
 */
export interface UserPreferences {
  /** Psychopedagogy's «N evaluaciones hoy» total in the Inicio (on by default). */
  show_today_assessments_total: boolean
}

export async function fetchUserPreferences(): Promise<UserPreferences> {
  const { data } = await api.get<{ data: UserPreferences }>("/api/v1/me/preferences")
  return data.data
}

export async function updateUserPreferences(input: UserPreferences): Promise<UserPreferences> {
  const { data } = await api.patch<{ data: UserPreferences }>("/api/v1/me/preferences", input)
  return data.data
}
