import { useEffect, useMemo, useState } from "react"
import { Link } from "react-router-dom"
import { Columns2 } from "lucide-react"
import { Button } from "@/components/ui/button"
import { EmptyState } from "@/components/ui/empty-state"
import { PageHeader } from "@/components/ui/page-header"
import { SectionCard } from "@/components/ui/section-card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { useAuth } from "@/features/auth/AuthContext"
import { canCompareGroups } from "@/lib/permissions"
import { NoAccessPage } from "@/pages/NoAccessPage"
import {
  fetchComparisonOptions,
  fetchGroupComparison,
  type ComparedGroup,
  type ComparisonOption,
  type GroupComparison,
} from "./groupComparisonApi"

type Variable = "students" | "averages" | "accommodations" | "screenings"

const variableLabels: Record<Variable, string> = {
  students: "Cantidad de alumnos",
  averages: "Promedio por materia",
  accommodations: "Ajustes vigentes, por tipo",
  screenings: "Screenings",
}

const ALL_VARIABLES = Object.keys(variableLabels) as Variable[]

const optionKey = (option: Pick<ComparisonOption, "school_grade_id" | "school_year">) =>
  `${option.school_grade_id}-${option.school_year}`

const formatAverage = (value: number) => value.toLocaleString("es-UY", { maximumFractionDigits: 1 })

interface Row {
  key: string
  label: string
  cell: (group: ComparedGroup) => string
}

/**
 * Screen 05 «Comparativa por año de cursada» (ClickUp 1dfd3mc4bgt): the groups
 * of one grade side by side, with the variables the viewer turns on
 * (P05-DEC-06). Psicopedagogía and Dirección only (P05-DEC-04); UX gate here,
 * the server decides (GroupPolicy::compare).
 *
 * Red line: every value is of the whole group, no student is named, and the
 * groups keep their name order — never ranked (P05-DEC-01/02). The screen
 * shows the numbers and writes no hypothesis (P05-DEC-05).
 */
export function GroupComparisonPage() {
  const { user } = useAuth()
  if (!canCompareGroups(user)) return <NoAccessPage />
  return <GroupComparisonScreen />
}

