import { api } from "@/lib/api"
import type { AssessmentType, ScreeningColor } from "@/types"

/**
 * Screen 30 «Indicadores y cruces» (ClickUp 1dfd3mc4bgp). Mirrors of the
 * `/api/v1/indicator-data`, `/indicators`, `/crossings`, `/external-measures`
 * and `/indicator-chart` endpoints. English identifiers; Spanish labels live
 * in `indicatorText.ts`.
 */

export type SourceType = "screening" | "subject" | "external" | "indicator" | "assessment"
export type IndicatorCalculation = "raw" | "average" | "z_score" | "difference"
export type ZScorePopulation = "grade_year" | "group" | "school"
export type IndicatorDisplayPlace =
  | "profile_bar"
  | "group_band_bars"
  | "cross_chart"
  | "profile_bell"
  | "profile_timeline"
  | "profile_band"
  | "group_points"
export type CrossingOperator = "band" | "level" | "below" | "above"

/** Mirror of `App\Enums\SubjectGradeRule`: which of the year's grades a subject datum reads. */
export type SubjectGradeRule = "all" | "all_except_category" | "category_average" | "latest" | "latest_of_category"

/** The category is an assessment type (Escrita, Oral…). */
export interface SubjectGrades {
  rule: SubjectGradeRule
  category: AssessmentType | null
}

export interface Scale {
  min: number
  max: number
}

export interface Band {
  name: string
  color: ScreeningColor
  max: number | null
}

export interface IndicatorBand extends Band {
  meaning: string
}

export interface ScaleLevel {
  id: number
  name: string
  position: number
}

/** One datum of «Datos disponibles» (P30-DAT-02). Aggregate: no student here. */
export interface DataSource {
  type: Exclude<SourceType, "assessment">
  id: number
  name: string
  scale: Scale | null
  coverage: number
  number_coverage: number
  level_coverage: number
  latest_year: number | null
  bands: Band[]
  levels: ScaleLevel[]
  taken_every_year?: boolean
  in_cross_chart?: boolean
}

export interface AssessmentOption {
  id: number
  type: string | null
  administered_at: string | null
  group_name: string
  subject_name: string | null
  levels: ScaleLevel[]
}

export interface IndicatorDataCatalog {
  school_year: number
  roster_count: number
  groups: { id: number; name: string }[]
  sources: DataSource[]
  assessments: AssessmentOption[]
}

export interface SourceRef {
  type: SourceType
  id: number
  /** Only a subject: which grades it reads (every grade when absent). */
  grades?: SubjectGrades
}

export interface Population {
  kind: ZScorePopulation
  label: string | null
  size: number
}

export interface IndicatorSummary {
  roster_count: number
  students_with_data: number
  bands: { name: string; color: ScreeningColor; count: number }[]
  populations: Population[]
}

export interface Indicator {
  id: number
  name: string
  calculation: IndicatorCalculation
  inputs: (SourceRef & { name: string | null })[]
  base_100: boolean
  z_population: ZScorePopulation | null
  bands: IndicatorBand[]
  display_places: IndicatorDisplayPlace[]
  teacher_visible: boolean
  summary: IndicatorSummary
  updated_at: string | null
}

export interface IndicatorInput {
  name: string
  calculation: IndicatorCalculation
  inputs: SourceRef[]
  base_100: boolean
  z_population: ZScorePopulation | null
  bands: IndicatorBand[]
  display_places: IndicatorDisplayPlace[]
  teacher_visible: boolean
}

export interface CrossingCondition {
  source: SourceRef
  operator: CrossingOperator
  value: string | number
}

export interface CrossingPreview {
  total: number
  groups: { group_id: number; group_name: string; count: number }[]
}

export interface Crossing {
  id: number
  name: string
  reading: string
  conditions: (CrossingCondition & { source: SourceRef & { name: string | null } })[]
  preview: CrossingPreview
  updated_at: string | null
}

export interface CrossingInput {
  name: string
  reading: string
  conditions: CrossingCondition[]
}

export interface ExternalMeasure {
  id: number
  name: string
  scale_min: number
  scale_max: number
  taken_every_year: boolean
}

export interface ExternalMeasureRoster {
  measure: ExternalMeasure
  school_year: number
  rows: { student_id: number; name: string; group_name: string; value: number | null }[]
}

