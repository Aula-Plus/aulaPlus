import { api } from "@/lib/api"
import type { Student, StudentStatus } from "@/types"

export interface StudentInput {
  first_name: string
  last_name: string
  birth_date?: string
  group_id?: number | null
  status?: StudentStatus
  family_contact_name?: string
  family_contact_phone?: string
  family_contact_email?: string
  pedagogical_notes?: string
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
