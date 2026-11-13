import { api } from "@/lib/api"
import type { AppNotification } from "@/types"

export type NotificationsFilter = "all" | "unread"

/**
 * The caller's notice history (screen 22): `all` includes the read ones with
 * their `read_at`; `unread` is what the Inicio already lists. At most
 * NOTIFICATIONS_LIST_LIMIT, newest first.
 */
export async function fetchNotificationHistory(filter: NotificationsFilter): Promise<AppNotification[]> {
  const { data } = await api.get<{ data: AppNotification[] }>("/api/v1/notifications", { params: { filter } })
  return data.data
}

/** «Marcar todas como leídas»: marks the caller's own unread notices read. */
export async function markAllNotificationsRead(): Promise<void> {
  await api.post("/api/v1/notifications/read-all")
}
