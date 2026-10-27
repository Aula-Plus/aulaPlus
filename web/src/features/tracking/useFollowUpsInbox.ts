import { useCallback, useEffect, useState } from "react"
import type { AppNotification, ScheduledFollowUp } from "@/types"
import * as scheduledFollowUpsApi from "./scheduledFollowUpsApi"
import { refreshUnreadNoticeCount } from "./unreadNotices"

/**
 * The caller's pending follow-ups plus their notices, loaded once. «Alertas y
 * seguimientos» shows both under «Mis seguimientos»; the Inicio shows the
 * notices with the alerts and the follow-ups apart (P01-DEC-03), so the page
 * owns this state and hands it to both blocks — one request each, not two.
 */
export interface FollowUpsInbox {
  followUps: ScheduledFollowUp[] | null
  notifications: AppNotification[]
  /** A failed load is not an empty list: «Pendientes» is not rendered then. */
  loadFailed: boolean
  loadError: string | null
  noticeError: string | null
  reload: () => Promise<void>
  dismiss: (id: string) => Promise<void>
}

export function useFollowUpsInbox(): FollowUpsInbox {
  const [followUps, setFollowUps] = useState<ScheduledFollowUp[] | null>(null)
  const [notifications, setNotifications] = useState<AppNotification[]>([])
  const [loadError, setLoadError] = useState<string | null>(null)
  const [noticeError, setNoticeError] = useState<string | null>(null)
  const [loadFailed, setLoadFailed] = useState(false)

  const reload = useCallback(async () => {
    try {
      const [mine, notices] = await Promise.all([
        scheduledFollowUpsApi.fetchMyFollowUps(),
        scheduledFollowUpsApi.fetchNotifications(),
      ])
      setFollowUps(mine)
      setNotifications(notices)
    } catch {
      setLoadError("No pudimos cargar tus seguimientos.")
      setLoadFailed(true)
    }
  }, [])

  useEffect(() => {
    reload()
  }, [reload])

  const dismiss = useCallback(async (id: string) => {
    try {
      await scheduledFollowUpsApi.markNotificationRead(id)
      setNotifications((prev) => prev.filter((notice) => notice.id !== id))
    } catch {
      setNoticeError("No pudimos marcar el aviso como leído.")
    }
    // Re-sync the sidebar count with the server (it disappears at 0).
    void refreshUnreadNoticeCount()
  }, [])

  return { followUps, notifications, loadFailed, loadError, noticeError, reload, dismiss }
}
