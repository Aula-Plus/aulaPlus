import { useSyncExternalStore } from "react"
import { fetchUnreadNotificationCount } from "./scheduledFollowUpsApi"

/**
 * Shared unread-notice count: the number next to "Mis seguimientos" in the
 * sidebar. A tiny external store so the sidebar (desktop and mobile drawer)
 * and the "Mis seguimientos" page read the same value — when a notice is read
 * there, the page calls {@link refreshUnreadNoticeCount} and the sidebar count
 * updates (or disappears at 0) without a reload. The server is always the
 * source of truth: the store only ever holds the last count it returned.
 *
 * The store is module-level (it outlives a logout), so it is keyed by the user
 * it belongs to and only the latest request may write to it: switching user or
 * resetting drops the count at once and aborts/ignores any request still in
 * flight, so user B never sees user A's count, not even briefly.
 */
let count = 0
/** The user the count belongs to; `null` while nobody is logged in. */
let ownerId: number | null = null
/** Bumped on every refresh/reset; a response only lands if it is still the latest. */
let generation = 0
let inFlight: AbortController | null = null
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

/** Invalidate (and abort) whatever request is in flight. */
function invalidate(): number {
  inFlight?.abort()
  inFlight = null
  return ++generation
}

/**
 * Re-fetch the count from the server for `userId` (the logged-in user). When
 * omitted, refreshes for the user the store already belongs to (e.g. after a
 * notice is read) and does nothing if nobody is logged in. A failed request
 * keeps the last value; a superseded one is discarded.
 */
export async function refreshUnreadNoticeCount(userId?: number): Promise<void> {
  const owner = userId ?? ownerId
  if (owner === null) return
  if (owner !== ownerId) {
    // A different user: never show the previous one's count while loading.
    ownerId = owner
    setCount(0)
  }

  const seq = invalidate()
  const controller = new AbortController()
  inFlight = controller
  try {
    const next = await fetchUnreadNotificationCount(controller.signal)
    if (seq === generation && owner === ownerId) setCount(next)
  } catch {
    // The count is a hint, not a gate: keep the last known value.
  } finally {
    if (inFlight === controller) inFlight = null
  }
}

/** Reset to zero and forget the owner (on logout, or between tests). */
export function resetUnreadNoticeCount(): void {
  invalidate()
  ownerId = null
  setCount(0)
}

export function useUnreadNoticeCount(): number {
  return useSyncExternalStore(subscribe, getSnapshot, getSnapshot)
}
