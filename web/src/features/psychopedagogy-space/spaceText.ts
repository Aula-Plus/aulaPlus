import { weekdayNameLabels, WEEKDAYS, type ObservationScope, type TherapeuticCompanion, type Weekday } from "@/types"

/** «Martes y jueves», «Lunes, miércoles y viernes» — in week order. */
export function formatDays(days: Weekday[]): string {
  const labels = WEEKDAYS.filter((day) => days.includes(day)).map((day, index) =>
    index === 0 ? weekdayNameLabels[day] : weekdayNameLabels[day].toLowerCase(),
  )
  if (labels.length <= 1) return labels.join("")
  return `${labels.slice(0, -1).join(", ")} y ${labels[labels.length - 1]}`
}

/** «6 h», «4,5 h». */
export function formatHours(hours: number): string {
  return `${String(hours).replace(".", ",")} h`
}

/** «Romina Silva · 6 h semanales · Martes y jueves» (never the reason). */
export function companionSummary(companion: TherapeuticCompanion): string {
  return `${companion.name} · ${formatHours(companion.weekly_hours)} semanales · ${formatDays(companion.days)}`
}

export const observationScopeLabels: Record<ObservationScope, string> = {
  everyone: "Todos los que ven al alumno",
  direction_and_psychopedagogy: "Dirección y Psicopedagogía",
  psychopedagogue: "Solo Psicopedagogía",
  author_only: "Solo quien escribe",
}

export const OBSERVATION_SCOPES: ObservationScope[] = [
  "psychopedagogue",
  "direction_and_psychopedagogy",
  "everyone",
  "author_only",
]