function GroupComparisonScreen() {
  const [options, setOptions] = useState<ComparisonOption[] | null>(null)
  const [optionsError, setOptionsError] = useState<string | null>(null)
  const [selected, setSelected] = useState<string>("")
  const [comparison, setComparison] = useState<GroupComparison | null>(null)
  const [comparisonError, setComparisonError] = useState<string | null>(null)
  const [variables, setVariables] = useState<Set<Variable>>(() => new Set(ALL_VARIABLES))

  useEffect(() => {
    fetchComparisonOptions().then(
      (list) => {
        setOptions(list)
        if (list.length > 0) setSelected(optionKey(list[0]))
      },
      () => setOptionsError("No pudimos cargar los años de cursada."),
    )
  }, [])

  const option = options?.find((candidate) => optionKey(candidate) === selected) ?? null

  useEffect(() => {
    if (!option) return
    let cancelled = false
    fetchGroupComparison(option.school_grade_id, option.school_year).then(
      (data) => {
        if (cancelled) return
        setComparisonError(null)
        setComparison(data)
      },
      () => {
        if (!cancelled) setComparisonError("No pudimos cargar la comparación.")
      },
    )
    return () => {
      cancelled = true
    }
  }, [option])

  function changeYear(next: string) {
    setComparison(null)
    setSelected(next)
  }

  function toggle(variable: Variable) {
    setVariables((current) => {
      const next = new Set(current)
      if (next.has(variable)) next.delete(variable)
      else next.add(variable)
      return next
    })
  }

  const rows = useMemo(() => (comparison ? buildRows(comparison.groups, variables) : []), [comparison, variables])

  return (
    <div className="grid gap-6">
      <PageHeader
        title="Comparativa por año de cursada"
        description="Los grupos de un mismo año, uno al lado del otro. Todo es del grupo entero: ningún alumno aparece nombrado."
      />

      <SectionCard title="Comparar">
        {optionsError ? (
          <p className="text-sm text-destructive">{optionsError}</p>
        ) : options === null ? (
          <p className="text-sm text-muted-foreground">Cargando años de cursada…</p>
        ) : options.length === 0 ? (
          <EmptyState
            icon={Columns2}
            message="Todavía no hay un año de cursada con dos o más grupos. Se compara cuando un año tiene más de un grupo con su grado cargado."
          />
        ) : (
          <div className="grid gap-4">
            <label className="grid max-w-xs gap-1 text-sm font-medium">
              Año de cursada
              <select
                className="h-9 rounded-md border bg-background px-2 text-sm"
                value={selected}
                onChange={(event) => changeYear(event.target.value)}
              >
                {options.map((candidate) => (
                  <option key={optionKey(candidate)} value={optionKey(candidate)}>
                    {candidate.school_grade ?? "Sin grado"} {candidate.school_year} —{" "}
                    {candidate.groups.map((group) => group.name).join(" y ")}
                  </option>
                ))}
              </select>
            </label>
            <div className="grid gap-2">
              <span className="text-sm font-medium">Qué variables mostrar</span>
              <div role="group" aria-label="Qué variables mostrar" className="flex flex-wrap gap-2">
                {ALL_VARIABLES.map((variable) => (
                  <Button
                    key={variable}
                    type="button"
                    size="sm"
                    variant={variables.has(variable) ? "default" : "outline"}
                    aria-pressed={variables.has(variable)}
                    onClick={() => toggle(variable)}
                  >
                    {variableLabels[variable]}
                  </Button>
                ))}
              </div>
              <p className="text-xs text-muted-foreground">
                «Unidades dadas por materia» e «Indicadores» se suman cuando existan el Programa anual e Indicadores y
                cruces.
              </p>
            </div>
          </div>
        )}
      </SectionCard>

      {option && (
        <SectionCard
          title={`${option.groups.map((group) => group.name).join(" frente a ")}`}
          bare={comparison !== null && rows.length > 0}
        >
          {comparisonError ? (
            <p className="text-sm text-destructive">{comparisonError}</p>
          ) : comparison === null ? (
            <p className="text-sm text-muted-foreground">Cargando la comparación…</p>
          ) : rows.length === 0 ? (
            <p className="text-sm text-muted-foreground">Prendé al menos una variable para ver la tabla.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Variable</TableHead>
                  {comparison.groups.map((group) => (
                    <TableHead key={group.id}>{group.name}</TableHead>
                  ))}
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((row) => (
                  <TableRow key={row.key}>
                    <TableCell className="font-medium">{row.label}</TableCell>
                    {comparison.groups.map((group) => (
                      <TableCell key={group.id}>{row.cell(group)}</TableCell>
                    ))}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </SectionCard>
      )}

      {comparison && comparison.groups.length > 0 && (
        <SectionCard title="Lo que esto no explica solo">
          <p className="mb-3 text-sm text-muted-foreground">
            Aula+ no arma una hipótesis ni escribe conclusiones: deja los datos a la vista para que alguien los revise.
            Cada número es del grupo entero; los screenings se cuentan por franja, con los nombres que configuró el
            colegio.
          </p>
          <div className="flex flex-wrap gap-2">
            {comparison.groups.flatMap((group) => [
              <Button key={`evolution-${group.id}`} asChild size="sm" variant="outline">
                <Link to={`/clases/${group.id}/seguimiento`}>Evolución de {group.name}</Link>
              </Button>,
              <Button key={`comments-${group.id}`} asChild size="sm" variant="outline">
                <Link to={`/clases/${group.id}/seguimiento`}>Comentarios de {group.name}</Link>
              </Button>,
            ])}
          </div>
        </SectionCard>
      )}
    </div>
  )
}

/** The table rows for the variables turned on, in a fixed order. */
function buildRows(groups: ComparedGroup[], variables: Set<Variable>): Row[] {
  const rows: Row[] = []

  if (variables.has("students")) {
    rows.push({ key: "students", label: "Alumnos", cell: (group) => String(group.student_count) })
  }

  if (variables.has("averages")) {
    const subjects = uniqueBy(
      groups.flatMap((group) => group.subject_averages.map((item) => ({ id: item.subject_id, name: item.subject_name }))),
    )
    for (const subject of subjects) {
      rows.push({
        key: `average-${subject.id}`,
        label: `Promedio ${subject.name}`,
        cell: (group) => {
          const item = group.subject_averages.find((candidate) => candidate.subject_id === subject.id)
          return item ? formatAverage(item.average) : "Sin calificaciones"
        },
      })
    }
    if (subjects.length === 0) {
      rows.push({ key: "averages-empty", label: "Promedio por materia", cell: () => "Sin calificaciones" })
    }
  }

  if (variables.has("accommodations")) {
    rows.push({
      key: "accommodations",
      label: "Ajustes vigentes, por tipo",
      cell: (group) =>
        group.accommodations_by_type.length === 0
          ? "Sin ajustes vigentes"
          : group.accommodations_by_type.map((item) => `${item.type}: ${item.student_count}`).join(" · "),
    })
  }

  if (variables.has("screenings")) {
    const screenings = uniqueBy(
      groups.flatMap((group) => group.screenings.map((item) => ({ id: item.screening_test_type_id, name: item.name }))),
    )
    for (const screening of screenings) {
      rows.push({
        key: `screening-${screening.id}`,
        label: screening.name,
        cell: (group) => {
          const item = group.screenings.find((candidate) => candidate.screening_test_type_id === screening.id)
          return item ? item.bands.map((band) => `${band.name} ${band.count}`).join(" · ") : "Sin tomas"
        },
      })
    }
    if (screenings.length === 0) {
      rows.push({ key: "screenings-empty", label: "Screenings", cell: () => "Sin tomas" })
    }
  }

  return rows
}

function uniqueBy(items: { id: number; name: string }[]): { id: number; name: string }[] {
  const seen = new Map<number, { id: number; name: string }>()
  for (const item of items) if (!seen.has(item.id)) seen.set(item.id, item)
  return [...seen.values()].sort((a, b) => a.name.localeCompare(b.name, "es"))
}
