import { useCallback, useEffect, useMemo, useState } from "react"
import { Link, Outlet } from "react-router-dom"
import { Search, Users } from "lucide-react"
import { useAuth } from "@/features/auth/AuthContext"
import { canManageGroups, canManageScreeningTests, canRecalculateScreeningTestApplication, isDirector, isSchoolWideStaff } from "@/lib/permissions"
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
import type { EducationLevelOption, Group, GroupLevelSetting } from "@/types"
import type { GroupFormOutletContext } from "./GroupFormPage"
import { GroupLevelDialog } from "./GroupLevelDialog"

const ALL = "all"
const NO_LEVEL = "none"

type SortKey = "name" | "level" | "year" | "teachers" | "tracking"

export function GroupsListPage() {
  const { user } = useAuth()
  const canManage = canManageGroups(user)
  // Dirección reaches the applications too, to «Recalcular con el nivel actual».
  const canScreen = canManageScreeningTests(user) || canRecalculateScreeningTestApplication(user)
  const [groups, setGroups] = useState<Group[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [query, setQuery] = useState("")
  const [levelFilter, setLevelFilter] = useState(ALL)
  const [yearFilter, setYearFilter] = useState(ALL)
  const [levels, setLevels] = useState<EducationLevelOption[]>([])
  const [levelSetting, setLevelSetting] = useState<GroupLevelSetting | null>(null)
  const [settingError, setSettingError] = useState<string | null>(null)
  const [levelGroup, setLevelGroup] = useState<Group | null>(null)
  const schoolWide = isSchoolWideStaff(user)
  // Direction edits the level in the group form; psychopedagogy gets its own
  // "Elegir nivel" only when direction allowed it (the API checks it anyway).
  const canSetLevelOnly = !canManage && (levelSetting?.can_set_level ?? false)

  const loadGroups = useCallback(() => {
    return groupsApi
      .fetchGroups()
      .then((data) => setGroups(data))
      .catch(() => setError("No pudimos cargar los grupos."))
  }, [])

  useEffect(() => {
    loadGroups()
  }, [loadGroups])

  useEffect(() => {
    if (!schoolWide) return
    groupsApi
      .fetchGroupLevelSetting()
      .then(setLevelSetting)
      .catch(() => setLevelSetting(null))
    groupsApi
      .fetchEducationLevels()
      .then((data) => setLevels(data.data))
      .catch(() => setLevels([]))
  }, [schoolWide])

  async function toggleDelegation(enabled: boolean) {
    setSettingError(null)
    try {
      setLevelSetting(await groupsApi.updateGroupLevelSetting(enabled))
    } catch {
      setSettingError("No pudimos guardar el cambio.")
    }
  }

  const { sort, toggle, sortItems } = useSort<Group, SortKey>(
    {
      name: (group) => group.name,
      level: (group) => group.education_level?.label ?? null,
      year: (group) => group.school_year,
      teachers: (group) => group.teachers.map((teacher) => teacher.name).join(", ") || null,
      tracking: (group) => group.active_tracking_count ?? 0,
    },
    { key: "name", direction: "asc" },
  )

  const levelOptions = useMemo(() => {
    if (!groups) return []
    const present = new Map<string, { label: string; position: number }>()
    for (const group of groups) {
      if (group.education_level) {
        present.set(group.education_level.key, {
          label: group.education_level.label,
          position: levels.findIndex((level) => level.key === group.education_level?.key),
        })
      }
    }
    const options = [...present.entries()]
      .sort(([, a], [, b]) => a.position - b.position || a.label.localeCompare(b.label, "es"))
      .map(([key, { label }]) => ({ value: key, label }))
    if (groups.some((group) => !group.education_level)) options.push({ value: NO_LEVEL, label: "Sin nivel" })
    return options
  }, [groups, levels])

  const withoutLevel = groups?.filter((group) => !group.education_level).length ?? 0

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
      const matchesLevel =
        levelFilter === ALL ||
        (levelFilter === NO_LEVEL ? !group.education_level : group.education_level?.key === levelFilter)
      const matchesYear = yearFilter === ALL || String(group.school_year) === yearFilter
      return matchesQuery && matchesLevel && matchesYear
    })
    return sortItems(matched)
  }, [groups, query, levelFilter, yearFilter, sortItems])

  const hasGroups = groups !== null && groups.length > 0

  return (
    <div className="grid gap-6">
      <PageHeader title="Grupos">
        {canManage && (
          <Button asChild>
            <Link to="/clases/nueva">Nuevo grupo</Link>
          </Button>
        )}
      </PageHeader>

      {schoolWide && withoutLevel > 0 && (
        <p role="note" className="rounded-md bg-muted p-3 text-sm">
          {withoutLevel === 1
            ? "1 grupo sin nivel: no cuenta en la vista de impacto de ningún screening hasta que se le elija uno."
            : `${withoutLevel} grupos sin nivel: no cuentan en la vista de impacto de ningún screening hasta que se les elija uno.`}
        </p>
      )}

      {isDirector(user) && levelSetting && (
        <div className="grid gap-1">
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={levelSetting.psychopedagogue_can_set_group_level}
              onChange={(event) => toggleDelegation(event.target.checked)}
            />
            Psicopedagogía también puede elegir el nivel de los grupos
          </label>
          {settingError && <p className="text-sm text-destructive">{settingError}</p>}
        </div>
      )}

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
              aria-label="Buscar grupos por nombre"
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
        <EmptyState icon={Users} message="Todavía no hay grupos." />
      )}
      {hasGroups && filtered && filtered.length === 0 && (
        <EmptyState icon={Search} message="Ningún grupo coincide con los filtros." />
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
                  <TableCell>
                    {group.education_level ? (
                      group.education_level.label
                    ) : (
                      <span className="text-muted-foreground">Sin nivel</span>
                    )}
                    {group.school_grade && (
                      <span className="text-muted-foreground"> · {group.school_grade.label}</span>
                    )}
                  </TableCell>
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
                        <Link to={`/clases/${group.id}/seguimiento`}>Ver grupo</Link>
                      </Button>
                      <RowLink to={`/clases/${group.id}/evaluaciones`}>Evaluaciones</RowLink>
                      {canScreen && (
                        <RowLink to={`/clases/${group.id}/pruebas-de-sondeo`}>Screenings</RowLink>
                      )}
                      {canManage && <RowLink to={`/clases/${group.id}`}>Editar</RowLink>}
                      {canSetLevelOnly && (
                        <Button type="button" size="sm" variant="ghost" onClick={() => setLevelGroup(group)}>
                          Elegir nivel
                        </Button>
                      )}
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      )}

      {levelGroup && (
        <GroupLevelDialog
          group={levelGroup}
          levels={levels}
          onClose={() => setLevelGroup(null)}
          onSaved={() => {
            setLevelGroup(null)
            loadGroups()
          }}
        />
      )}

      <Outlet context={{ onSaved: loadGroups } satisfies GroupFormOutletContext} />
    </div>
  )
}
