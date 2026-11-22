import { cn } from "@/lib/utils"
import type { CalendarItem } from "@/types"

import { calendarItemTopic } from "./calendarItemText"

/**
 * A class's topic next to its main text: discreet (small, muted) and cut with
 * an ellipsis when long; the full topic stays in the tooltip.
 */
export function CalendarItemTopic({ item, className }: { item: CalendarItem; className?: string }) {
  const topic = calendarItemTopic(item)
  if (!topic) return null
  return (
    <span
      data-testid="class-topic"
      title={topic}
      className={cn("ml-1.5 inline-block max-w-[16rem] truncate align-bottom text-xs font-normal text-muted-foreground", className)}
    >
      {topic}
    </span>
  )
}
