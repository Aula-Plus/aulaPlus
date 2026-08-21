import { api } from "@/lib/api"
import type { Student } from "@/types"

export interface StudentInput {
  full_name: string
  photo_url?: string
  birth_date?: string
  enrollment_year: number
  has_therapeutic_companion?: boolean
  group_id?: number | null
  school_year?: number
  learning_profile?: unknown
  tracking_notes?: string
  individual_profile?: unknown
  related_documents?: unknown
}

export async function fetchStudents(): Promise<Student[]> {
  const { data } = await api.get<{ data: Student[] }>("/api/students")
  return data.data
}

export async function fetchStudent(id: number): Promise<Student> {
  const { data } = await api.get<{ data: Student }>(`/api/students/${id}`)
  return data.data
}

export async function createStudent(input: StudentInput): Promise<Student> {
  const { data } = await api.post<{ data: Student }>("/api/students", input)
  return data.data
}

export async function updateStudent(id: number, input: StudentInput): Promise<Student> {
  const { data } = await api.put<{ data: Student }>(`/api/students/${id}`, input)
  return data.data
}

export async function deleteStudent(id: number): Promise<void> {
  await api.delete(`/api/students/${id}`)
}
