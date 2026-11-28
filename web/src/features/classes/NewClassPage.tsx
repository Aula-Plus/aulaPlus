import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from "react"
import { Link, useNavigate, useSearchParams } from "react-router-dom"
import { Sparkles } from "lucide-react"

import { PageHeader } from "@/components/ui/page-header"
import { SectionCard } from "@/components/ui/section-card"
import { EmptyState } from "@/components/ui/empty-state"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { useAuth } from "@/features/auth/AuthContext"
import { isTeacher } from "@/lib/permissions"
import { formatShortDate } from "@/lib/utils"
import {
  fetchAnnualPlan,
  fetchAnnualPlanOptions,
  fetchAnnualPlans,
  type AnnualPlanDetail,
  type AnnualPlanOptions,
  type PlanUnit,
} from "@/features/annual-plans/annualPlansApi"
import { todayIso } from "@/features/annual-plans/timeline"
import {
  classPath,
  closingLabels,
  fetchMyClasses,
  newJourneyId,
  proposalPath,
  requestClassProposal,
  requestErrorMessage,
  type ClassRequestParameters,
  type MyClasses,
} from "./classesApi"

const selectClass = "h-9 rounded-md border bg-background px-3 text-sm disabled:opacity-60"

/**
 * Screen 14 «Nueva clase» (ClickUp 86e3jujh0, P14-DEC-01).
 *
 * - From a unit of the Programa anual (`?programa=&unidad=[&objetivo=]`):
 *   group, subject and unit come pre-filled, visible and NOT changeable.
 * - Entering directly: group and subject are mandatory (only the subjects
 *   the teacher teaches in that group, PA-104); unit and objectives are
 *   optional. A class without a unit is a «clase libre»: no planner is read
 *   and it adds nothing to coverage.
 *
 * The objectives are the teacher's («Qué querés que logren» and the unit's
 * objectives they pick), never the AI's. Only teachers generate classes
 * (P14-DEC-04); the server enforces it, this screen only explains it.
 */
export function NewClassPage() {
  const { user } = useAuth()
  const teacher = isTeacher(user)

  return (
    <div className="grid gap-6">
      <PageHeader
        title="Nueva clase"
        description="Pedile a la IA una propuesta de clase para tu grupo. La IA propone; vos decidís qué queda."
      />
      {teacher ? (
        <>
          <NewClassForm />
          <MyClassesCard />
        </>
      ) : (
        <SectionCard title="Las clases las genera el docente">
          <p className="text-sm text-muted-foreground">
            Psicopedagogía y Dirección no generan clases: las leen y las comentan desde la propuesta, antes
            de que el docente confirme.
          </p>
        </SectionCard>
      )}
    </div>
  )
}

