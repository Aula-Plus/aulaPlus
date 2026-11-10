import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from "react"
import { useParams } from "react-router-dom"
import { Plus, Trash2 } from "lucide-react"

import { PageHeader } from "@/components/ui/page-header"
import { SectionCard } from "@/components/ui/section-card"
import { EmptyState } from "@/components/ui/empty-state"
import { StatCard } from "@/components/ui/stat-card"
import { Badge, type BadgeTone } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { ConfirmDialog } from "@/components/ui/confirm-dialog"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { formatShortDate } from "@/lib/utils"
import {
  createObjective,
  createUnit,
  deleteObjective,
  deleteUnit,
  fetchAnnualPlan,
  unitStatusLabels,
  updateAnnualPlan,
  updateObjective,
  updateUnit,
  type AnnualPlanDetail,
  type PlanUnit,
  type UnitStatus,
} from "./annualPlansApi"
import { barPosition, givenShare, overlappingUnits } from "./timeline"

type Tab = "schedule" | "units" | "coverage" | "relations"

const tabLabels: Record<Tab, string> = {
  schedule: "Cronograma",
  units: "Unidades",
  coverage: "Cobertura",
  relations: "Relaciones entre programas",
}

const statusTone: Record<UnitStatus, BadgeTone> = {
  unplanned: "neutral",
  planned: "info",
  given: "success",
}

/**
 * Screen 12 of the living document — Programa anual (ClickUp 1dfd3mc4bg8).
 * Four tabs: Cronograma (default), Unidades, Cobertura and Relaciones entre
 * programas (P12-DEC-05). The owning teacher edits; Psicopedagogía and
 * Dirección read (`can_edit` comes from AnnualPlanPolicy — the UI only
 * mirrors it). Unit status and coverage are computed by the server.
 */
export function AnnualPlanPage() {
  const { id } = useParams()
  const planId = Number(id)
  const [plan, setPlan] = useState<AnnualPlanDetail | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [tab, setTab] = useState<Tab>("schedule")
  const [addingUnit, setAddingUnit] = useState(false)

  const reload = useCallback(
    () =>
      fetchAnnualPlan(planId)
        .then(setPlan)
        .catch(() => setError("No pudimos cargar el programa anual.")),
    [planId],
  )

  useEffect(() => {
    void reload()
  }, [reload])

  if (error) return <p className="text-sm text-destructive">{error}</p>
  if (!plan) return <p className="text-sm text-muted-foreground">Cargando…</p>

  const meta = [
    `Año ${plan.year}`,
    plan.framework.name,
    `Docente: ${plan.teacher.name}`,
    ...(plan.can_edit ? [] : ["solo lectura"]),
  ].join(" · ")

  return (
    <div className="grid gap-6">
      <PageHeader
        backTo="/programa-anual"
        backLabel="Programas anuales"
        title={`${plan.subject.name} · ${plan.group.name}`}
        description={meta}
      >
        {plan.can_edit && (
          <Button
            onClick={() => {
              setTab("units")
              setAddingUnit(true)
            }}
          >
            <Plus aria-hidden="true" />
            Nueva unidad
          </Button>
        )}
      </PageHeader>

      <div className="flex flex-wrap gap-2 text-sm">
        <Badge>{plan.coverage.units_total} unidades</Badge>
        <Badge tone="success">{plan.coverage.units_given} dadas</Badge>
      </div>

      <div role="tablist" aria-label="Solapas del programa anual" className="flex flex-wrap gap-2">
        {(Object.keys(tabLabels) as Tab[]).map((key) => (
          <button
            key={key}
            type="button"
            role="tab"
            aria-selected={tab === key}
            onClick={() => setTab(key)}
            className={
              "rounded-md px-3 py-1.5 text-sm font-medium transition-colors " +
              (tab === key ? "bg-primary text-primary-foreground" : "bg-muted text-muted-foreground")
            }
          >
            {tabLabels[key]}
          </button>
        ))}
      </div>

      {tab === "schedule" && <ScheduleTab plan={plan} onSaved={setPlan} />}
      {tab === "units" && (
        <UnitsTab
          plan={plan}
          adding={addingUnit}
          onAddingChange={setAddingUnit}
          onChanged={reload}
        />
      )}
      {tab === "coverage" && <CoverageTab plan={plan} />}
      {tab === "relations" && <RelationsTab />}
    </div>
  )
}

