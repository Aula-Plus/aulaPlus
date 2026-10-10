import { useSyncExternalStore } from "react"
import { fetchUnreadNotificationCount } from "./scheduledFollowUpsApi"

/**
 * Shared unread-notice count: the number next to "Mis seguimientos" in the
 * sidebar. A tiny external store so the sidebar (desktop and mobile drawer)
 * and the "Mis seguimientos" page read the same value — when a notice is read
 * there, the page calls {@link refreshUnreadNoticeCount} and the sidebar count
 * updates (or disappears at 0) without a reload. The server is always the
 * source of truth: the store only ever holds the last count it returned.
 */
let count = 0
const listeners = new Set<() => void>()

function setCount(next: number) {
  if (next === count) return
  count = next
  for (const listener of listeners) listener()
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

function getSnapshot(): number {
  return count
}

/** Re-fetch the count from the server. A failed request keeps the last value. */
export async function refreshUnreadNoticeCount(): Promise<void> {
  try {
    setCount(await fetchUnreadNotificationCount())
  } catch {
    // The count is a hint, not a gate: keep the last known value.
  }
}

/** Reset to zero (e.g. between tests or on logout). */
export function resetUnreadNoticeCount(): void {
  setCount(0)
}

export function useUnreadNoticeCount(): number {
  return useSyncExternalStore(subscribe, getSnapshot, getSnapshot)
}