function NewClassForm() {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const fixedPlanId = Number(params.get("programa")) || null
  const fixedUnitId = Number(params.get("unidad")) || null
  const preselectedObjective = Number(params.get("objetivo")) || null
  const fromUnit = fixedPlanId !== null && fixedUnitId !== null

  const [options, setOptions] = useState<AnnualPlanOptions | null>(null)
  const [plan, setPlan] = useState<AnnualPlanDetail | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)

  const [groupId, setGroupId] = useState("")
  const [subjectId, setSubjectId] = useState("")
  const [unitId, setUnitId] = useState(fixedUnitId ? String(fixedUnitId) : "")
  const [objectiveIds, setObjectiveIds] = useState<number[]>(preselectedObjective ? [preselectedObjective] : [])
  const [focus, setFocus] = useState("")
  const [objectives, setObjectives] = useState("")
  const [date, setDate] = useState(todayIso())
  const [duration, setDuration] = useState("45")
  const [requirements, setRequirements] = useState("")
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // From a unit: its plan gives group, subject and unit.
  useEffect(() => {
    if (!fromUnit || fixedPlanId === null) return
    fetchAnnualPlan(fixedPlanId)
      .then(setPlan)
      .catch(() => setLoadError("No pudimos cargar la unidad del programa anual."))
  }, [fromUnit, fixedPlanId])

  // Directly: the group-subjects the teacher teaches.
  useEffect(() => {
    if (fromUnit) return
    fetchAnnualPlanOptions()
      .then(setOptions)
      .catch(() => setLoadError("No pudimos cargar tus grupos y materias."))
  }, [fromUnit])

  // Directly, once group and subject are chosen: the teacher's own plan of
  // that group-subject (if any) offers its units, optionally.
  useEffect(() => {
    if (fromUnit || !groupId || !subjectId) return
    let cancelled = false
    fetchAnnualPlans()
      .then((plans) => {
        const match = plans
          .filter((p) => p.group.id === Number(groupId) && p.subject.id === Number(subjectId))
          .sort((a, b) => b.year - a.year)[0]
        return match ? fetchAnnualPlan(match.id) : null
      })
      .then((detail) => {
        if (!cancelled) setPlan(detail)
      })
      .catch(() => {
        if (!cancelled) setPlan(null)
      })
    return () => {
      cancelled = true
    }
  }, [fromUnit, groupId, subjectId])

  const groups = useMemo(() => {
    const seen = new Map<number, string>()
    options?.assignments.forEach((a) => seen.set(a.group_id, a.group_name))
    return [...seen.entries()].map(([id, name]) => ({ id, name }))
  }, [options])

  const subjects = useMemo(
    () => options?.assignments.filter((a) => a.group_id === Number(groupId)) ?? [],
    [options, groupId],
  )

  const unit: PlanUnit | null = plan?.units.find((u) => u.id === Number(unitId)) ?? null
  const fixedUnitMissing = fromUnit && plan !== null && unit === null

  function chooseGroup(value: string) {
    setGroupId(value)
    const taught = options?.assignments.filter((a) => a.group_id === Number(value)) ?? []
    // A single subject in the group is chosen for the teacher.
    setSubjectId(taught.length === 1 ? String(taught[0].subject_id) : "")
    setPlan(null)
    setUnitId("")
    setObjectiveIds([])
  }

  function chooseSubject(value: string) {
    setSubjectId(value)
    setPlan(null)
    setUnitId("")
    setObjectiveIds([])
  }

  function toggleObjective(id: number) {
    setObjectiveIds((current) => (current.includes(id) ? current.filter((x) => x !== id) : [...current, id]))
  }

  const targetGroupId = fromUnit ? plan?.group.id ?? null : Number(groupId) || null
  const canSubmit =
    !submitting && focus.trim() !== "" && targetGroupId !== null && (fromUnit ? unit !== null : subjectId !== "")

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!canSubmit || targetGroupId === null) return
    setSubmitting(true)
    setError(null)

    // With a unit the subject comes from its plan (the server takes one or
    // the other, never both); without one, the subject chosen directly.
    const request: ClassRequestParameters = {
      ...(unit ? { unit_id: unit.id } : { subject_id: Number(subjectId) }),
      focus: focus.trim(),
      ...(objectives.trim() ? { objectives: objectives.trim() } : {}),
      ...(unit && objectiveIds.length > 0
        ? { unit_objective_ids: objectiveIds.filter((id) => unit.objectives.some((o) => o.id === id)) }
        : {}),
      ...(date ? { date } : {}),
      ...(Number(duration) > 0 ? { duration_minutes: Number(duration) } : {}),
      ...(requirements.trim() ? { requirements: requirements.trim() } : {}),
    }

    try {
      const proposal = await requestClassProposal(targetGroupId, request, newJourneyId())
      navigate(proposalPath(proposal.id))
    } catch (err) {
      setError(requestErrorMessage(err))
      setSubmitting(false)
    }
  }

  if (loadError) return <p className="text-sm text-destructive">{loadError}</p>

  return (
    <form onSubmit={onSubmit} className="grid gap-6">
      <SectionCard title="Paso 1 · De dónde sale">
        {fromUnit ? (
          plan === null ? (
            <p className="text-sm text-muted-foreground">Cargando…</p>
          ) : fixedUnitMissing ? (
            <p className="text-sm text-destructive">Esa unidad ya no está en el programa anual.</p>
          ) : (
            <div className="grid gap-2 text-sm">
              <dl className="grid gap-2 sm:grid-cols-3">
                <Fixed label="Grupo" value={plan.group.name ?? "—"} />
                <Fixed label="Materia" value={plan.subject.name ?? "—"} />
                <Fixed label="Unidad" value={unit ? `Unidad ${unit.position} — ${unit.name}` : "—"} />
              </dl>
              <p className="text-xs text-muted-foreground">
                Viene del Programa anual: grupo, materia y unidad no se cambian desde acá.
              </p>
            </div>
          )
        ) : options && options.assignments.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            Todavía no tenés materias asignadas en ningún grupo. Dirección las asigna desde Grupos.
          </p>
        ) : (
          <div className="grid gap-4 md:grid-cols-3">
            <Field label="Grupo" htmlFor="class-group">
              <select
                id="class-group"
                className={selectClass}
                value={groupId}
                onChange={(e) => chooseGroup(e.target.value)}
                required
              >
                <option value="">Elegí…</option>
                {groups.map((g) => (
                  <option key={g.id} value={g.id}>
                    {g.name}
                  </option>
                ))}
              </select>
            </Field>
            <Field label="Materia" htmlFor="class-subject">
              <select
                id="class-subject"
                className={selectClass}
                value={subjectId}
                onChange={(e) => chooseSubject(e.target.value)}
                disabled={!groupId}
                required
              >
                <option value="">Elegí…</option>
                {subjects.map((s) => (
                  <option key={s.subject_id} value={s.subject_id}>
                    {s.subject_name}
                  </option>
                ))}
              </select>
            </Field>
            <Field label="Unidad (opcional)" htmlFor="class-unit">
              <select
                id="class-unit"
                className={selectClass}
                value={unitId}
                onChange={(e) => {
                  setUnitId(e.target.value)
                  setObjectiveIds([])
                }}
                disabled={!plan || plan.units.length === 0}
              >
                <option value="">Sin unidad (clase libre)</option>
                {plan?.units.map((u) => (
                  <option key={u.id} value={u.id}>
                    Unidad {u.position} — {u.name}
                  </option>
                ))}
              </select>
            </Field>
            <p className="text-xs text-muted-foreground md:col-span-3">
              Grupo y materia son obligatorios: solo aparecen las materias que dictás en ese grupo.
              {groupId && subjectId && !unit && (
                <>
                  {" "}
                  Sin unidad es una «clase libre»: se genera sin planificador y no suma a la cobertura del
                  programa.
                </>
              )}
            </p>
          </div>
        )}
      </SectionCard>

      <SectionCard title="Paso 2 · Tema, objetivos, fecha y duración">
        <div className="grid gap-4 md:grid-cols-2">
          <Field label="Tema" htmlFor="class-focus">
            <Input
              id="class-focus"
              value={focus}
              onChange={(e) => setFocus(e.target.value)}
              placeholder="Por ejemplo: comparar fracciones"
              maxLength={300}
              required
            />
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Fecha de la clase" htmlFor="class-date">
              <Input id="class-date" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
            </Field>
            <Field label="Duración (minutos)" htmlFor="class-duration">
              <Input
                id="class-duration"
                type="number"
                min={10}
                max={300}
                value={duration}
                onChange={(e) => setDuration(e.target.value)}
              />
            </Field>
          </div>
          <Field label="Qué querés que logren (opcional)" htmlFor="class-objectives">
            <Textarea
              id="class-objectives"
              value={objectives}
              onChange={(e) => setObjectives(e.target.value)}
              placeholder="Por ejemplo: que comparen fracciones con material concreto"
              maxLength={2000}
            />
          </Field>
          <Field label="Requerimientos específicos (opcional)" htmlFor="class-requirements">
            <Textarea
              id="class-requirements"
              value={requirements}
              onChange={(e) => setRequirements(e.target.value)}
              placeholder="Por ejemplo: que arranque en parejas y termine con trabajo individual"
              maxLength={2000}
            />
          </Field>
          {unit && (
            <fieldset className="grid gap-2 md:col-span-2">
              <legend className="text-sm font-medium">Objetivos de la unidad que querés trabajar (opcional)</legend>
              {unit.objectives.length === 0 ? (
                <p className="text-sm text-muted-foreground">La unidad todavía no tiene objetivos escritos.</p>
              ) : (
                unit.objectives.map((objective) => (
                  <label key={objective.id} className="flex items-start gap-2 text-sm">
                    <input
                      type="checkbox"
                      className="mt-1"
                      checked={objectiveIds.includes(objective.id)}
                      onChange={() => toggleObjective(objective.id)}
                    />
                    <span>{objective.description}</span>
                  </label>
                ))
              )}
              <p className="text-xs text-muted-foreground">
                Los objetivos los escribís vos; la IA no propone objetivos. Los que elijas quedan con esta clase
                en el Programa anual.
              </p>
            </fieldset>
          )}
        </div>
      </SectionCard>

      <SectionCard title="Generar">
        <div className="grid gap-3 text-sm">
          <p className="text-muted-foreground">
            A la IA le llegan el grupo, el programa de la materia, tus objetivos, el desempeño del grupo
            (agregado), los ajustes del grupo agregados por tipo y cómo se cerraron sus clases recientes. Nunca
            nombres ni diagnósticos. Al generar, explica por qué armó la clase así.
          </p>
          {(fromUnit || unit) && (
            <p className="text-muted-foreground">
              También le llega lo que completaste en el planificador de la unidad y los recursos que tenés tildados.
            </p>
          )}
          {error && <p className="text-destructive">{error}</p>}
          <div>
            <Button type="submit" disabled={!canSubmit}>
              <Sparkles aria-hidden="true" />
              {submitting ? "Pidiendo la propuesta…" : "Generar con la IA"}
            </Button>
          </div>
        </div>
      </SectionCard>
    </form>
  )
}