/** Who a CSV row is (`POST /external-measures/{id}/match-students`). */
export interface TemplateRowIdentity {
  student_id: number | null
  name: string | null
  group: string | null
}

export interface StudentCandidate {
  student_id: number
  name: string
  group_name: string
}

export interface StudentMatch {
  status: "matched" | "ambiguous" | "unknown"
  student_id: number | null
  candidates: StudentCandidate[]
}

export interface ChartAxis {
  type: SourceType
  id: number
  name: string | null
  kind: "number" | "level"
  scale: Scale | null
  cuts: number[]
  bands: Band[]
  levels: ScaleLevel[]
}

export interface ChartPoint {
  name: string
  group_name: string
  x: number
  x_label: string | null
  x_population: Population | null
  y: number
  y_label: string | null
  y_population: Population | null
}

export interface ChartData {
  x: ChartAxis
  y: ChartAxis
  points: ChartPoint[]
}

export async function fetchIndicatorData(): Promise<IndicatorDataCatalog> {
  const { data } = await api.get<{ data: IndicatorDataCatalog }>("/api/v1/indicator-data")
  return data.data
}

export async function fetchIndicators(): Promise<Indicator[]> {
  const { data } = await api.get<{ data: Indicator[] }>("/api/v1/indicators")
  return data.data
}

export async function saveIndicator(input: IndicatorInput, id?: number): Promise<Indicator> {
  const { data } = id
    ? await api.patch<{ data: Indicator }>(`/api/v1/indicators/${id}`, input)
    : await api.post<{ data: Indicator }>("/api/v1/indicators", input)
  return data.data
}

export async function previewIndicator(input: IndicatorInput): Promise<IndicatorSummary> {
  const { data } = await api.post<{ data: IndicatorSummary }>("/api/v1/indicators/preview", input)
  return data.data
}

export async function deleteIndicator(id: number): Promise<void> {
  await api.delete(`/api/v1/indicators/${id}`)
}

export async function fetchCrossings(): Promise<Crossing[]> {
  const { data } = await api.get<{ data: Crossing[] }>("/api/v1/crossings")
  return data.data
}

export async function saveCrossing(input: CrossingInput, id?: number): Promise<Crossing> {
  const { data } = id
    ? await api.patch<{ data: Crossing }>(`/api/v1/crossings/${id}`, input)
    : await api.post<{ data: Crossing }>("/api/v1/crossings", input)
  return data.data
}

export async function previewCrossing(conditions: CrossingCondition[]): Promise<CrossingPreview> {
  const { data } = await api.post<{ data: CrossingPreview }>("/api/v1/crossings/preview", { conditions })
  return data.data
}

export async function deleteCrossing(id: number): Promise<void> {
  await api.delete(`/api/v1/crossings/${id}`)
}

export async function createExternalMeasure(input: Omit<ExternalMeasure, "id">): Promise<ExternalMeasure> {
  const { data } = await api.post<{ data: ExternalMeasure }>("/api/v1/external-measures", input)
  return data.data
}

export async function fetchExternalMeasureRoster(id: number, groupId?: number): Promise<ExternalMeasureRoster> {
  const { data } = await api.get<{ data: ExternalMeasureRoster }>(`/api/v1/external-measures/${id}/values`, {
    params: groupId ? { group_id: groupId } : {},
  })
  return data.data
}

export async function saveExternalMeasureValues(
  id: number,
  values: { student_id: number; value: number | null }[],
): Promise<{ saved: number; cleared: number; skipped: number; school_year: number }> {
  const { data } = await api.put<{ data: { saved: number; cleared: number; skipped: number; school_year: number } }>(
    `/api/v1/external-measures/${id}/values`,
    { values },
  )
  return data.data
}

export async function matchTemplateStudents(id: number, rows: TemplateRowIdentity[]): Promise<StudentMatch[]> {
  const { data } = await api.post<{ data: { rows: StudentMatch[] } }>(`/api/v1/external-measures/${id}/match-students`, {
    rows,
  })
  return data.data.rows
}

export async function fetchChart(x: SourceRef, y: SourceRef, groupId?: number): Promise<ChartData> {
  const { data } = await api.get<{ data: ChartData }>("/api/v1/indicator-chart", {
    params: { x_type: x.type, x_id: x.id, y_type: y.type, y_id: y.id, ...(groupId ? { group_id: groupId } : {}) },
  })
  return data.data
}
