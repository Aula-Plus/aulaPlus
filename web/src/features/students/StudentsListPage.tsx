import { useEffect, useState } from "react"
import { Link } from "react-router-dom"
import { useAuth } from "@/features/auth/AuthContext"
import { Button } from "@/components/ui/button"
import { studentStatusLabels } from "@/types"
import * as studentsApi from "./studentsApi"
import type { Student } from "@/types"

export function StudentsListPage() {
  const { user } = useAuth()
  const isDirector = user?.roles.includes("director") ?? false
  const [students, setStudents] = useState<Student[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let active = true
    studentsApi
      .fetchStudents()
      .then((data) => {
        if (active) setStudents(data)
      })
      .catch(() => {
        if (active) setError("No pudimos cargar los alumnos.")
      })
    return () => {
      active = false
    }
  }, [])

  return (
    <div className="grid gap-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-semibold">Alumnos</h1>
        {isDirector && (
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
        <table className="w-full border-collapse text-sm">
          <thead>
            <tr className="border-b text-left text-muted-foreground">
              <th className="py-2 pr-4">Nombre</th>
              <th className="py-2 pr-4">Clase</th>
              <th className="py-2 pr-4">Estado</th>
              {isDirector && <th className="py-2" />}
            </tr>
          </thead>
          <tbody>
            {students.map((student) => (
              <tr key={student.id} className="border-b last:border-b-0">
                <td className="py-2 pr-4">{student.full_name}</td>
                <td className="py-2 pr-4">{student.group?.name ?? "—"}</td>
                <td className="py-2 pr-4">{studentStatusLabels[student.status]}</td>
                {isDirector && (
                  <td className="py-2 text-right">
                    <Link
                      className="text-primary underline-offset-4 hover:underline"
                      to={`/alumnos/${student.id}`}
                    >
                      Editar
                    </Link>
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}