/** «Tus clases» (P14-ASI-05): open drafts and the teacher's classes. */
function MyClassesCard() {
  const [data, setData] = useState<MyClasses | null>(null)
  const [error, setError] = useState(false)

  useEffect(() => {
    fetchMyClasses()
      .then(setData)
      .catch(() => setError(true))
  }, [])

  return (
    <SectionCard title="Tus clases" bare>
      {error ? (
        <p className="px-6 pb-6 text-sm text-destructive">No pudimos cargar tus clases.</p>
      ) : !data ? (
        <p className="px-6 pb-6 text-sm text-muted-foreground">Cargando…</p>
      ) : data.drafts.length === 0 && data.classes.length === 0 ? (
        <div className="px-6 pb-6">
          <EmptyState message="Todavía no generaste clases." />
        </div>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Clase</TableHead>
              <TableHead>Grupo y materia</TableHead>
              <TableHead>Fecha</TableHead>
              <TableHead>Estado</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {data.drafts.map((draft) => (
              <TableRow key={`draft-${draft.id}`}>
                <TableCell className="font-medium">
                  <Link className="text-primary underline-offset-2 hover:underline" to={proposalPath(draft.id)}>
                    {draft.title ?? "Propuesta de clase"}
                  </Link>
                </TableCell>
                <TableCell>
                  {[draft.subject?.name, draft.group?.name].filter(Boolean).join(" · ") || "—"}
                </TableCell>
                <TableCell>{formatShortDate(draft.date)}</TableCell>
                <TableCell>
                  <Badge tone="warning">
                    {draft.status === "pending" ? "Generando" : draft.status === "error" ? "Con error" : "Borrador"}
                  </Badge>
                </TableCell>
              </TableRow>
            ))}
            {data.classes.map((item) => (
              <TableRow key={`class-${item.id}`}>
                <TableCell className="font-medium">
                  <Link className="text-primary underline-offset-2 hover:underline" to={classPath(item.id)}>
                    {item.title}
                  </Link>
                </TableCell>
                <TableCell>
                  {[item.subject?.name, item.group?.name].filter(Boolean).join(" · ") || "—"}
                </TableCell>
                <TableCell>{formatShortDate(item.date)}</TableCell>
                <TableCell>
                  {item.status === "cancelled" ? (
                    <Badge>Cancelada</Badge>
                  ) : item.closing_result ? (
                    <Badge tone="success">Cerrada · {closingLabels[item.closing_result]}</Badge>
                  ) : (
                    <Badge tone="info">Confirmada</Badge>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </SectionCard>
  )
}

function Fixed({ label, value }: { label: string; value: string }) {
  return (
    <div className="grid gap-0.5">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="font-medium">{value}</dd>
    </div>
  )
}

function Field({ label, htmlFor, children }: { label: string; htmlFor: string; children: ReactNode }) {
  return (
    <div className="grid gap-1.5">
      <Label htmlFor={htmlFor}>{label}</Label>
      {children}
    </div>
  )
}
