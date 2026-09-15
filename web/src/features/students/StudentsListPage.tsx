import { useCallback, useEffect, useMemo, useState } from "react"
import { Link, Outlet } from "react-router-dom"
import { GraduationCap, Search } from "lucide-react"
import { useAuth } from "@/features/auth/AuthContext"
import { canManageStudents } from "@/lib/permissions"
import { Button } from "@/components/ui/button"
import { Card } from "@/components/ui/card"
import { EmptyState } from "@/components/ui/empty-state"
import { Input } from "@/components/ui/input"
import { PageHeader } from "@/components/ui/page-header"
import { RowLink } from "@/components/ui/row-link"
import { SingleSelect } from "@/components/ui/single-select"
import { SortableHead } from "@/components/ui/sortable-head"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { getCurrentSchoolYear } from "@/lib/schoolYear"
import { useSort } from "@/lib/useSort"
import * as studentsApi from "./studentsApi"
import type { Student } from "@/types"
import type { StudentFormOutletContext } from "./StudentFormPage"

const ALL_GROUPS = "all"

type SortKey = "name" | "group"

export function StudentsListPage() {
  const { user } = useAuth()
  const canManage = canManageStudents(user)
  const [students, setStudents] = useState<Student[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [query, setQuery] = useState("")
  const [groupFilter, setGroupFilter] = useState(ALL_GROUPS)

  const loadStudents = useCallback(() => {
    return studentsApi
      .fetchStudents()
      .then((data) => setStudents(data))
      .catch(() => setError("No pudimos cargar los alumnos."))
  }, [])

  useEffect(() => {
    loadStudents()
  }, [loadStudents])

  const currentYear = getCurrentSchoolYear()

  const groupForYear = useCallback(
    (student: Student) => student.groups.find((group) => group.school_year === currentYear),
    [currentYear],
  )

  const { sort, toggle, sortItems } = useSort<Student, SortKey>(
    {
      name: (student) => student.full_name,
      group: (student) => groupForYear(student)?.name ?? null,
    },
    { key: "name", direction: "asc" },
  )

  // Every current-year class present across the loaded students, for the filter.
  const groupOptions = useMemo(() => {
    if (!students) return []
    const names = new Set<string>()
    for (const student of students) {
      const group = groupForYear(student)
      if (group) names.add(group.name)
    }
    return [...names]
      .sort((a, b) => a.localeCompare(b, "es", { numeric: true, sensitivity: "base" }))
      .map((name) => ({ value: name, label: name }))
  }, [students, groupForYear])

  const filtered = useMemo(() => {
    if (!students) return null
    const term = query.trim().toLowerCase()
    const matched = students.filter((student) => {
      const matchesQuery = !term || student.full_name.toLowerCase().includes(term)
      const matchesGroup = groupFilter === ALL_GROUPS || groupForYear(student)?.name === groupFilter
      return matchesQuery && matchesGroup
    })
    return sortItems(matched)
  }, [students, query, groupFilter, groupForYear, sortItems])

  const hasStudents = students !== null && students.length > 0

  return (
    <div className="grid gap-6">
      <PageHeader title="Alumnos">
        {canManage && (
          <Button asChild>
            <Link to="/alumnos/nuevo">Nuevo alumno</Link>
          </Button>
        )}
      </PageHeader>

      {hasStudents && (
        <div className="flex flex-wrap items-center gap-3">
          <div className="relative max-w-xs flex-1">
            <Search
              className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
              aria-hidden="true"
            />
            <Input
              type="search"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder="Buscar por nombre…"
              aria-label="Buscar alumnos por nombre"
              className="pl-9"
            />
          </div>
          <div className="w-48">
            <SingleSelect
              options={[{ value: ALL_GROUPS, label: "Todas las clases" }, ...groupOptions]}
              value={groupFilter}
              onChange={setGroupFilter}
              placeholder="Filtrar por clase"
            />
          </div>
        </div>
      )}

      {error && <p className="text-sm text-destructive">{error}</p>}
      {!students && !error && <p className="text-muted-foreground">Cargando…</p>}
      {students && students.length === 0 && (
        <EmptyState icon={GraduationCap} message="Todavía no hay alumnos cargados." />
      )}
      {hasStudents && filtered && filtered.length === 0 && (
        <EmptyState icon={Search} message="Ningún alumno coincide con los filtros." />
      )}

      {filtered && filtered.length > 0 && (
        <Card className="overflow-hidden py-0">
          <Table>
            <TableHeader>
              <TableRow>
                <SortableHead
                  label="Nombre"
                  active={sort.key === "name"}
                  direction={sort.direction}
                  onSort={() => toggle("name")}
                  className="pl-6"
                />
                <SortableHead
                  label="Clase"
                  active={sort.key === "group"}
                  direction={sort.direction}
                  onSort={() => toggle("group")}
                />
                <TableHead className="pr-6" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {filtered.map((student) => {
                const currentGroup = groupForYear(student)
                return (
                  <TableRow key={student.id}>
                    <TableCell className="pl-6 font-medium">{student.full_name}</TableCell>
                    <TableCell>{currentGroup?.name ?? "—"}</TableCell>
                    <TableCell className="pr-6 text-right">
                      <div className="flex items-center justify-end gap-2">
                        <Button asChild size="sm" variant="outline">
                          <Link to={`/alumnos/${student.id}/seguimiento`}>Seguimiento</Link>
                        </Button>
                        {canManage && <RowLink to={`/alumnos/${student.id}`}>Editar</RowLink>}
                      </div>
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        </Card>
      )}

      <Outlet context={{ onSaved: loadStudents } satisfies StudentFormOutletContext} />
    </div>
  )
}
