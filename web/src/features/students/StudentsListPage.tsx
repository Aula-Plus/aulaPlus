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
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { getCurrentSchoolYear } from "@/lib/schoolYear"
import * as studentsApi from "./studentsApi"
import type { Student } from "@/types"
import type { StudentFormOutletContext } from "./StudentFormPage"

export function StudentsListPage() {
  const { user } = useAuth()
  const canManage = canManageStudents(user)
  const [students, setStudents] = useState<Student[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [query, setQuery] = useState("")

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

  const filtered = useMemo(() => {
    if (!students) return null
    const term = query.trim().toLowerCase()
    if (!term) return students
    return students.filter((student) => student.full_name.toLowerCase().includes(term))
  }, [students, query])

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
        <div className="relative max-w-xs">
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
      )}

      {error && <p className="text-sm text-destructive">{error}</p>}
      {!students && !error && <p className="text-muted-foreground">Cargando…</p>}
      {students && students.length === 0 && (
        <EmptyState icon={GraduationCap} message="Todavía no hay alumnos cargados." />
      )}
      {hasStudents && filtered && filtered.length === 0 && (
        <EmptyState icon={Search} message={`Ningún alumno coincide con “${query.trim()}”.`} />
      )}

      {filtered && filtered.length > 0 && (
        <Card className="overflow-hidden py-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-6">Nombre</TableHead>
                <TableHead>Clase</TableHead>
                <TableHead className="pr-6" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {filtered.map((student) => {
                const currentGroup = student.groups.find(
                  (group) => group.school_year === currentYear,
                )
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
