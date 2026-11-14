import { useCallback, useEffect, useRef, useState, type FormEvent, type ReactNode } from "react"
import { Link, useParams } from "react-router-dom"
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
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { formatShortDate } from "@/lib/utils"
import {
  createObjective,
  coverageLabels,
  createUnit,
  deleteObjective,
  deleteUnit,
  fetchAnnualPlan,
  fetchClosableClasses,
  objectiveStatusLabels,
  unitStatusLabels,
  updateAnnualPlan,
  updateClassObjectiveCoverage,
  updateObjective,
  updateUnit,
  type AnnualPlanDetail,
  type ClosableClass,
  type ObjectiveStatus,
  type PlanMark,
  type PlanObjective,
  type PlanUnit,
  type UnitStatus,
} from "./annualPlansApi"
import {
  barPosition,
  datePosition,
  givenLabel,
  givenShare,
  inYear,
  overlappingUnits,
  startedShare,
  todayIso,
  unitSummary,
} from "./timeline"

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

const objectiveTone: Record<ObjectiveStatus, BadgeTone> = {
  given: "success",
  started: "warning",
  planned: "info",
  unplanned: "neutral",
}

/** Which unit is open in «Unidades», and whether to focus its objective input. */
interface OpenUnit {
  id: number
  focusObjective: boolean
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
  const [openUnit, setOpenUnit] = useState<OpenUnit | null>(null)

  /** «Tocar una unidad la abre» (P12-CRO-03), also used by the no-objectives link. */
  const showUnit = useCallback((unitId: number, focusObjective = false) => {
    setTab("units")
    setOpenUnit({ id: unitId, focusObjective })
  }, [])

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

