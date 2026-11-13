import { useCallback, useEffect, useState } from "react"
import { Bell } from "lucide-react"
import { Button } from "@/components/ui/button"
import { EmptyState } from "@/components/ui/empty-state"
import { PageHeader } from "@/components/ui/page-header"
import { SectionCard } from "@/components/ui/section-card"
import { AlertsSection } from "@/features/alerts/AlertsSection"
import { markNotificationRead } from "@/features/tracking/scheduledFollowUpsApi"
import { refreshUnreadNoticeCount } from "@/features/tracking/unreadNotices"
import { cn, formatShortDate } from "@/lib/utils"
import type { AppNotification } from "@/types"
import { NoticeText } from "./NoticeText"
import { fetchNotificationHistory, markAllNotificationsRead, type NotificationsFilter } from "./notificationsApi"

const filterLabels: Record<NotificationsFilter, string> = {
  all: "Todas",
  unread: "Sin leer",
}

/**
 * Screen 22 «Notificaciones» (ClickUp 1dfd3mc4bgw): the history of what Aula+
 * told each person, alerts included — alerts are the most urgent notices, so
 * they come first with the same ways out as in «Alertas y seguimientos».
 *
 * Not a chat and no announcements (P22-DEC-01/02). Reading a notice resolves
 * nothing (P22-DEC-03): «Entendido» only marks it read; what is pending keeps
 * living on its own screen, where each notice links to. Every role sees its
 * own history; which notices each one gets is decided server-side.
 */
export function NotificationsPage() {
  const [filter, setFilter] = useState<NotificationsFilter>("all")
  const [notices, setNotices] = useState<AppNotification[] | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(
    (which: NotificationsFilter) =>
      fetchNotificationHistory(which).then(
        (list) => {
          setLoadError(null)
          setNotices(list)
        },
        () => setLoadError("No pudimos cargar tus notificaciones."),
      ),
    [],
  )

  useEffect(() => {
    void load(filter)
  }, [filter, load])

  function changeFilter(next: NotificationsFilter) {
    if (next === filter) return
    setNotices(null)
    setFilter(next)
  }

  async function markOne(id: string) {
    setActionError(null)
    setBusy(true)
    try {
      await markNotificationRead(id)
      const readAt = new Date().toISOString()
      setNotices((current) =>
        filter === "unread"
          ? (current ?? []).filter((notice) => notice.id !== id)
          : (current ?? []).map((notice) => (notice.id === id ? { ...notice, read_at: readAt } : notice)),
      )
    } catch {
      setActionError("No pudimos marcar el aviso como leído.")
    } finally {
      setBusy(false)
      void refreshUnreadNoticeCount()
    }
  }

  async function markAll() {
    setActionError(null)
    setBusy(true)
    try {
      await markAllNotificationsRead()
      await load(filter)
    } catch {
      setActionError("No pudimos marcar los avisos como leídos.")
    } finally {
      setBusy(false)
      void refreshUnreadNoticeCount()
    }
  }

  const hasUnread = (notices ?? []).some((notice) => !notice.read_at)

  return (
    <div className="grid gap-8">
      <PageHeader
        title="Notificaciones"
        description="Alertas y avisos de Aula+ — nunca una conversación sobre un alumno"
      />

      <AlertsSection compact />

      <SectionCard title="Avisos" headingLevel={2}>
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
          <div role="group" aria-label="Filtrar avisos" className="flex gap-1">
            {(Object.keys(filterLabels) as NotificationsFilter[]).map((option) => (
              <Button
                key={option}
                type="button"
                size="sm"
                variant={filter === option ? "default" : "outline"}
                aria-pressed={filter === option}
                onClick={() => changeFilter(option)}
              >
                {filterLabels[option]}
              </Button>
            ))}
          </div>
          <Button type="button" size="sm" variant="outline" disabled={busy || !hasUnread} onClick={markAll}>
            Marcar todas como leídas
          </Button>
        </div>

        {actionError && <p className="mb-2 text-sm text-destructive">{actionError}</p>}

        {loadError ? (
          <p className="text-sm text-destructive">{loadError}</p>
        ) : notices === null ? (
          <p className="text-sm text-muted-foreground">Cargando avisos…</p>
        ) : notices.length === 0 ? (
          <EmptyState
            icon={Bell}
            message={filter === "unread" ? "No tenés avisos sin leer." : "Todavía no tenés avisos."}
          />
        ) : (
          <ul className="grid gap-2">
            {notices.map((notice) => {
              const unread = !notice.read_at
              return (
                <li
                  key={notice.id}
                  className={cn(
                    "flex items-center justify-between gap-3 rounded-md border p-3 text-sm",
                    unread ? "bg-card" : "bg-muted/40 text-muted-foreground",
                  )}
                >
                  <div className="grid gap-1">
                    <NoticeText notice={notice} />
                    <span className="text-xs text-muted-foreground">
                      {notice.created_at ? formatShortDate(notice.created_at) : ""}
                      {unread ? " · Sin leer" : " · Leído"}
                    </span>
                  </div>
                  {unread && (
                    <Button type="button" size="sm" variant="outline" disabled={busy} onClick={() => markOne(notice.id)}>
                      Entendido
                    </Button>
                  )}
                </li>
              )
            })}
          </ul>
        )}
        <p className="mt-4 text-xs text-muted-foreground">
          Leer un aviso no resuelve nada: «Entendido» lo marca como leído y lo pendiente sigue en su pantalla.
        </p>
      </SectionCard>
    </div>
  )
}
