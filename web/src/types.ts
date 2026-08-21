export type Role = "teacher" | "director" | "psychopedagogue"

/** User-facing Spanish labels for roles (the app UI is in Spanish). */
export const roleLabels: Record<Role, string> = {
  teacher: "Docente",
  director: "Director",
  psychopedagogue: "Psicopedagogo",
}

export interface School {
  id: number
  name: string
}

export interface User {
  id: number
  name: string
  email: string
  roles: Role[]
  school?: School
}

export type StudentStatus = "active" | "inactive"

export const studentStatusLabels: Record<StudentStatus, string> = {
  active: "Activo",
  inactive: "Inactivo",
}

export interface Group {
  id: number
  name: string
  level: string | null
  school_year: number
  group_profile: unknown | null
  related_documents: unknown | null
  teachers: { id: number; name: string }[]
}

export interface Student {
  id: number
  first_name: string
  last_name: string
  full_name: string
  birth_date: string | null
  status: StudentStatus
  family_contact_name: string | null
  family_contact_phone: string | null
  family_contact_email: string | null
  pedagogical_notes: string | null
  group_id: number | null
  group: { id: number; name: string } | null
}
