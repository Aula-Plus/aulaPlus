import { useEffect, useState } from "react"
import { fetchGroups } from "@/features/groups/groupsApi"
import { fetchStudents } from "@/features/students/studentsApi"
import type { Group, MeetingSheetType, StaffMember, Student } from "@/types"
import { fetchMeetingSheetTypes, fetchStaff } from "./psychopedagogySpaceApi"

export interface SheetFormOptions {
  types: MeetingSheetType[]
  staff: StaffMember[]
  groups: Group[]
  students: Student[]
}

/** What a sheet form picks from: the school's types, people, groups and students. */
export function useSheetFormOptions(): SheetFormOptions | null {
  const [options, setOptions] = useState<SheetFormOptions | null>(null)

  useEffect(() => {
    let cancelled = false
    Promise.all([fetchMeetingSheetTypes(), fetchStaff(), fetchGroups(), fetchStudents()]).then(
      ([types, staff, groups, students]) => {
        if (!cancelled) setOptions({ types, staff, groups, students })
      },
      () => {
        if (!cancelled) setOptions({ types: [], staff: [], groups: [], students: [] })
      },
    )
    return () => {
      cancelled = true
    }
  }, [])

  return options
}

