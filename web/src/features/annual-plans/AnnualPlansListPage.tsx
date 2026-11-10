import { useEffect, useState, type FormEvent } from "react"
import { Link, useNavigate } from "react-router-dom"
import { CalendarRange } from "lucide-react"

import { useAuth } from "@/features/auth/AuthContext"
import { isTeacher } from "@/lib/permissions"
import { PageHeader } from "@/components/ui/page-header"
import { SectionCard } from "@/components/ui/section-card"
import { EmptyState } from "@/components/ui/empty-state"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import {
  createAnnualPlan,
  fetchAnnualPlanOptions,
  fetchAnnualPlans,
  type AnnualPlanOptions,
  type AnnualPlanSummary,
} from "./annualPlansApi"

/**
 * Entry to screen 12 (Programa anual): the plans the person can open — a
 * teacher's own, or every plan of the school for Psicopedagogía and Dirección
 * (read-only) — and, for a teacher, creating the plan of a group-subject they
 * teach. The role gating is UX only; AnnualPlanPolicy decides.
 */
export function AnnualPlansListPage() {
  const { user } = useAuth()
  const teacher = isTeacher(user)
  const [plans, setPlans] = useState<AnnualPlanSummary[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    fetchAnnualPlans()
      .then(setPlans)
      .catch(() => setError("No pudimos cargar los programas anuales."))
  }, [])

  return (
    <div className="grid gap-6">
      <PageHeader
        title="Programa anual"
        description={
          teacher
            ? "El programa de cada materia que das: cronograma, unidades y cobertura."
            : "Los programas del colegio, en modo lectura."
        }
      />

      {teacher && <NewPlanForm />}

      <SectionCard title={teacher ? "Mis programas" : "Programas del colegio"} bare>
        {error ? (
          <p className="px-6 pb-6 text-sm text-destructive">{error}</p>
        ) : plans === null ? (
          <p className="px-6 pb-6 text-sm text-muted-foreground">Cargando…</p>
        ) : plans.length === 0 ? (
          <div className="px-6 pb-6">
            <EmptyState
              icon={CalendarRange}
              message={
                teacher
                  ? "Todavía no armaste ningún programa anual."
                  : "Todavía no hay programas anuales en el colegio."
              }
            />
          </div>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Materia · Grupo</TableHead>
                <TableHead>Año</TableHead>
                <TableHead>Marco</TableHead>
                {!teacher && <TableHead>Docente</TableHead>}
                <TableHead>Unidades</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {plans.map((plan) => (
                <TableRow key={plan.id}>
                  <TableCell>
                    <Link to={`/programa-anual/${plan.id}`} className="font-medium hover:underline">
                      {plan.subject.name} · {plan.group.name}
                    </Link>
                  </TableCell>
                  <TableCell>{plan.year}</TableCell>
                  <TableCell>{plan.framework.name}</TableCell>
                  {!teacher && <TableCell>{plan.teacher.name}</TableCell>}
                  <TableCell>{plan.units_count}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </SectionCard>
    </div>
  )
}

function NewPlanForm() {
  const navigate = useNavigate()
  const [options, setOptions] = useState<AnnualPlanOptions | null>(null)
  const [assignment, setAssignment] = useState("")
  const [framework, setFramework] = useState("")
  const [year, setYear] = useState(() => String(new Date().getFullYear()))
  const [purpose, setPurpose] = useState("")
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    fetchAnnualPlanOptions()
      .then((loaded) => {
        setOptions(loaded)
        if (loaded.frameworks.length === 1) setFramework(String(loaded.frameworks[0].id))
      })
      .catch(() => setError("No pudimos cargar tus grupos y materias."))
  }, [])

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    const [groupId, subjectId] = assignment.split(":").map(Number)
    if (!groupId || !subjectId || !framework) {
      setError("Elegí el grupo y la materia, y el marco curricular.")
      return
    }
    setSaving(true)
    setError(null)
    try {
      const plan = await createAnnualPlan({
        group_id: groupId,
        subject_id: subjectId,
        curricular_framework_id: Number(framework),
        year: Number(year),
        description: purpose.trim() || undefined,
      })
      navigate(`/programa-anual/${plan.id}`)
    } catch {
      setError("No pudimos crear el programa. Revisá que no exista ya uno para ese grupo, materia y año.")
      setSaving(false)
    }
  }

  return (
    <SectionCard title="Nuevo programa anual">
      {options && options.assignments.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          Todavía no tenés materias asignadas en ningún grupo. Dirección las asigna desde Grupos.
        </p>
      ) : (
        <form onSubmit={onSubmit} className="grid gap-4 md:grid-cols-2">
          <div className="grid gap-1.5">
            <Label htmlFor="plan-assignment">Grupo y materia</Label>
            <select
              id="plan-assignment"
              className="h-9 rounded-md border bg-background px-3 text-sm"
              value={assignment}
              onChange={(e) => setAssignment(e.target.value)}
            >
              <option value="">Elegí…</option>
              {options?.assignments.map((a) => (
                <option key={`${a.group_id}:${a.subject_id}`} value={`${a.group_id}:${a.subject_id}`}>
                  {a.subject_name} · {a.group_name}
                </option>
              ))}
            </select>
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="plan-framework">Marco curricular</Label>
            <select
              id="plan-framework"
              className="h-9 rounded-md border bg-background px-3 text-sm"
              value={framework}
              onChange={(e) => setFramework(e.target.value)}
            >
              <option value="">Elegí…</option>
              {options?.frameworks.map((f) => (
                <option key={f.id} value={f.id}>
                  {f.name}
                </option>
              ))}
            </select>
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="plan-year">Año</Label>
            <Input
              id="plan-year"
              type="number"
              min={2000}
              max={2100}
              value={year}
              onChange={(e) => setYear(e.target.value)}
            />
          </div>
          <div className="grid gap-1.5 md:col-span-2">
            <Label htmlFor="plan-purpose">Propósito del año</Label>
            <Textarea
              id="plan-purpose"
              value={purpose}
              onChange={(e) => setPurpose(e.target.value)}
              placeholder="Qué querés que el grupo logre al cierre del año."
            />
          </div>
          {error && <p className="text-sm text-destructive md:col-span-2">{error}</p>}
          <div className="md:col-span-2">
            <Button type="submit" disabled={saving}>
              {saving ? "Creando…" : "Crear programa"}
            </Button>
          </div>
        </form>
      )}
    </SectionCard>
  )
}
