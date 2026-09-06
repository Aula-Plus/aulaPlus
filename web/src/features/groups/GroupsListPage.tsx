import { useEffect, useState } from "react"
import { Link } from "react-router-dom"
import { useAuth } from "@/features/auth/AuthContext"
import { Button } from "@/components/ui/button"
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
        <p className="text-muted-foreground">Todavía no hay clases cargadas.</p>
      )}

      {groups && groups.length > 0 && (
        <table className="w-full border-collapse text-sm">
          <thead>
            <tr className="border-b text-left text-muted-foreground">
              <th className="py-2 pr-4">Nombre</th>
              <th className="py-2 pr-4">Nivel</th>
              <th className="py-2 pr-4">Año</th>
              <th className="py-2 pr-4">Docente a cargo</th>
              {isDirector && <th className="py-2" />}
            </tr>
          </thead>
          <tbody>
            {groups.map((group) => (
              <tr key={group.id} className="border-b last:border-b-0">
                <td className="py-2 pr-4">{group.name}</td>
                <td className="py-2 pr-4">{group.level ?? "—"}</td>
                <td className="py-2 pr-4">{group.year ?? "—"}</td>
                <td className="py-2 pr-4">{group.teacher?.name ?? "—"}</td>
                {isDirector && (
                  <td className="py-2 text-right">
                    <Link
                      className="text-primary underline-offset-4 hover:underline"
                      to={`/clases/${group.id}`}
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
