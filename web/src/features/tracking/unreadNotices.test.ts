import { act, renderHook } from "@testing-library/react"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import * as api from "./scheduledFollowUpsApi"
import {
  refreshUnreadNoticeCount,
  resetUnreadNoticeCount,
  useUnreadNoticeCount,
} from "./unreadNotices"

/** A request whose response the test releases by hand. */
function deferred() {
  let resolve!: (count: number) => void
  const promise = new Promise<number>((r) => {
    resolve = r
  })
  return { promise, resolve }
}

describe("unread notice count store", () => {
  beforeEach(() => resetUnreadNoticeCount())
  afterEach(() => vi.restoreAllMocks())

  it("holds the count the server returned for the logged-in user", async () => {
    vi.spyOn(api, "fetchUnreadNotificationCount").mockResolvedValue(3)
    const { result } = renderHook(() => useUnreadNoticeCount())

    await act(() => refreshUnreadNoticeCount(1))

    expect(result.current).toBe(3)
  })

  it("discards a response that lands after a logout reset", async () => {
    const slow = deferred()
    vi.spyOn(api, "fetchUnreadNotificationCount").mockReturnValue(slow.promise)
    const { result } = renderHook(() => useUnreadNoticeCount())

    const pending = refreshUnreadNoticeCount(1)
    act(() => resetUnreadNoticeCount())
    await act(async () => {
      slow.resolve(5)
      await pending
    })

    expect(result.current).toBe(0)
  })

  it("aborts the in-flight request on reset", () => {
    const fetchCount = vi
      .spyOn(api, "fetchUnreadNotificationCount")
      .mockReturnValue(new Promise<number>(() => {}))

    void refreshUnreadNoticeCount(1)
    const signal = fetchCount.mock.calls[0][0] as AbortSignal
    expect(signal.aborted).toBe(false)

    resetUnreadNoticeCount()
    expect(signal.aborted).toBe(true)
  })

  it("never shows the previous user's count to the next one, even when A's response is late", async () => {
    const forA = deferred()
    const forB = deferred()
    vi.spyOn(api, "fetchUnreadNotificationCount")
      .mockResolvedValueOnce(4)
      .mockReturnValueOnce(forA.promise)
      .mockReturnValueOnce(forB.promise)
    const { result } = renderHook(() => useUnreadNoticeCount())

    // A logged in and saw 4.
    await act(() => refreshUnreadNoticeCount(1))
    expect(result.current).toBe(4)

    // A refreshes again, then B takes over the tab before either answers.
    const pendingA = refreshUnreadNoticeCount(1)
    let pendingB!: Promise<void>
    act(() => {
      pendingB = refreshUnreadNoticeCount(2)
    })
    // B starts from zero, not from A's 4.
    expect(result.current).toBe(0)

    await act(async () => {
      forA.resolve(9)
      await pendingA
    })
    expect(result.current).toBe(0)

    await act(async () => {
      forB.resolve(1)
      await pendingB
    })
    expect(result.current).toBe(1)
  })

  it("lets the latest request win when responses arrive out of order", async () => {
    const older = deferred()
    const newer = deferred()
    vi.spyOn(api, "fetchUnreadNotificationCount")
      .mockReturnValueOnce(older.promise)
      .mockReturnValueOnce(newer.promise)
    const { result } = renderHook(() => useUnreadNoticeCount())

    const first = refreshUnreadNoticeCount(1)
    const second = refreshUnreadNoticeCount(1)
    await act(async () => {
      newer.resolve(0)
      await second
    })
    await act(async () => {
      older.resolve(2)
      await first
    })

    expect(result.current).toBe(0)
  })

  it("refreshes for the current user when called without one, and not at all when logged out", async () => {
    const fetchCount = vi.spyOn(api, "fetchUnreadNotificationCount").mockResolvedValue(2)
    const { result } = renderHook(() => useUnreadNoticeCount())

    await act(() => refreshUnreadNoticeCount())
    expect(fetchCount).not.toHaveBeenCalled()

    await act(() => refreshUnreadNoticeCount(1))
    fetchCount.mockResolvedValue(1)
    await act(() => refreshUnreadNoticeCount())
    expect(fetchCount).toHaveBeenCalledTimes(2)
    expect(result.current).toBe(1)
  })
})
