import { assessmentTypeLabels, type AssessmentType } from "@/types"
import type { SubjectGradeRule, SubjectGrades } from "./indicatorsApi"
import { DEFAULT_GRADES, gradeRuleLabels, gradeRuleNeedsCategory } from "./indicatorText"

const RULES = Object.keys(gradeRuleLabels) as SubjectGradeRule[]
const CATEGORIES = Object.keys(assessmentTypeLabels) as AssessmentType[]

/**
 * Which of the year's grades a subject datum reads (PO answer of 17/11 on
 * ClickUp 1dfd3mc4bgp): every grade, every one but a category, one category,
 * or only the latest grade (of the year or of a category). The category is
 * the assessment type. In Primaria without the school's equivalence the
 * datum is the latest level of progress among those grades.
 */
export function GradeRuleFields({
  label,
  grades,
  onChange,
}: {
  /** Names the datum for the accessible labels, e.g. «dato 1». */
  label: string
  grades: SubjectGrades | undefined
  onChange: (grades: SubjectGrades) => void
}) {
  const current = grades ?? DEFAULT_GRADES
  const needsCategory = gradeRuleNeedsCategory(current.rule)

  return (
    <div className="flex flex-wrap items-center gap-2">
      <select
        aria-label={`Qué calificaciones usa el ${label}`}
        className="h-9 rounded-md border bg-background px-2 text-sm"
        value={current.rule}
        onChange={(event) => {
          const rule = event.target.value as SubjectGradeRule
          onChange({ rule, category: gradeRuleNeedsCategory(rule) ? (current.category ?? "written") : null })
        }}
      >
        {RULES.map((rule) => (
          <option key={rule} value={rule}>
            {gradeRuleLabels[rule]}
          </option>
        ))}
      </select>
      {needsCategory && (
        <select
          aria-label={`Categoría de calificación del ${label}`}
          className="h-9 rounded-md border bg-background px-2 text-sm"
          value={current.category ?? "written"}
          onChange={(event) => onChange({ ...current, category: event.target.value as AssessmentType })}
        >
          {CATEGORIES.map((category) => (
            <option key={category} value={category}>
              {assessmentTypeLabels[category]}
            </option>
          ))}
        </select>
      )}
    </div>
  )
}
