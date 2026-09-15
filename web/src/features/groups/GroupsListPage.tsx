import { useCallback, useEffect, useState } from "react"
import { Link, Outlet } from "react-router-dom"
import { Users } from "lucide-react"
import { useAuth } from "@/features/auth/AuthContext"
import { canManageGroups, canManageScreeningTests } from "@/lib/permissions"
import { Button } from "@/components/ui/button"
import { EmptyState } from "@/components/ui/empty-state"
import { PageHeader } from "@/components/ui/page-header"
import { Card } from "@/components/ui/card"
import { RowLink } from "@/components/ui/row-link"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import * as groupsApi from "./groupsApi"
import type { Group } from "@/types"
import type { GroupFormOutletContext } from "./GroupFormPage"

export function GroupsListPage() {
  const { user } = useAuth()
  const canManage = canManageGroups(user)
  const canScreen = canManageScreeningTests(user)
  const [groups, setGroups] = useState<Group[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  const loadGroups = useCallback(() => {
    return groupsApi
      .fetchGroups()
      .then((data) => setGroups(data))
      .catch(() => setError("No pudimos cargar las clases."))
  }, [])

  useEffect(() => {
    loadGroups()
  }, [loadGroups])

  return (
    <div className="grid gap-6">
      <PageHeader title="Clases">
        {canManage && (
          <Button asChild>
            <Link to="/clases/nueva">Nueva clase</Link>
          </Button>
        )}
      </PageHeader>

      {error && <p className="text-sm text-destructive">{error}</p>}
      {!groups && !error && <p className="text-muted-foreground">Cargando…</p>}
      {groups && groups.length === 0 && (
        <EmptyState icon={Users} message="Todavía no hay clases." />
      )}

      {groups && groups.length > 0 && (
        <Card className="overflow-hidden py-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-6">Nombre</TableHead>
                <TableHead>Nivel</TableHead>
                <TableHead>Año</TableHead>
                <TableHead>Docentes</TableHead>
                <TableHead>Seguimiento activo</TableHead>
                <TableHead className="pr-6" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {groups.map((group) => (
                <TableRow key={group.id}>
                  <TableCell className="pl-6 font-medium">{group.name}</TableCell>
                  <TableCell>{group.level ?? "—"}</TableCell>
                  <TableCell>{group.school_year}</TableCell>
                  <TableCell>
                    {group.teachers.length > 0
                      ? group.teachers.map((teacher) => teacher.name).join(", ")
                      : "—"}
                  </TableCell>
                  <TableCell>{group.active_tracking_count ?? 0}</TableCell>
                  <TableCell className="pr-6 text-right">
                    <div className="flex items-center justify-end gap-2">
                      <Button asChild size="sm" variant="outline">
                        <Link to={`/clases/${group.id}/seguimiento`}>Seguimiento</Link>
                      </Button>
                      <RowLink to={`/clases/${group.id}/evaluaciones`}>Evaluaciones</RowLink>
                      {canScreen && (
                        <RowLink to={`/clases/${group.id}/pruebas-de-sondeo`}>Sondeo</RowLink>
                      )}
                      {canManage && <RowLink to={`/clases/${group.id}`}>Editar</RowLink>}
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      )}

      <Outlet context={{ onSaved: loadGroups } satisfies GroupFormOutletContext} />
    </div>
  )
}
