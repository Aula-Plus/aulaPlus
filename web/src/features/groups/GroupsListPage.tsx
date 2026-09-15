import { useCallback, useEffect, useMemo, useState } from "react"
import { Link, Outlet } from "react-router-dom"
import { Search, Users } from "lucide-react"
import { useAuth } from "@/features/auth/AuthContext"
import { canManageGroups, canManageScreeningTests } from "@/lib/permissions"
import { Button } from "@/components/ui/button"
import { EmptyState } from "@/components/ui/empty-state"
import { Input } from "@/components/ui/input"
import { PageHeader } from "@/components/ui/page-header"
import { Card } from "@/components/ui/card"
import { RowLink } from "@/components/ui/row-link"
import { SingleSelect } from "@/components/ui/single-select"
import { SortableHead } from "@/components/ui/sortable-head"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { useSort } from "@/lib/useSort"
import * as groupsApi from "./groupsApi"
import type { Group } from "@/types"
import type { GroupFormOutletContext } from "./GroupFormPage"

const ALL = "all"

type SortKey = "name" | "level" | "year" | "teachers" | "tracking"

export function GroupsListPage() {
  const { user } = useAuth()
  const canManage = canManageGroups(user)
  const canScreen = canManageScreeningTests(user)
  const [groups, setGroups] = useState<Group[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [query, setQuery] = useState("")
  const [levelFilter, setLevelFilter] = useState(ALL)
  const [yearFilter, setYearFilter] = useState(ALL)

  const loadGroups = useCallback(() => {
    return groupsApi
      .fetchGroups()
      .then((data) => setGroups(data))
      .catch(() => setError("No pudimos cargar las clases."))
  }, [])

  useEffect(() => {
    loadGroups()
  }, [loadGroups])

  const { sort, toggle, sortItems } = useSort<Group, SortKey>(
    {
      name: (group) => group.name,
      level: (group) => group.level,
      year: (group) => group.school_year,
      teachers: (group) => group.teachers.map((teacher) => teacher.name).join(", ") || null,
      tracking: (group) => group.active_tracking_count ?? 0,
    },
    { key: "name", direction: "asc" },
  )

  const levelOptions = useMemo(() => {
    if (!groups) return []
    const levels = new Set<string>()
    for (const group of groups) if (group.level) levels.add(group.level)
    return [...levels]
      .sort((a, b) => a.localeCompare(b, "es", { numeric: true, sensitivity: "base" }))
      .map((level) => ({ value: level, label: level }))
  }, [groups])

  const yearOptions = useMemo(() => {
    if (!groups) return []
    const years = new Set<number>()
    for (const group of groups) years.add(group.school_year)
    return [...years]
      .sort((a, b) => b - a)
      .map((year) => ({ value: String(year), label: String(year) }))
  }, [groups])

  const filtered = useMemo(() => {
    if (!groups) return null
    const term = query.trim().toLowerCase()
    const matched = groups.filter((group) => {
      const matchesQuery = !term || group.name.toLowerCase().includes(term)
      const matchesLevel = levelFilter === ALL || group.level === levelFilter
      const matchesYear = yearFilter === ALL || String(group.school_year) === yearFilter
      return matchesQuery && matchesLevel && matchesYear
    })
    return sortItems(matched)
  }, [groups, query, levelFilter, yearFilter, sortItems])

  const hasGroups = groups !== null && groups.length > 0

  return (
    <div className="grid gap-6">
      <PageHeader title="Clases">
        {canManage && (
          <Button asChild>
            <Link to="/clases/nueva">Nueva clase</Link>
          </Button>
        )}
      </PageHeader>

      {hasGroups && (
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
              aria-label="Buscar clases por nombre"
              className="pl-9"
            />
          </div>
          <div className="w-44">
            <SingleSelect
              options={[{ value: ALL, label: "Todos los niveles" }, ...levelOptions]}
              value={levelFilter}
              onChange={setLevelFilter}
              placeholder="Filtrar por nivel"
            />
          </div>
          <div className="w-36">
            <SingleSelect
              options={[{ value: ALL, label: "Todos los años" }, ...yearOptions]}
              value={yearFilter}
              onChange={setYearFilter}
              placeholder="Filtrar por año"
            />
          </div>
        </div>
      )}

      {error && <p className="text-sm text-destructive">{error}</p>}
      {!groups && !error && <p className="text-muted-foreground">Cargando…</p>}
      {groups && groups.length === 0 && (
        <EmptyState icon={Users} message="Todavía no hay clases." />
      )}
      {hasGroups && filtered && filtered.length === 0 && (
        <EmptyState icon={Search} message="Ninguna clase coincide con los filtros." />
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
                  label="Nivel"
                  active={sort.key === "level"}
                  direction={sort.direction}
                  onSort={() => toggle("level")}
                />
                <SortableHead
                  label="Año"
                  active={sort.key === "year"}
                  direction={sort.direction}
                  onSort={() => toggle("year")}
                />
                <SortableHead
                  label="Docentes"
                  active={sort.key === "teachers"}
                  direction={sort.direction}
                  onSort={() => toggle("teachers")}
                />
                <SortableHead
                  label="Seguimiento activo"
                  active={sort.key === "tracking"}
                  direction={sort.direction}
                  onSort={() => toggle("tracking")}
                />
                <TableHead className="pr-6" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {filtered.map((group) => (
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