      {tab === "schedule" && <ScheduleTab plan={plan} onSaved={setPlan} onOpenUnit={showUnit} />}
      {tab === "units" && (
        <UnitsTab
          plan={plan}
          adding={addingUnit}
          onAddingChange={setAddingUnit}
          openUnit={openUnit}
          onOpenUnitChange={setOpenUnit}
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
  onOpenUnit,
}: {
  plan: AnnualPlanDetail
  onSaved: (plan: AnnualPlanDetail) => void
  onOpenUnit: (unitId: number) => void
}) {
  const [purpose, setPurpose] = useState(plan.description)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const overlaps = overlappingUnits(plan.units)
  const marks = plan.marks.filter((m) => m.date)
  const today = todayIso()
  const unitIds = new Set(plan.units.map((u) => u.id))
  // Marks tied to a unit of this plan sit on that unit's bar; the rest
  // (assessments, classes without a unit) on a general line above.
  const generalMarks = marks.filter((m) => m.unit_id === null || !unitIds.has(m.unit_id))
  const marksOf = (unitId: number) => marks.filter((m) => m.unit_id === unitId)

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
              <li className="grid gap-1">
                <span className="text-sm text-muted-foreground">Evaluaciones y otras clases</span>
                <MarksTrack marks={generalMarks} plan={plan} today={today} showTodayLabel />
              </li>
              {plan.units.map((unit) => {
                const { left, width } = barPosition(unit, plan.year)
                const share = givenShare(unit)
                const started = startedShare(unit)
                const summary = unitSummary(unit)
                return (
                  <li key={unit.id} className="grid gap-1">
                    <div className="flex flex-wrap justify-between gap-2 text-sm">
                      <button
                        type="button"
                        className="text-left font-medium hover:underline"
                        title={summary}
                        onClick={() => onOpenUnit(unit.id)}
                      >
                        {unit.position} · {unit.name}
                      </button>
                      <span className="text-muted-foreground">
                        {formatShortDate(unit.start_date)} – {formatShortDate(unit.end_date)} · Dado {share}%
                      </span>
                    </div>
                    <MarksTrack marks={marksOf(unit.id)} plan={plan} today={today}>
                      <div
                        role="img"
                        aria-label={summary}
                        title={summary}
                        data-testid={`unit-bar-${unit.id}`}
                        className="absolute inset-y-0 flex overflow-hidden rounded border border-primary/40 bg-primary/5"
                        style={{ left: `${left}%`, width: `${width}%` }}
                      >
                        <div className="h-full bg-primary" style={{ width: `${share}%` }} />
                        <div
                          className="h-full bg-primary/35"
                          style={{ width: `${started}%` }}
                          data-testid={`unit-bar-started-${unit.id}`}
                        />
                      </div>
                    </MarksTrack>
                  </li>
                )
              })}
            </ul>
            <p className="text-xs text-muted-foreground">
              Cada unidad es una barra: lo relleno es lo dado y lo tenue, lo empezado (una parte, todavía
              no cuenta como dado). Encima van las clases, las evaluaciones y el día de hoy. Los cierres de
              período todavía no están cargados.
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

/**
 * One line of the Cronograma: the year as a track, with the marks of classes
 * and assessments at their date and a «hoy» line (P12-CRO-03), using the same
 * position math as the unit bars. An assessment mark opens the group's
 * assessments; a class has no screen yet, so its mark only shows a tooltip.
 */
function MarksTrack({
  marks,
  plan,
  today,
  showTodayLabel = false,
  children,
}: {
  marks: PlanMark[]
  plan: AnnualPlanDetail
  today: string
  showTodayLabel?: boolean
  children?: ReactNode
}) {
  const showToday = inYear(today, plan.year)
  return (
    <div className="relative h-4 rounded bg-muted">
      {children}
      {showToday && (
        <span
          className="absolute -inset-y-1 w-0.5 bg-destructive"
          style={{ left: `${datePosition(today, plan.year)}%` }}
          title={`Hoy · ${formatShortDate(today)}`}
          data-testid={showTodayLabel ? "today-mark" : undefined}
        >
          {showTodayLabel && (
            <span className="absolute -top-4 -translate-x-1/2 text-[10px] font-medium text-destructive">
              hoy
            </span>
          )}
        </span>
      )}
      {marks.map((mark) => {
        const label = `${mark.kind === "class" ? "Clase" : "Evaluación"} · ${formatShortDate(mark.date)}${
          mark.title ? ` — ${mark.title}` : ""
        }`
        const style = { left: `calc(${datePosition(mark.date as string, plan.year)}% - 4px)` }
        const shape =
          "absolute top-1/2 size-2 -translate-y-1/2 rounded-full border border-background " +
          (mark.kind === "class" ? "bg-foreground" : "rotate-45 rounded-none bg-info")
        return mark.kind === "assessment" && plan.group.id !== null ? (
          <Link
            key={`${mark.kind}-${mark.id}`}
            to={`/clases/${plan.group.id}/evaluaciones`}
            className={shape}
            style={style}
            title={label}
            aria-label={label}
          />
        ) : (
          <span
            key={`${mark.kind}-${mark.id}`}
            role="img"
            className={shape}
            style={style}
            title={label}
            aria-label={label}
          />
        )
      })}
    </div>
  )
}

function UnitsTab({
  plan,
  adding,
  onAddingChange,
  openUnit,
  onOpenUnitChange,
  onChanged,
}: {
  plan: AnnualPlanDetail
  adding: boolean
  onAddingChange: (adding: boolean) => void
  openUnit: OpenUnit | null
  onOpenUnitChange: (open: OpenUnit | null) => void
  onChanged: () => Promise<void>
}) {

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
                  open={openUnit?.id === unit.id}
                  focusObjective={openUnit?.id === unit.id && openUnit.focusObjective}
                  onToggle={() =>
                    onOpenUnitChange(openUnit?.id === unit.id ? null : { id: unit.id, focusObjective: false })
                  }
                  onWriteObjectives={() => onOpenUnitChange({ id: unit.id, focusObjective: true })}
                  onChanged={onChanged}
                />
              ))}
            </TableBody>
          </Table>
        )}
        <p className="px-6 pb-6 text-xs text-muted-foreground">
          El estado sale solo. Una unidad está «planificada» cuando todos sus objetivos tienen una clase o
          una evaluación armada, y «dada» cuando todos se dieron. Un objetivo «empezado» se dio en
          parte y todavía no cuenta como dado.
        </p>
      </SectionCard>
    </div>
  )
}

