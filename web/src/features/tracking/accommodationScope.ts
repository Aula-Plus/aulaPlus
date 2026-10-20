import type { AccommodationScope, AssessmentSummary, AssessmentType } from "@/types"

/**
 * Spanish phrasing for where and since when an accommodation applies (ClickUp
 * 86e3dpbwy follow-up, living document screen 04 · Ajustes), e.g.
 * «desde el 02/04 — en Idioma Español y Literatura».
 */

/** Plural nouns for "solo en …" (e.g. «solo en escritos y orales»). */
const assessmentTypeScopeLabels: Record<AssessmentType, string> = {
  written: "escritos",
  assignment: "tareas",
  project: "proyectos",
  oral: "orales",
  submission: "entregas",
}

/**
 * Spanish conjunction list: «A», «A y B», «A, B y C». The conjunction turns
 * into «e» before a word starting with the /i/ sound («Matemática e Inglés»),
 * but not before a diphthong («y hielo»).
 */
export function joinSpanishList(items: string[]): string {
  if (items.length <= 1) return items[0] ?? ""
  const last = items[items.length - 1]
  const conjunction = /^h?[iíIÍ](?![aeouáéóú])/i.test(last) ? "e" : "y"
  return `${items.slice(0, -1).join(", ")} ${conjunction} ${last}`
}

/**
 * `dd/mm`, adding the year only when it is not the current one, so an
 * accommodation in force since a previous year never reads as this year's.
 */
export function formatSinceDate(iso: string, today: Date = new Date()): string {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso)
  if (!match) return iso
  const [, year, month, day] = match
  return Number(year) === today.getFullYear() ? `${day}/${month}` : `${day}/${month}/${year}`
}

/** «en todas las materias» for a general accommodation, else «en A y B». */
export function describeSubjects(scope: Pick<AccommodationScope, "subjects">): string {
  return scope.subjects.length === 0
    ? "en todas las materias"
    : `en ${joinSpanishList(scope.subjects.map((subject) => subject.name))}`
}

/** «solo en escritos y orales», or null when it applies to every type. */
export function describeAssessmentTypes(
  scope: Pick<AccommodationScope, "assessment_types">,
): string | null {
  if (!scope.assessment_types || scope.assessment_types.length === 0) return null
  return `solo en ${joinSpanishList(scope.assessment_types.map((type) => assessmentTypeScopeLabels[type]))}`
}

/**
 * The scope line: «desde el 02/04 — en Idioma Español y Literatura», plus
 * «— solo en escritos» when restricted to some assessment types.
 */
export function describeAccommodationScope(scope: AccommodationScope, today: Date = new Date()): string {
  const parts = [
    scope.starts_on ? `desde el ${formatSinceDate(scope.starts_on, today)}` : null,
    describeSubjects(scope),
    describeAssessmentTypes(scope),
  ]
  return parts.filter((part): part is string => part !== null).join(" — ")
}

/**
 * Whether the accommodation applies to an assessment: general or scoped to its
 * subject, and covering its type (or not restricted by type). Mirror of
 * `Accommodation::appliesToAssessment()` — the server rejects (422) a
 * per-assessment deactivation that fails it, so the form only offers these.
 */
export function accommodationAppliesToAssessment(
  scope: Pick<AccommodationScope, "subjects" | "assessment_types">,
  assessment: Pick<AssessmentSummary, "subject_id" | "type">,
): boolean {
  const subjectApplies =
    scope.subjects.length === 0 ||
    scope.subjects.some((subject) => subject.id === assessment.subject_id)
  const typeApplies =
    !scope.assessment_types ||
    scope.assessment_types.length === 0 ||
    scope.assessment_types.includes(assessment.type)
  return subjectApplies && typeApplies
}
