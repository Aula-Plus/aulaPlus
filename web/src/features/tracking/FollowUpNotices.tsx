import { Button } from "@/components/ui/button"
import { SectionCard } from "@/components/ui/section-card"
import { NoticeText } from "@/features/notifications/NoticeText"
import type { FollowUpsInbox } from "./useFollowUpsInbox"

/**
 * «Avisos»: follow-ups assigned to the caller, alerts they have that reached
 * their deadline without a way out («Llegó a su fecha sin salida»), shared
 * extracts, returned proposals, new accommodations in force and screening
 * design changes. A notice is read
 * and dismissed — it is not a new alert and asks for nothing. Renders nothing
 * when there are none.
 *
 * `showError`: the Inicio shows a failed «Entendido» here, next to the notice;
 * «Mis seguimientos» shows it at the top of its section.
 */
export function FollowUpNotices({ inbox, showError = false }: { inbox: FollowUpsInbox; showError?: boolean }) {
  const { notifications, followUps, dismiss } = inbox
  if (notifications.length === 0) return null

  // Notices carry ids only; the student's name comes from the pending list.
  const studentNameByFollowUp = new Map(
    (followUps ?? []).map((followUp) => [followUp.id, followUp.student?.full_name]),
  )

  return (
    <SectionCard title="Avisos" headingLevel={3}>
      {showError && inbox.noticeError && <p className="mb-2 text-sm text-destructive">{inbox.noticeError}</p>}
      <ul className="grid gap-2">
        {notifications.map((notice) => (
          <li key={notice.id} className="flex items-center justify-between gap-3 rounded-md border p-3 text-sm">
            <NoticeText
              notice={notice}
              studentName={
                notice.data.type === "follow_up_assigned"
                  ? studentNameByFollowUp.get(notice.data.follow_up_id)
                  : undefined
              }
            />
            <Button type="button" size="sm" variant="outline" onClick={() => dismiss(notice.id)}>
              Entendido
            </Button>
          </li>
        ))}
      </ul>
    </SectionCard>
  )
}