function ScheduleTab({
  plan,
  onSaved,
}: {
  plan: AnnualPlanDetail
  onSaved: (plan: AnnualPlanDetail) => void
}) {
  const [purpose, setPurpose] = useState(plan.description)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const overlaps = overlappingUnits(plan.units)
  const marks = plan.marks.filter((m) => m.date)

  async function savePurpose() {
    setSaving(true)
    setError(null)
    try {
      onSaved(await updateAnnualPlan(plan.id, { description: purpose }))
    } catch {
      setError("No pudimos guardar el propósito.")
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="grid gap-6">
      <SectionCard title="Datos del programa">
        <dl className="grid gap-3 text-sm">
          <div>
            <dt className="text-muted-foreground">Grupo y materia</dt>
            <dd>
              {plan.subject.name} · {plan.group.name}
            </dd>
          </div>
          <div className="grid gap-1.5">
            <dt className="text-muted-foreground">
              {plan.can_edit ? <Label htmlFor="plan-purpose">Propósito del año</Label> : "Propósito del año"}
            </dt>
            <dd>
              {plan.can_edit ? (
                <div className="grid gap-2">
                  <Textarea id="plan-purpose" value={purpose} onChange={(e) => setPurpose(e.target.value)} />
                  {error && <p className="text-sm text-destructive">{error}</p>}
                  <div>
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={savePurpose}
                      disabled={saving || purpose === plan.description}
                    >
                      {saving ? "Guardando…" : "Guardar propósito"}
                    </Button>
                  </div>
                </div>
              ) : (
                <p>{plan.description || "Sin propósito escrito."}</p>
              )}
            </dd>
          </div>
        </dl>
      </SectionCard>

      <SectionCard title="El año, unidad por unidad">
        {plan.units.length === 0 ? (
          <EmptyState message="Todavía no hay unidades en este programa." />
        ) : (
          <div className="grid gap-3">
            {overlaps.map(([a, b]) => (
              <p key={`${a.id}-${b.id}`} className="rounded-md bg-warning-background px-3 py-2 text-sm text-warning">
                Se superponen: {a.name} y {b.name}. Puede ser a propósito.
              </p>
            ))}
            <ul className="grid gap-3" aria-label="Línea de tiempo">
              {plan.units.map((unit) => {
                const { left, width } = barPosition(unit, plan.year)
                const share = givenShare(unit)
                return (
                  <li key={unit.id} className="grid gap-1">
                    <div className="flex flex-wrap justify-between gap-2 text-sm">
                      <span className="font-medium">
                        {unit.position} · {unit.name}
                      </span>
                      <span className="text-muted-foreground">
                        {formatShortDate(unit.start_date)} – {formatShortDate(unit.end_date)} · Dado {share}%
                      </span>
                    </div>
                    <div className="relative h-3 rounded bg-muted" aria-hidden="true">
                      <div
                        className="absolute inset-y-0 overflow-hidden rounded border border-primary/40 bg-primary/15"
                        style={{ left: `${left}%`, width: `${width}%` }}
                      >
                        <div className="h-full bg-primary" style={{ width: `${share}%` }} />
                      </div>
                    </div>
                  </li>
                )
              })}
            </ul>
            <p className="text-xs text-muted-foreground">
              Cada unidad es una barra; lo relleno es lo dado.
            </p>
          </div>
        )}
      </SectionCard>

      <SectionCard title="Clases y evaluaciones">
        {marks.length === 0 ? (
          <p className="text-sm text-muted-foreground">Todavía no hay clases ni evaluaciones cargadas.</p>
        ) : (
          <ul className="grid gap-1 text-sm">
            {marks.map((mark) => (
              <li key={`${mark.kind}-${mark.id}`}>
                {formatShortDate(mark.date)} · {mark.kind === "class" ? "Clase" : "Evaluación"}
                {mark.title ? ` — ${mark.title}` : ""}
              </li>
            ))}
          </ul>
        )}
      </SectionCard>
    </div>
  )
}

function UnitsTab({
  plan,
  adding,
  onAddingChange,
  onChanged,
}: {
  plan: AnnualPlanDetail
  adding: boolean
  onAddingChange: (adding: boolean) => void
  onChanged: () => Promise<void>
}) {
  const [openUnit, setOpenUnit] = useState<number | null>(null)

  return (
    <div className="grid gap-6">
      {plan.can_edit && adding && (
        <SectionCard title="Nueva unidad">
          <UnitForm
            submitLabel="Agregar unidad"
            onCancel={() => onAddingChange(false)}
            onSubmit={async (input) => {
              await createUnit(plan.id, input)
              onAddingChange(false)
              await onChanged()
            }}
          />
        </SectionCard>
      )}

      <SectionCard title={`Unidades del año · ${plan.year}`} bare>
        {plan.units.length === 0 ? (
          <div className="px-6 pb-6">
            <EmptyState message="Todavía no hay unidades en este programa." />
          </div>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Unidad</TableHead>
                <TableHead>Período</TableHead>
                <TableHead>Objetivos</TableHead>
                <TableHead>Dados</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead>
                  <span className="sr-only">Acciones</span>
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {plan.units.map((unit) => (
                <UnitRow
                  key={unit.id}
                  unit={unit}
                  canEdit={plan.can_edit}
                  open={openUnit === unit.id}
                  onToggle={() => setOpenUnit(openUnit === unit.id ? null : unit.id)}
                  onChanged={onChanged}
                />
              ))}
            </TableBody>
          </Table>
        )}
        <p className="px-6 pb-6 text-xs text-muted-foreground">
          El estado sale solo. Una unidad está «planificada» cuando todos sus objetivos tienen una clase o
          una evaluación armada, y «dada» cuando todos se dieron.
        </p>
      </SectionCard>
    </div>
  )
}

function UnitRow({
  unit,
  canEdit,
  open,
  onToggle,
  onChanged,
}: {
  unit: PlanUnit
  canEdit: boolean
  open: boolean
  onToggle: () => void
  onChanged: () => Promise<void>
}) {
  const [editing, setEditing] = useState(false)

  return (
    <>
      <TableRow>
        <TableCell className="font-medium">
          Unidad {unit.position} — {unit.name}
        </TableCell>
        <TableCell>
          {formatShortDate(unit.start_date)} – {formatShortDate(unit.end_date)}
        </TableCell>
        <TableCell>{unit.objectives_count}</TableCell>
        <TableCell>
          {unit.given_count} de {unit.objectives_count}
        </TableCell>
        <TableCell>
          <Badge tone={statusTone[unit.status]}>{unitStatusLabels[unit.status]}</Badge>
        </TableCell>
        <TableCell className="text-right">
          <Button size="sm" variant="ghost" onClick={onToggle} aria-expanded={open}>
            {open ? "Cerrar" : "Objetivos"}
          </Button>
        </TableCell>
      </TableRow>
      {open && (
        <TableRow>
          <TableCell colSpan={6} className="bg-muted/30">
            <div className="grid gap-4 py-2">
              {canEdit &&
                (editing ? (
                  <UnitForm
                    initial={unit}
                    submitLabel="Guardar unidad"
                    onCancel={() => setEditing(false)}
                    onSubmit={async (input) => {
                      await updateUnit(unit.id, input)
                      setEditing(false)
                      await onChanged()
                    }}
                  />
                ) : (
                  <div className="flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
                      Editar unidad
                    </Button>
                    <ConfirmDialog
                      trigger={
                        <Button size="sm" variant="outline">
                          <Trash2 aria-hidden="true" />
                          Borrar unidad
                        </Button>
                      }
                      title="Borrar la unidad"
                      description="Se borran también sus objetivos. Las clases y evaluaciones quedan, sin unidad."
                      confirmLabel="Borrar"
                      onConfirm={async () => {
                        await deleteUnit(unit.id)
                        await onChanged()
                      }}
                    />
                  </div>
                ))}
              <ObjectivesList unit={unit} canEdit={canEdit} onChanged={onChanged} />
            </div>
          </TableCell>
        </TableRow>
      )}
    </>
  )
}

function ObjectivesList({
  unit,
  canEdit,
  onChanged,
}: {
  unit: PlanUnit
  canEdit: boolean
  onChanged: () => Promise<void>
}) {
  const [draft, setDraft] = useState("")
  const [editingId, setEditingId] = useState<number | null>(null)
  const [editText, setEditText] = useState("")
  const [error, setError] = useState<string | null>(null)

  async function run(action: () => Promise<void>) {
    setError(null)
    try {
      await action()
      await onChanged()
    } catch {
      setError("No pudimos guardar el objetivo.")
    }
  }

  return (
    <div className="grid gap-2">
      <h3 className="text-sm font-medium">Objetivos de la unidad</h3>
      {unit.objectives.length === 0 ? (
        <p className="text-sm text-muted-foreground">Todavía no hay objetivos escritos.</p>
      ) : (
        <ol className="grid gap-2 text-sm">
          {unit.objectives.map((objective, index) => (
            <li key={objective.id} className="flex flex-wrap items-center gap-2">
              {editingId === objective.id ? (
                <>
                  <Input
                    aria-label="Objetivo"
                    value={editText}
                    onChange={(e) => setEditText(e.target.value)}
                    className="max-w-xl"
                  />
                  <Button
                    size="sm"
                    disabled={!editText.trim()}
                    onClick={() =>
                      run(async () => {
                        await updateObjective(objective.id, editText.trim())
                        setEditingId(null)
                      })
                    }
                  >
                    Guardar
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => setEditingId(null)}>
                    Cancelar
                  </Button>
                </>
              ) : (
                <>
                  <span>
                    {index + 1} · {objective.description}
                  </span>
                  <Badge tone={objective.given ? "success" : objective.planned ? "info" : "neutral"}>
                    {objective.given ? "Dado" : objective.planned ? "Con clase o evaluación" : "Sin planificar"}
                  </Badge>
                  {canEdit && (
                    <>
                      <Button
                        size="xs"
                        variant="ghost"
                        onClick={() => {
                          setEditingId(objective.id)
                          setEditText(objective.description)
                        }}
                      >
                        Editar
                      </Button>
                      <Button
                        size="xs"
                        variant="ghost"
                        onClick={() => run(() => deleteObjective(objective.id))}
                      >
                        Quitar
                      </Button>
                    </>
                  )}
                </>
              )}
            </li>
          ))}
        </ol>
      )}
      {canEdit && (
        <form
          className="flex flex-wrap items-end gap-2"
          onSubmit={(event: FormEvent) => {
            event.preventDefault()
            if (!draft.trim()) return
            void run(async () => {
              await createObjective(unit.id, draft.trim())
              setDraft("")
            })
          }}
        >
          <div className="grid gap-1.5">
            <Label htmlFor={`objective-${unit.id}`}>Escribir un objetivo</Label>
            <Input
              id={`objective-${unit.id}`}
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
              className="w-96 max-w-full"
            />
          </div>
          <Button type="submit" size="sm" disabled={!draft.trim()}>
            Agregar objetivo
          </Button>
        </form>
      )}
      {error && <p className="text-sm text-destructive">{error}</p>}
    </div>
  )
}