function UnitRow({
  unit,
  canEdit,
  open,
  focusObjective,
  onToggle,
  onWriteObjectives,
  onChanged,
}: {
  unit: PlanUnit
  canEdit: boolean
  open: boolean
  focusObjective: boolean
  onToggle: () => void
  onWriteObjectives: () => void
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
        <TableCell>{unit.objectives_count === 0 ? "—" : givenLabel(unit)}</TableCell>
        <TableCell>
          {unit.status === null ? (
            <div className="grid gap-0.5">
              <span aria-label="Sin estado">—</span>
              {canEdit && (
                <button
                  type="button"
                  className="text-left text-xs text-primary underline-offset-2 hover:underline"
                  onClick={onWriteObjectives}
                >
                  Escribí los objetivos y el estado sale solo
                </button>
              )}
            </div>
          ) : (
            <Badge tone={statusTone[unit.status]}>{unitStatusLabels[unit.status]}</Badge>
          )}
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
              <ObjectivesList
                unit={unit}
                canEdit={canEdit}
                autoFocusInput={focusObjective}
                onChanged={onChanged}
              />
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
  autoFocusInput,
  onChanged,
}: {
  unit: PlanUnit
  canEdit: boolean
  autoFocusInput: boolean
  onChanged: () => Promise<void>
}) {
  const [draft, setDraft] = useState("")
  const [editingId, setEditingId] = useState<number | null>(null)
  const [editText, setEditText] = useState("")
  const [error, setError] = useState<string | null>(null)
  // One request at a time: no double submit while adding, editing or removing.
  const [busy, setBusy] = useState(false)
  const inputRef = useRef<HTMLInputElement>(null)

  // Opened from «Escribí los objetivos y el estado sale solo»: the input is
  // ready to write the first objective.
  useEffect(() => {
    if (autoFocusInput) inputRef.current?.focus()
  }, [autoFocusInput])

  async function run(action: () => Promise<void>, failure = "No pudimos guardar el objetivo.") {
    if (busy) return
    setBusy(true)
    setError(null)
    try {
      await action()
      await onChanged()
    } catch {
      setError(failure)
    } finally {
      setBusy(false)
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
                    disabled={busy || !editText.trim()}
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
                  <Badge tone={objectiveTone[objective.status]}>{objectiveStatusLabels[objective.status]}</Badge>
                  {canEdit && objective.status === "started" && objective.class_to_close && (
                    <FinishObjectiveDialog
                      objective={objective}
                      busy={busy}
                      onConfirm={(classId) =>
                        run(
                          () => updateClassObjectiveCoverage(classId, objective.id, "full"),
                          "No pudimos cambiar el registro de la clase.",
                        )
                      }
                    />
                  )}
                  {canEdit && (
                    <>
                      <Button
                        size="xs"
                        variant="ghost"
                        disabled={busy}
                        onClick={() => {
                          setEditingId(objective.id)
                          setEditText(objective.description)
                        }}
                      >
                        Editar
                      </Button>
                      <ConfirmDialog
                        trigger={
                          <Button size="xs" variant="ghost" disabled={busy}>
                            Quitar
                          </Button>
                        }
                        title="Quitar el objetivo"
                        description="Se borra el objetivo y lo que las clases y evaluaciones registraron sobre él. El estado de la unidad puede cambiar."
                        confirmLabel="Quitar"
                        isConfirming={busy}
                        onConfirm={() => void run(() => deleteObjective(objective.id), "No pudimos quitar el objetivo.")}
                      />
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
            if (!draft.trim() || busy) return
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
              ref={inputRef}
            />
          </div>
          <Button type="submit" size="sm" disabled={busy || !draft.trim()}>
            {busy ? "Guardando…" : "Agregar objetivo"}
          </Button>
        </form>
      )}
      {error && <p className="text-sm text-destructive">{error}</p>}
    </div>
  )
}

/**
 * «Lo terminé» on an objective «empezado». Not a checkbox: the teacher picks
 * the class where they finished it — the recent classes of the plan's group
 * and subject, preselecting the one where «una parte» was last recorded, or
 * another one found with «Buscar otra» — sees what that class's closing said
 * and what will change, and records «Todo» there. Other classes keep their
 * record; the objective's state keeps deriving only from the class records
 * (P12-COB-02, screen 13 F-7; ClickUp 1dfd3mc4bg8, PO 14/11).
 */
function FinishObjectiveDialog({
  objective,
  busy,
  onConfirm,
}: {
  objective: PlanObjective
  busy: boolean
  onConfirm: (classId: number) => Promise<void>
}) {
  const [open, setOpen] = useState(false)
  const [classes, setClasses] = useState<ClosableClass[]>([])
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [search, setSearch] = useState("")
  const [loading, setLoading] = useState(false)
  const [loadError, setLoadError] = useState<string | null>(null)
  const preselected = objective.class_to_close

  const load = useCallback(
    async (term?: string) => {
      setLoading(true)
      setLoadError(null)
      try {
        const found = await fetchClosableClasses(objective.id, term)
        setClasses((previous) => {
          // Keep the picked class visible even when a search does not return it.
          const kept = previous.find((c) => c.id === selectedId && !found.some((f) => f.id === c.id))
          return kept ? [kept, ...found] : found
        })
        if (term === undefined) {
          setSelectedId(preselected && found.some((c) => c.id === preselected.id) ? preselected.id : (found[0]?.id ?? null))
        }
      } catch {
        setLoadError("No pudimos traer las clases.")
      } finally {
        setLoading(false)
      }
    },
    [objective.id, preselected, selectedId],
  )

  function onOpenChange(next: boolean) {
    setOpen(next)
    if (next) {
      setClasses([])
      setSearch("")
      setSelectedId(null)
      void load()
    }
  }

  const selected = classes.find((c) => c.id === selectedId) ?? null
  const classLabel = (c: ClosableClass) => `${formatShortDate(c.date)}${c.title ? ` · ${c.title}` : ""}`
  const recordedLabel = (c: ClosableClass) =>
    c.coverage ? coverageLabels[c.coverage] : "Sin registro de este objetivo"

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogTrigger asChild>
        <Button size="xs" variant="outline" disabled={busy}>
          Lo terminé
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>¿En qué clase lo terminaste?</DialogTitle>
          <DialogDescription>
            Elegí la clase y queda registrado «Todo» para este objetivo en esa clase. Los cierres de las otras
            clases se conservan.
          </DialogDescription>
        </DialogHeader>
        <p className="text-sm">
          <span className="text-muted-foreground">Objetivo: </span>
          {objective.description}
        </p>
        <fieldset className="grid gap-1 text-sm" aria-label="Clases recientes">
          {loading && classes.length === 0 && <p className="text-muted-foreground">Cargando…</p>}
          {!loading && classes.length === 0 && !loadError && (
            <p className="text-muted-foreground">No encontramos clases de este grupo y materia.</p>
          )}
          {classes.map((c) => (
            <label key={c.id} className="flex items-start gap-2 rounded-md px-2 py-1 hover:bg-muted">
              <input
                type="radio"
                name={`finish-objective-${objective.id}`}
                className="mt-1"
                checked={selectedId === c.id}
                onChange={() => setSelectedId(c.id)}
              />
              <span>
                {classLabel(c)}
                <span className="block text-xs text-muted-foreground">{recordedLabel(c)}</span>
              </span>
            </label>
          ))}
        </fieldset>
        <form
          className="flex gap-2"
          onSubmit={(event) => {
            event.preventDefault()
            void load(search.trim() || undefined)
          }}
        >
          <Input
            aria-label="Buscar otra clase"
            placeholder="Buscar otra: título o fecha (AAAA-MM-DD)"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
          />
          <Button type="submit" variant="outline" size="sm" disabled={loading}>
            Buscar
          </Button>
        </form>
        {loadError && <p className="text-sm text-destructive">{loadError}</p>}
        {selected && (
          <div role="group" aria-label="Qué va a cambiar" className="rounded-md border p-3 text-sm">
            <dl className="grid gap-2">
              <div>
                <dt className="text-muted-foreground">Clase</dt>
                <dd>{classLabel(selected)}</dd>
              </div>
              <div>
                <dt className="text-muted-foreground">Qué decía ese cierre</dt>
                <dd>{recordedLabel(selected)}</dd>
              </div>
              <div>
                <dt className="text-muted-foreground">Cómo va a quedar</dt>
                <dd>
                  {selected.coverage === "full"
                    ? "Ya dice «Todo»: no cambia nada."
                    : selected.coverage
                      ? `«${coverageLabels[selected.coverage]}» pasa a «Todo», y el objetivo cuenta como dado.`
                      : "Se suma este objetivo a esa clase con «Todo», y el objetivo cuenta como dado."}
                </dd>
              </div>
            </dl>
          </div>
        )}
        <DialogFooter>
          <DialogClose asChild>
            <Button type="button" variant="outline">
              Cancelar
            </Button>
          </DialogClose>
          <Button
            type="button"
            disabled={busy || !selected || selected.coverage === "full"}
            onClick={async () => {
              if (!selected) return
              await onConfirm(selected.id)
              setOpen(false)
            }}
          >
            {busy ? "Guardando…" : "Cambiar a «todo»"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
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
                    {unit.status === null ? (
                      "—"
                    ) : (
                      <Badge tone={statusTone[unit.status]}>{unitStatusLabels[unit.status]}</Badge>
                    )}
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
