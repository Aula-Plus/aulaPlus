import type { EventAudienceTarget } from "@/types"
import type { AudienceTargetInput } from "./calendarApi"

/**
 * «Secundaria» is Ciclo Básico + Bachillerato (the two secondary levels of
 * `education_levels`); the doc's «Primaria / Secundaria» map onto them.
 */
export const SECONDARY_LEVEL_KEYS = ["lower_secondary", "upper_secondary"] as const

/**
 * Who an institutional event concerns, in Spanish: «Todo el colegio», or its
 * targets joined — «Primaria · Secundaria · 6° de Primaria · Cambridge
 * Primary en 3° de Primaria».
 */
export function audienceLabel(audience: readonly EventAudienceTarget[] | undefined): string {
  if (!audience || audience.length === 0) return "Todo el colegio"

  const levelKeys = audience.flatMap((target) => (target.level && !target.grade ? [target.level.key] : []))
  const bothSecondary = SECONDARY_LEVEL_KEYS.every((key) => levelKeys.includes(key))
  const parts: string[] = []
  let secondaryWritten = false

  for (const target of audience) {
    if (target.grade) {
      parts.push(target.framework ? `${target.framework.name} en ${target.grade.label}` : target.grade.label)
    } else if (target.level) {
      if (bothSecondary && (SECONDARY_LEVEL_KEYS as readonly string[]).includes(target.level.key)) {
        if (!secondaryWritten) parts.push("Secundaria")
        secondaryWritten = true
      } else {
        parts.push(target.level.label)
      }
    }
  }
  return parts.join(" · ")
}

/** The stored targets back as the form sends them. */
export function audienceToInput(audience: readonly EventAudienceTarget[] | undefined): AudienceTargetInput[] {
  return (audience ?? []).flatMap((target): AudienceTargetInput[] => {
    if (target.grade) {
      return [target.framework ? { grade_id: target.grade.id, framework_id: target.framework.id } : { grade_id: target.grade.id }]
    }
    return target.level ? [{ level_id: target.level.id }] : []
  })
}