function UnitForm({
  initial,
  submitLabel,
  onSubmit,
  onCancel,
}: {
  initial?: PlanUnit
  submitLabel: string
  onSubmit: (input: { name: string; start_date: string; end_date: string }) => Promise<void>
  onCancel: () => void
}) {
  const [name, setName] = useState(initial?.name ?? "")
  const [start, setStart] = useState(initial?.start_date ?? "")
  const [end, setEnd] = useState(initial?.end_date ?? "")
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const prefix = initial ? `unit-${initial.id}` : "unit-new"

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!name.trim() || !start || !end) {
      setError("Completá el título y las fechas.")
      return
    }
    if (end < start) {
      setError("La fecha de fin no puede ser anterior a la de inicio.")
      return
    }
    setSaving(true)
    setError(null)
    try {
      await onSubmit({ name: name.trim(), start_date: start, end_date: end })
    } catch {
      setError("No pudimos guardar la unidad.")
    } finally {
      setSaving(false)
    }
  }

  return (
    <form onSubmit={submit} className="grid gap-3 md:grid-cols-3">
      <Field label="Título" htmlFor={`${prefix}-name`}>
        <Input id={`${prefix}-name`} value={name} onChange={(e) => setName(e.target.value)} />
      </Field>
      <Field label="Desde" htmlFor={`${prefix}-start`}>
        <Input id={`${prefix}-start`} type="date" value={start} onChange={(e) => setStart(e.target.value)} />
      </Field>
      <Field label="Hasta" htmlFor={`${prefix}-end`}>
        <Input id={`${prefix}-end`} type="date" value={end} onChange={(e) => setEnd(e.target.value)} />
      </Field>
      {error && <p className="text-sm text-destructive md:col-span-3">{error}</p>}
      <div className="flex gap-2 md:col-span-3">
        <Button type="submit" size="sm" disabled={saving}>
          {saving ? "Guardando…" : submitLabel}
        </Button>
        <Button type="button" size="sm" variant="ghost" onClick={onCancel}>
          Cancelar
        </Button>
      </div>
    </form>
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

function CoverageTab({ plan }: { plan: AnnualPlanDetail }) {
  const c = plan.coverage
  return (
    <div className="grid gap-6">
      <SectionCard title="Cobertura del año">
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
          <StatCard label={`Unidades dadas (de ${c.units_total})`} value={c.units_given} tone="success" />
          <StatCard label="Objetivos en el programa" value={c.objectives_total} />
          <StatCard label="Con clase o evaluación armada" value={c.objectives_planned} tone="info" />
          <StatCard label="Dados" value={c.objectives_given} tone="success" />
          <StatCard label="Sin planificar todavía" value={c.objectives_unplanned} tone="warning" />
        </div>
        <p className="mt-4 text-xs text-muted-foreground">
          Son contadores de lo que ya pasó: no hay un «ritmo esperado» porque las clases efectivas del año
          no están cargadas.
        </p>
      </SectionCard>

      <SectionCard title="Por unidad" bare>
        {plan.units.length === 0 ? (
          <div className="px-6 pb-6">
            <EmptyState message="Todavía no hay unidades en este programa." />
          </div>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Unidad</TableHead>
                <TableHead>Objetivos</TableHead>
                <TableHead>Con clase o evaluación</TableHead>
                <TableHead>Dados</TableHead>
                <TableHead>Sin planificar</TableHead>
                <TableHead>Estado</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {plan.units.map((unit) => (
                <TableRow key={unit.id}>
                  <TableCell>
                    Unidad {unit.position} — {unit.name}
                  </TableCell>
                  <TableCell>{unit.objectives_count}</TableCell>
                  <TableCell>{unit.planned_count}</TableCell>
                  <TableCell>{unit.given_count}</TableCell>
                  <TableCell>{unit.objectives_count - unit.planned_count}</TableCell>
                  <TableCell>
                    <Badge tone={statusTone[unit.status]}>{unitStatusLabels[unit.status]}</Badge>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </SectionCard>
    </div>
  )
}

function RelationsTab() {
  return (
    <SectionCard title="Relaciones entre programas">
      <div className="grid gap-3">
        <EmptyState message="Todavía no hay relaciones sugeridas para esta materia." />
        <p className="text-sm text-muted-foreground">
          Las relaciones las carga el equipo de Aula+ al armar el catálogo, comparando objetivos de ANEP,
          Cambridge e IB. No se aprueban solas: el docente las acepta o las ignora, e ignorar no borra
          nada.
        </p>
      </div>
    </SectionCard>
  )
}
