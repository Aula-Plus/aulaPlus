import { useEffect, useState } from "react"
import { Link } from "react-router-dom"
import { useAuth } from "@/features/auth/AuthContext"
import { Button } from "@/components/ui/button"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import * as groupsApi from "./groupsApi"
import type { Group } from "@/types"

export function GroupsListPage() {
  const { user } = useAuth()
  const isDirector = user?.roles.includes("director") ?? false
  const [groups, setGroups] = useState<Group[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let active = true
    groupsApi
      .fetchGroups()
      .then((data) => {
        if (active) setGroups(data)
      })
      .catch(() => {
        if (active) setError("No pudimos cargar las clases.")
      })
    return () => {
      active = false
    }
  }, [])

  return (
    <div className="grid gap-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-semibold">Clases</h1>
        {isDirector && (
          <Button asChild>
            <Link to="/clases/nueva">Nueva clase</Link>
          </Button>
        )}
      </div>

      {error && <p className="text-sm text-destructive">{error}</p>}
      {!groups && !error && <p className="text-muted-foreground">Cargando…</p>}
      {groups && groups.length === 0 && (
        <p className="text-muted-foreground">Todavía no hay clases.</p>
      )}

      {groups && groups.length > 0 && (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Nombre</TableHead>
              <TableHead>Nivel</TableHead>
              <TableHead>Año</TableHead>
              <TableHead>Docentes</TableHead>
              {isDirector && <TableHead />}
            </TableRow>
          </TableHeader>
          <TableBody>
            {groups.map((group) => (
              <TableRow key={group.id}>
                <TableCell>{group.name}</TableCell>
                <TableCell>{group.level ?? "—"}</TableCell>
                <TableCell>{group.school_year}</TableCell>
                <TableCell>
                  {group.teachers.length > 0
                    ? group.teachers.map((teacher) => teacher.name).join(", ")
                    : "—"}
                </TableCell>
                {isDirector && (
                  <TableCell className="text-right">
                    <Link
                      className="text-primary underline-offset-4 hover:underline"
                      to={`/clases/${group.id}`}
                    >
                      Editar
                    </Link>
                  </TableCell>
                )}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </div>
  )
}
