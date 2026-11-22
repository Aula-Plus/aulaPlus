import type { EventAudienceGroupOption, EventAudienceTarget } from "@/types"
import type { AudienceTargetInput } from "./calendarApi"

/**
 * «Secundaria» is Ciclo Básico + Bachillerato (the two secondary levels of
 * `education_levels`); the doc's «Primaria / Secundaria» map onto them.
 */
export const SECONDARY_LEVEL_KEYS = ["lower_secondary", "upper_secondary"] as const

/**
 * «3° de Primaria: 3° A, 3° B» — a generation or program target with the
 * groups it reaches, by the name the school gave them (Eitán, ClickUp
 * 1dfd3mc55zp). Without groups, just the label.
 */
export function withGroupNames(label: string, groups: readonly { name: string }[]): string {
  return groups.length > 0 ? `${label}: ${groups.map((group) => group.name).join(", ")}` : label
}

/** What reads for a deleted group's target, which reaches nobody. */
export const REMOVED_GROUP_LABEL = "Un grupo que ya no existe"

/**
 * Who an institutional event concerns, in Spanish: «Todo el colegio», or its
 * targets joined — «Primaria · Secundaria · 6° de Primaria: 6° A, 6° B ·
 * Cambridge Primary en 3° de Primaria: 3° A · 4° B».
 */
export function audienceLabel(audience: readonly EventAudienceTarget[] | undefined): string {
  if (!audience || audience.length === 0) return "Todo el colegio"

  const levelKeys = audience.flatMap((target) => (target.level && !target.grade ? [target.level.key] : []))
  const bothSecondary = SECONDARY_LEVEL_KEYS.every((key) => levelKeys.includes(key))
  const parts: string[] = []
  let secondaryWritten = false

  for (const target of audience) {
    if (target.group) {
      parts.push(target.group.name)
    } else if (target.grade) {
      const label = target.framework ? `${target.framework.name} en ${target.grade.label}` : target.grade.label
      parts.push(withGroupNames(label, target.groups ?? []))
    } else if (target.level) {
      if (bothSecondary && (SECONDARY_LEVEL_KEYS as readonly string[]).includes(target.level.key)) {
        if (!secondaryWritten) parts.push("Secundaria")
        secondaryWritten = true
      } else {
        parts.push(target.level.label)
      }
    } else {
      parts.push(REMOVED_GROUP_LABEL)
    }
  }
  return parts.join(" · ")
}

/** The stored targets back as the form sends them. */
export function audienceToInput(audience: readonly EventAudienceTarget[] | undefined): AudienceTargetInput[] {
  return (audience ?? []).flatMap((target): AudienceTargetInput[] => {
    if (target.group) return [{ group_id: target.group.id }]
    if (target.grade) {
      return [target.framework ? { grade_id: target.grade.id, framework_id: target.framework.id } : { grade_id: target.grade.id }]
    }
    return target.level ? [{ level_id: target.level.id }] : []
  })
}

/**
 * The groups of a school year a generation or program target reaches, as the
 * server matches them (EventAudience::matches): same grade and, for a
 * program, following it in some subject of their «Oferta curricular».
 */
export function groupsOfTarget(
  target: AudienceTargetInput,
  groups: readonly EventAudienceGroupOption[],
  year: number,
): EventAudienceGroupOption[] {
  if (target.grade_id === undefined) return []
  return groups.filter(
    (group) =>
      group.school_year === year &&
      group.grade_id === target.grade_id &&
      (target.framework_id === undefined || group.framework_ids.includes(target.framework_id)),
  )
}
