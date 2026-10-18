import { useCallback, useRef } from "react"
import { api } from "@/lib/api"
import type { JourneyContentType } from "@/types"

/**
 * Creation journeys (ClickUp 86e3qkxtc): the browser reports the steps only
 * it can observe — the create form was opened, and the first change in any
 * field — under a journey id it generates. The same id is sent with the save,
 * so the server links its own confirmed-creation record to the journey.
 *
 * Only ids and step names travel: never any field value. Reporting is
 * best-effort and must never block or break the form.
 */

type ClientJourneyStep = "form_opened" | "filling_started"

export async function recordJourneyStep(input: {
  journey_id: string
  step: ClientJourneyStep
  content_type: JourneyContentType
  group_id?: number
}): Promise<void> {
  await api.post("/api/v1/usage/journey-steps", input)
}

/** RFC 4122 v4 id; falls back to getRandomValues outside secure contexts. */
export function newJourneyId(): string {
  if (typeof crypto.randomUUID === "function") return crypto.randomUUID()
  const bytes = crypto.getRandomValues(new Uint8Array(16))
  bytes[6] = (bytes[6] & 0x0f) | 0x40
  bytes[8] = (bytes[8] & 0x3f) | 0x80
  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("")
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

/**
 * One journey per create form: `formOpened` and `fillingStarted` each report
 * once per journey; `journeyId()` is what the save sends; `restart()` begins a
 * new journey after a successful save (the form is ready for the next one).
 */
export function useCreationJourney(contentType: JourneyContentType, groupId?: number) {
  const journey = useRef<{ id: string; sent: Set<ClientJourneyStep> } | null>(null)

  const current = useCallback(() => {
    journey.current ??= { id: newJourneyId(), sent: new Set() }
    return journey.current
  }, [])

  const report = useCallback(
    (step: ClientJourneyStep) => {
      const { id, sent } = current()
      if (sent.has(step)) return
      sent.add(step)
      recordJourneyStep({ journey_id: id, step, content_type: contentType, group_id: groupId }).catch(
        () => undefined,
      )
    },
    [contentType, current, groupId],
  )

  const formOpened = useCallback(() => report("form_opened"), [report])
  const fillingStarted = useCallback(() => {
    // Filling implies the form was open, even if focus was never reported.
    report("form_opened")
    report("filling_started")
  }, [report])
  const journeyId = useCallback(() => current().id, [current])
  const restart = useCallback(() => {
    journey.current = null
  }, [])

  return { formOpened, fillingStarted, journeyId, restart }
}
