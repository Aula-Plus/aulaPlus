import { assessmentTypeLabels, calendarItemTypeLabels, type CalendarItem } from "@/types"

/**
 * «Qué» of a calendar date: the subject of a class (P01-HOY-01 «Matemática»),
 * the purpose of an assessment, the title of a follow-up or event — with a
 * generic Spanish label when the record has none.
 */
export function calendarItemWhat(item: CalendarItem): string {
  if (item.title) return item.title
  if (item.type === "assessment" && item.assessment_type) {
    return `Evaluación ${assessmentTypeLabels[item.assessment_type].toLowerCase()}`
  }
  if (item.type === "task") return "Seguimiento"
  return calendarItemTypeLabels[item.type]
}

/** «08:30» or «08:30–09:15»; empty for a date without a time. */
export function calendarItemHour(item: CalendarItem): string {
  if (!item.time) return ""
  return item.end_time ? `${item.time}–${item.end_time}` : item.time
}

/**
 * The screen a date leads to («cada una lleva a donde se resuelve», screen
 * 21), or null when it is resolved right in the Calendar.
 */
export function calendarItemLink(item: CalendarItem): string | null {
  if (item.type === "assessment" && item.group) return `/clases/${item.group.id}/evaluaciones`
  if (item.type === "task") return "/alertas"
  return null
}
