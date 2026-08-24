import { useCallback, useEffect, useState } from "react"
import { Link, Outlet } from "react-router-dom"
import { useAuth } from "@/features/auth/AuthContext"
import { canCreateStudent, canEditStudent } from "@/lib/permissions"
import { Button } from "@/components/ui/button"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { getCurrentSchoolYear } from "@/lib/schoolYear"
import * as studentsApi from "./studentsApi"
import type { Student } from "@/types"
import type { StudentFormOutletContext } from "./StudentFormPage"

export function StudentsListPage() {
  const { user } = useAuth()
  const showCreate = canCreateStudent(user)
  const showEdit = canEditStudent(user)
  const [students, setStudents] = useState<Student[] | null>(null)
  const [error, setError] = useState<string | null>(null)

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

  return (
    <div className="grid gap-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-semibold">Alumnos</h1>
        {showCreate && (
          <Button asChild>
            <Link to="/alumnos/nuevo">Nuevo alumno</Link>
          </Button>
        )}
      </div>

      {error && <p className="text-sm text-destructive">{error}</p>}
      {!students && !error && <p className="text-muted-foreground">Cargando…</p>}
      {students && students.length === 0 && (
        <p className="text-muted-foreground">Todavía no hay alumnos cargados.</p>
      )}

      {students && students.length > 0 && (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Nombre</TableHead>
              <TableHead>Clase</TableHead>
              <TableHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            {students.map((student) => {
              const currentGroup = student.groups.find(
                (group) => group.school_year === currentYear,
              )
              return (
                <TableRow key={student.id}>
                  <TableCell>{student.full_name}</TableCell>
                  <TableCell>{currentGroup?.name ?? "—"}</TableCell>
                  <TableCell className="text-right">
                    <div className="flex justify-end gap-4">
                      <Link
                        className="text-primary underline-offset-4 hover:underline"
                        to={`/alumnos/${student.id}/seguimiento`}
                      >
                        Seguimiento
                      </Link>
                      {showEdit && (
                        <Link
                          className="text-primary underline-offset-4 hover:underline"
                          to={`/alumnos/${student.id}`}
                        >
                          Editar
                        </Link>
                      )}
                    </div>
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      )}

      <Outlet context={{ onSaved: loadStudents } satisfies StudentFormOutletContext} />
    </div>
  )
}
