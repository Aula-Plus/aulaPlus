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
  full_name: string
  photo_url: string | null
  birth_date: string | null
  enrollment_year: number
  has_therapeutic_companion: boolean
  groups: { id: number; name: string; school_year: number }[]
  // Absent from the JSON (not null) when the viewer lacks
  // view-clinical-profile on this student.
  learning_profile?: unknown
  tracking_notes?: string
  individual_profile?: unknown
  related_documents?: unknown
}
