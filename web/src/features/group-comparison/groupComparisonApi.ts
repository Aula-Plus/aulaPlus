import { api } from "@/lib/api"

/** A grade within a school year with two or more groups (screen 05). */
export interface ComparisonOption {
  school_grade_id: number
  school_grade: string | null
  school_year: number
  groups: { id: number; name: string }[]
}

export interface ComparedGroup {
  id: number
  name: string
  student_count: number
  subject_averages: { subject_id: number; subject_name: string; average: number; results_count: number }[]
  accommodations_by_type: { type: string; category: string | null; student_count: number }[]
  screenings: {
    screening_test_type_id: number
    name: string
    application_date: string | null
    bands: { name: string; count: number }[]
  }[]
}

export interface GroupComparison {
  school_grade: string | null
  school_year: number
  groups: ComparedGroup[]
}

export async function fetchComparisonOptions(): Promise<ComparisonOption[]> {
  const { data } = await api.get<{ data: ComparisonOption[] }>("/api/v1/group-comparison/options")
  return data.data
}

export async function fetchGroupComparison(schoolGradeId: number, schoolYear: number): Promise<GroupComparison> {
  const { data } = await api.get<{ data: GroupComparison }>("/api/v1/group-comparison", {
    params: { school_grade_id: schoolGradeId, school_year: schoolYear },
  })
  return data.data
}
