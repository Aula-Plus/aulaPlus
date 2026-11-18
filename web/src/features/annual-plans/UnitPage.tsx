import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react"
import { Link, useParams } from "react-router-dom"
import { Lock, PanelRightOpen, X } from "lucide-react"

import { PageHeader } from "@/components/ui/page-header"
import { SectionCard } from "@/components/ui/section-card"
import { EmptyState } from "@/components/ui/empty-state"
import { Badge, type BadgeTone } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { formatShortDate } from "@/lib/utils"
import { accommodationCategoryLabels } from "@/types"
import {
  createObjective,
  objectiveStatusLabels,
  unitStatusLabels,
  updateUnit,
  type ObjectiveStatus,
  type UnitStatus,
} from "./annualPlansApi"
import {
  citation,
  fetchUnit,
  fetchUnitAccommodations,
  fetchUnitCurricularItems,
  fieldLabel,
  saveObjectiveCurricularItems,
  saveUnitPlanner,
  type CurricularItemRow,
  type PlannerField,
  type PlannerValue,
  type UnitAccommodationType,
  type UnitDetail,
  type UnitObjective,
} from "./unitApi"

type Tab = "planning" | "objectives"

const tabLabels: Record<Tab, string> = {
  planning: "Planificación",
  objectives: "Objetivos",
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

/**
 * Screen 13 of the living document — Unidad (ClickUp 1dfd3mc4bga).
 *
 * The teacher who owns the plan fills the planner fields they want (none is
 * mandatory, P13-DEC-07), writes objectives and picks the curricular items each
 * one works from the program catalog, with its citation (P13-DEC-05). «A mano»
 * opens the group context in a side panel, closed by default: accommodations
 * by type and, on demand, the detail per student. Psicopedagogía and Dirección
 * read everything. The unit status is derived by the server (P13-DEC-06).
 *
 * Not here yet, by decision in ClickUp: «Nueva clase desde esta unidad»
 * (screen 14, task 86e3jujh0), the PTP block (second PR of Ajustes y PTP,
 * 1dfd3mc4bgc) and the group's available resources (screen 23 / group profile).
 */
export function UnitPage() {
  const { id } = useParams()
  const unitId = Number(id)
  const [unit, setUnit] = useState<UnitDetail | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [tab, setTab] = useState<Tab>("planning")
  const [panelOpen, setPanelOpen] = useState(false)
  const [accommodations, setAccommodations] = useState<UnitAccommodationType[] | null>(null)
  const [accommodationsError, setAccommodationsError] = useState(false)

  const reload = useCallback(
    () =>
      fetchUnit(unitId)
        .then(setUnit)
        .catch(() => setError("No pudimos cargar la unidad.")),
    [unitId],
  )

  useEffect(() => {
    void reload()
  }, [reload])

  useEffect(() => {
    let active = true
    fetchUnitAccommodations(unitId)
      .then((rows) => {
        if (!active) return
        setAccommodations(rows)
        setAccommodationsError(false)
      })
      .catch(() => {
        if (active) setAccommodationsError(true)
      })
    return () => {
      active = false
    }
  }, [unitId])

  if (error) return <p className="text-sm text-destructive">{error}</p>
  if (!unit) return <p className="text-sm text-muted-foreground">Cargando…</p>

  const meta = [
    unit.plan.subject.name,
    unit.plan.group.name,
    `${formatShortDate(unit.start_date)} – ${formatShortDate(unit.end_date)}`,
    `Docente: ${unit.plan.teacher.name}`,
    ...(unit.can_edit ? [] : ["solo lectura"]),
  ].join(" · ")

  return (
    <div className="grid gap-6">
      <PageHeader
        backTo={`/programa-anual/${unit.plan.id}`}
        backLabel="Programa anual"
        title={`Unidad ${unit.position} — ${unit.name}`}
        description={meta}
      >
        <Button variant="outline" onClick={() => setPanelOpen(true)} aria-expanded={panelOpen}>
          <PanelRightOpen aria-hidden="true" />A mano
        </Button>
      </PageHeader>

      <div className="flex flex-wrap items-center gap-2 text-sm">
        {unit.status ? (
          <Badge tone={statusTone[unit.status]}>{unitStatusLabels[unit.status]}</Badge>
        ) : (
          <Badge>Sin estado</Badge>
        )}
        <Badge>
          {unit.objectives_count} {unit.objectives_count === 1 ? "objetivo" : "objetivos"}
        </Badge>
        {unit.objectives_count > 0 && (
          <Badge tone="success">
            {unit.given_count} de {unit.objectives_count} objetivos dados
          </Badge>
        )}
      </div>

      <div role="tablist" aria-label="Solapas de la unidad" className="flex flex-wrap gap-2">
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

      {tab === "planning" && (
        <PlanningTab
          unit={unit}
          accommodations={accommodations}
          accommodationsFailed={accommodationsError}
          onChanged={reload}
          onSaved={setUnit}
        />
      )}
      {tab === "objectives" && <ObjectivesTab unit={unit} onChanged={reload} />}

      {panelOpen && (
        <AtHandPanel
          accommodations={accommodations}
          failed={accommodationsError}
          readOnly={!unit.can_edit}
          onClose={() => setPanelOpen(false)}
        />
      )}
    </div>
  )
}

function PlanningTab({
  unit,
  accommodations,
  accommodationsFailed,
  onChanged,
  onSaved,
}: {
  unit: UnitDetail
  accommodations: UnitAccommodationType[] | null
  accommodationsFailed: boolean
  onChanged: () => Promise<void>
  onSaved: (unit: UnitDetail) => void
}) {
  return (
    <div className="grid gap-6">
      <UnitDataCard unit={unit} onChanged={onChanged} />
      <PlannerCard
        unit={unit}
        accommodations={accommodations}
        accommodationsFailed={accommodationsFailed}
        onSaved={onSaved}
      />
    </div>
  )
}

function UnitDataCard({ unit, onChanged }: { unit: UnitDetail; onChanged: () => Promise<void> }) {
  const [name, setName] = useState(unit.name)
  const [start, setStart] = useState(unit.start_date)
  const [end, setEnd] = useState(unit.end_date)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const dirty = name !== unit.name || start !== unit.start_date || end !== unit.end_date

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!name.trim()) return
    if (end < start) {
      setError("La fecha de fin no puede ser anterior a la de inicio.")
      return
    }
    setSaving(true)
    setError(null)
    try {
      await updateUnit(unit.id, { name: name.trim(), start_date: start, end_date: end })
      await onChanged()
    } catch {
      setError("No pudimos guardar los datos de la unidad.")
    } finally {
      setSaving(false)
    }
  }

  const statusLine =
    unit.status === null
      ? "La unidad todavía no tiene objetivos: escribilos en la solapa Objetivos y el estado sale solo."
      : `El estado se actualiza con las clases y evaluaciones de la unidad. Está ${unitStatusLabels[unit.status].toLowerCase()}, con ${unit.given_count} de ${unit.objectives_count} objetivos dados.`

  if (!unit.can_edit) {
    return (
      <SectionCard title="Datos de la unidad">
        <dl className="grid gap-3 text-sm sm:grid-cols-3">
          <div>
            <dt className="text-muted-foreground">Título</dt>
            <dd>{unit.name}</dd>
          </div>
          <div>
            <dt className="text-muted-foreground">Desde</dt>
            <dd>{formatShortDate(unit.start_date)}</dd>
          </div>
          <div>
            <dt className="text-muted-foreground">Hasta</dt>
            <dd>{formatShortDate(unit.end_date)}</dd>
          </div>
        </dl>
        <p className="mt-3 text-sm text-muted-foreground">{statusLine}</p>
      </SectionCard>
    )
  }

  return (
    <SectionCard title="Datos de la unidad">
      <form className="grid gap-3" onSubmit={submit}>
        <div className="grid gap-3 sm:grid-cols-3">
          <div className="grid gap-1.5">
            <Label htmlFor="unit-name">Título</Label>
            <Input id="unit-name" value={name} onChange={(e) => setName(e.target.value)} required maxLength={255} />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="unit-start">Desde</Label>
            <Input id="unit-start" type="date" value={start} onChange={(e) => setStart(e.target.value)} required />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="unit-end">Hasta</Label>
            <Input id="unit-end" type="date" value={end} onChange={(e) => setEnd(e.target.value)} required />
          </div>
        </div>
        {error && <p className="text-sm text-destructive">{error}</p>}
        <p className="text-sm text-muted-foreground">{statusLine}</p>
        <div>
          <Button type="submit" size="sm" variant="outline" disabled={saving || !dirty}>
            {saving ? "Guardando…" : "Guardar datos"}
          </Button>
        </div>
      </form>
    </SectionCard>
  )
}

function differentiationLine(accommodations: UnitAccommodationType[]): string {
  return accommodations
    .map((a) => `${a.type} (${a.student_count} ${a.student_count === 1 ? "alumno" : "alumnos"})`)
    .join(" · ")
}

function PlannerCard({
  unit,
  accommodations,
  accommodationsFailed,
  onSaved,
}: {
  unit: UnitDetail
  accommodations: UnitAccommodationType[] | null
  accommodationsFailed: boolean
  onSaved: (unit: UnitDetail) => void
}) {
  const initial = useMemo(
    () => Object.fromEntries(unit.planner.fields.map((f) => [f.key, f.value])) as Record<string, PlannerValue>,
    [unit.planner.fields],
  )
  const [values, setValues] = useState<Record<string, PlannerValue>>(initial)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)


  const changed = Object.keys(values).filter((key) => JSON.stringify(values[key] ?? null) !== JSON.stringify(initial[key] ?? null))

  async function save() {
    setSaving(true)
    setError(null)
    setSaved(false)
    try {
      const payload = Object.fromEntries(changed.map((key) => [key, values[key] === "" ? null : values[key]]))
      const planner = await saveUnitPlanner(unit.id, payload)
      onSaved({ ...unit, planner })
      setSaved(true)
    } catch {
      setError("No pudimos guardar la planificación. Revisá los campos e intentá de nuevo.")
    } finally {
      setSaving(false)
    }
  }

  const set = (key: string, value: PlannerValue) => {
    setSaved(false)
    setValues((current) => ({ ...current, [key]: value }))
  }

  if (unit.planner.fields.length === 0) {
    return (
      <SectionCard title="Planificación de la unidad">
        <EmptyState message="El colegio todavía no configuró el planificador de esta materia." />
      </SectionCard>
    )
  }

  return (
    <SectionCard title="Planificación de la unidad">
      <div className="grid gap-4">
        <p className="text-sm text-muted-foreground">Ningún campo es obligatorio para generar una clase.</p>
        {unit.planner.fields.map((field) => (
          <PlannerFieldControl
            key={field.key}
            field={field}
            value={values[field.key] ?? null}
            readOnly={!unit.can_edit}
            accommodations={accommodations}
            accommodationsFailed={accommodationsFailed}
            onChange={(value) => set(field.key, value)}
          />
        ))}
        {unit.planner.fields.some((f) => f.required_by_program) && (
          <p className="text-xs text-muted-foreground">
            <Lock className="inline size-3" aria-hidden="true" /> Campo que pide el programa. El colegio puede cambiarle el
            nombre; no hace falta completarlo para generar una clase.
          </p>
        )}
        {unit.can_edit && (
          <div className="flex flex-wrap items-center gap-3">
            <Button onClick={save} disabled={saving || changed.length === 0}>
              {saving ? "Guardando…" : "Guardar planificación"}
            </Button>
            {saved && changed.length === 0 && <span className="text-sm text-muted-foreground">Guardado.</span>}
            {error && <span className="text-sm text-destructive">{error}</span>}
          </div>
        )}
      </div>
    </SectionCard>
  )
}

function PlannerFieldControl({
  field,
  value,
  readOnly,
  accommodations,
  accommodationsFailed,
  onChange,
}: {
  field: PlannerField
  value: PlannerValue
  readOnly: boolean
  accommodations: UnitAccommodationType[] | null
  accommodationsFailed: boolean
  onChange: (value: PlannerValue) => void
}) {
  const inputId = `planner-${field.key}`
  const label = (
    <span className="inline-flex items-center gap-1">
      {fieldLabel(field)}
      {field.required_by_program && <Lock className="size-3" aria-label="Pedido por el programa" />}
    </span>
  )
  const officialHint =
    field.school_name && field.official_name && field.school_name !== field.official_name ? (
      <span className="text-xs text-muted-foreground">Nombre en el programa: {field.official_name}</span>
    ) : null

  if (field.type === "automatic") {
    const body =
      field.key === "differentiation" ? (
        accommodationsFailed ? (
          <p className="text-sm text-destructive">No pudimos cargar los ajustes del grupo.</p>
        ) : accommodations === null ? (
          <p className="text-sm text-muted-foreground">Cargando…</p>
        ) : accommodations.length === 0 ? (
          <p className="text-sm text-muted-foreground">El grupo no tiene ajustes activos.</p>
        ) : (
          <>
            <p className="text-sm">{differentiationLine(accommodations)}</p>
            <p className="text-xs text-muted-foreground">
              Ajustes agrupados por tipo. Abrí «A mano» para consultar el detalle por alumno.
            </p>
          </>
        )
      ) : (
        <p className="text-sm text-muted-foreground">Aula+ lo completa con los datos del grupo.</p>
      )

    return (
      <div className="grid gap-1">
        <span className="text-sm font-medium">{label}</span>
        {officialHint}
        {body}
      </div>
    )
  }

  if (readOnly) {
    const shown = Array.isArray(value) ? value.join(" · ") : value === null || value === "" ? null : String(value)

    return (
      <div className="grid gap-1">
        <span className="text-sm font-medium">{label}</span>
        {officialHint}
        <p className={shown ? "whitespace-pre-line text-sm" : "text-sm text-muted-foreground"}>{shown ?? "Sin completar."}</p>
      </div>
    )
  }

  let control
  switch (field.type) {
    case "long_text":
      control = (
        <Textarea id={inputId} value={(value as string | null) ?? ""} maxLength={5000} onChange={(e) => onChange(e.target.value)} />
      )
      break
    case "number":
      control = (
        <Input
          id={inputId}
          type="number"
          min={0}
          max={10000}
          className="max-w-40"
          value={value === null ? "" : String(value)}
          onChange={(e) => onChange(e.target.value === "" ? null : Number(e.target.value))}
        />
      )
      break
    case "list":
      control = (
        <select
          id={inputId}
          className="h-9 max-w-sm rounded-md border border-input bg-background px-3 text-sm"
          value={(value as string | null) ?? ""}
          onChange={(e) => onChange(e.target.value || null)}
        >
          <option value="">Sin elegir</option>
          {(field.options ?? []).map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </select>
      )
      break
    case "multiple_choice": {
      const picked = Array.isArray(value) ? value : []
      control = (
        <div className="flex flex-wrap gap-3" role="group" aria-labelledby={`${inputId}-label`}>
          {(field.options ?? []).map((option) => (
            <label key={option} className="inline-flex items-center gap-1.5 text-sm">
              <input
                type="checkbox"
                checked={picked.includes(option)}
                onChange={(e) =>
                  onChange(e.target.checked ? [...picked, option] : picked.filter((p) => p !== option))
                }
              />
              {option}
            </label>
          ))}
        </div>
      )
      break
    }
    default:
      control = (
        <Input id={inputId} value={(value as string | null) ?? ""} maxLength={5000} onChange={(e) => onChange(e.target.value)} />
      )
  }

  return (
    <div className="grid gap-1.5">
      {field.type === "multiple_choice" ? (
        <span id={`${inputId}-label`} className="text-sm font-medium">
          {label}
        </span>
      ) : (
        <Label htmlFor={inputId}>{label}</Label>
      )}
      {officialHint}
      {field.description && <span className="text-xs text-muted-foreground">{field.description}</span>}
      {control}
    </div>
  )
}

function ObjectivesTab({ unit, onChanged }: { unit: UnitDetail; onChanged: () => Promise<void> }) {
  const [text, setText] = useState("")
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [picking, setPicking] = useState<UnitObjective | null>(null)

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!text.trim()) return
    setSaving(true)
    setError(null)
    try {
      await createObjective(unit.id, text.trim())
      setText("")
      await onChanged()
    } catch {
      setError("No pudimos guardar el objetivo.")
    } finally {
      setSaving(false)
    }
  }

  return (
    <SectionCard title="Objetivos de la unidad">
      <div className="grid gap-4">
        {unit.objectives.length === 0 ? (
          <EmptyState message="Esta unidad todavía no tiene objetivos." />
        ) : (
          <ol className="grid gap-3">
            {unit.objectives.map((objective, index) => (
              <li key={objective.id} className="grid gap-1.5 rounded-md border p-3">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-medium">
                    {index + 1} · {objective.description}
                  </span>
                  <Badge tone={objectiveTone[objective.status]}>{objectiveStatusLabels[objective.status]}</Badge>
                </div>
                {objective.curricular_items.length === 0 ? (
                  <p className="text-sm text-muted-foreground">Sin elementos curriculares elegidos.</p>
                ) : (
                  <ul className="grid gap-1 text-sm">
                    {objective.curricular_items.map((item) => (
                      <li key={item.id}>
                        <span className="font-medium">
                          {item.code ? `${item.code} · ` : ""}
                          {item.name}
                        </span>
                        <span className="text-muted-foreground"> — {citation(item)}</span>
                      </li>
                    ))}
                  </ul>
                )}
                {unit.can_edit && (
                  <div>
                    <Button size="sm" variant="outline" onClick={() => setPicking(objective)}>
                      Elementos curriculares
                    </Button>
                  </div>
                )}
              </li>
            ))}
          </ol>
        )}
        <p className="text-sm text-muted-foreground">
          Un objetivo está dado cuando lo marcaste al cerrar una clase o cuando una evaluación con resultados lo
          trabajó.
        </p>
        {unit.can_edit && (
          <form className="grid gap-2" onSubmit={submit}>
            <Label htmlFor="new-objective">Escribir un objetivo</Label>
            <Textarea id="new-objective" value={text} maxLength={2000} onChange={(e) => setText(e.target.value)} />
            {error && <p className="text-sm text-destructive">{error}</p>}
            <div>
              <Button type="submit" size="sm" disabled={saving || !text.trim()}>
                {saving ? "Guardando…" : "Agregar objetivo"}
              </Button>
            </div>
          </form>
        )}
      </div>
      {picking && (
        <CurricularItemsDialog
          unitId={unit.id}
          objective={picking}
          onClose={() => setPicking(null)}
          onSaved={async () => {
            setPicking(null)
            await onChanged()
          }}
        />
      )}
    </SectionCard>
  )
}

function CurricularItemsDialog({
  unitId,
  objective,
  onClose,
  onSaved,
}: {
  unitId: number
  objective: UnitObjective
  onClose: () => void
  onSaved: () => Promise<void>
}) {
  const [items, setItems] = useState<CurricularItemRow[] | null>(null)
  const [selected, setSelected] = useState<number[]>(objective.curricular_items.map((i) => i.id))
  const [search, setSearch] = useState("")
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    let active = true
    fetchUnitCurricularItems(unitId)
      .then((rows) => {
        if (active) setItems(rows)
      })
      .catch(() => {
        if (!active) return
        setError("No pudimos cargar el catálogo del programa.")
        setItems([])
      })
    return () => {
      active = false
    }
  }, [unitId])

  // Items already linked that the picker no longer offers (the program left
  // the group-subject's offering, the catalog changed…): listed first so the
  // teacher can still untick them.
  const offeredIds = new Set((items ?? []).map((item) => item.id))
  const staleIds = new Set(
    objective.curricular_items.filter((item) => !offeredIds.has(item.id)).map((item) => item.id),
  )
  const options =
    items === null ? null : [...objective.curricular_items.filter((item) => staleIds.has(item.id)), ...items]

  const term = search.trim().toLowerCase()
  const visible = (options ?? []).filter(
    (item) =>
      term === "" ||
      [item.name, item.code, item.parent_name, item.description].some((v) => v?.toLowerCase().includes(term)),
  )

  async function save() {
    setSaving(true)
    setError(null)
    try {
      await saveObjectiveCurricularItems(objective.id, selected)
      await onSaved()
    } catch {
      setError("No pudimos guardar los elementos curriculares.")
      setSaving(false)
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>Elementos curriculares</DialogTitle>
          <DialogDescription>
            Elegí del catálogo del programa los elementos que trabaja «{objective.description}». Cada uno queda con su
            cita.
          </DialogDescription>
        </DialogHeader>
        {options === null ? (
          <p className="text-sm text-muted-foreground">Cargando…</p>
        ) : options.length === 0 && !error ? (
          <p className="text-sm text-muted-foreground">
            Todavía no hay elementos del programa cargados para esta materia.
          </p>
        ) : (
          <div className="grid gap-3">
            <div className="grid gap-1.5">
              <Label htmlFor="curricular-search">Buscar en el catálogo</Label>
              <Input id="curricular-search" value={search} onChange={(e) => setSearch(e.target.value)} />
            </div>
            <ul className="grid max-h-80 gap-2 overflow-y-auto pr-1">
              {visible.map((item) => (
                <li key={item.id}>
                  <label className="flex items-start gap-2 text-sm">
                    <input
                      type="checkbox"
                      className="mt-1"
                      checked={selected.includes(item.id)}
                      onChange={(e) =>
                        setSelected((current) =>
                          e.target.checked ? [...current, item.id] : current.filter((id) => id !== item.id),
                        )
                      }
                    />
                    <span>
                      <span className="font-medium">
                        {item.code ? `${item.code} · ` : ""}
                        {item.name}
                      </span>
                      {item.parent_name && <span className="text-muted-foreground"> · {item.parent_name}</span>}
                      <span className="block text-xs text-muted-foreground">{citation(item)}</span>
                      {staleIds.has(item.id) && (
                        <span className="block text-xs text-muted-foreground">
                          Ya no está en el catálogo del programa.
                        </span>
                      )}
                    </span>
                  </label>
                </li>
              ))}
              {visible.length === 0 && <li className="text-sm text-muted-foreground">Ningún elemento coincide.</li>}
            </ul>
          </div>
        )}
        {error && <p className="text-sm text-destructive">{error}</p>}
        <DialogFooter>
          <DialogClose asChild>
            <Button variant="outline">Cancelar</Button>
          </DialogClose>
          <Button onClick={save} disabled={saving || options === null || (options.length === 0 && selected.length === 0)}>
            {saving ? "Guardando…" : "Guardar elementos"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

/**
 * «A mano»: the group context in a side panel that opens and closes with a
 * button, without leaving the unit. Starts with the accommodations by type;
 * «Ver el detalle por alumno» unfolds in the same panel. Only what the viewer
 * already sees in each student file is listed (server-side).
 */
function AtHandPanel({
  accommodations,
  failed,
  readOnly,
  onClose,
}: {
  accommodations: UnitAccommodationType[] | null
  failed: boolean
  readOnly: boolean
  onClose: () => void
}) {
  const [detail, setDetail] = useState(false)

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => event.key === "Escape" && onClose()
    window.addEventListener("keydown", onKey)
    return () => window.removeEventListener("keydown", onKey)
  }, [onClose])

  return (
    <aside
      role="dialog"
      aria-label="A mano"
      className="fixed inset-y-0 right-0 z-40 flex w-full max-w-md flex-col gap-4 overflow-y-auto border-l bg-background p-4 shadow-lg"
    >
      <div className="flex items-center justify-between gap-2">
        <h2 className="text-lg font-semibold">A mano</h2>
        <Button size="icon" variant="ghost" onClick={onClose} aria-label="Cerrar «A mano»">
          <X aria-hidden="true" />
        </Button>
      </div>
      <p className="text-sm text-muted-foreground">
        {readOnly
          ? "El contexto del grupo, para leer."
          : "El contexto del grupo para planificar esta unidad, sin salir de la pantalla."}
      </p>
      <section className="grid gap-2">
        <h3 className="font-medium">Ajustes del grupo, por tipo</h3>
        {failed ? (
          <p className="text-sm text-destructive">No pudimos cargar los ajustes del grupo.</p>
        ) : accommodations === null ? (
          <p className="text-sm text-muted-foreground">Cargando…</p>
        ) : accommodations.length === 0 ? (
          <p className="text-sm text-muted-foreground">El grupo no tiene ajustes activos.</p>
        ) : (
          <>
            <ul className="grid gap-2 text-sm">
              {accommodations.map((a) => (
                <li key={a.type} className="rounded-md border p-2">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="font-medium">{a.type}</span>
                    <span className="text-muted-foreground">
                      {a.student_count} {a.student_count === 1 ? "alumno" : "alumnos"}
                    </span>
                  </div>
                  {a.category && (
                    <span className="text-xs text-muted-foreground">{accommodationCategoryLabels[a.category]}</span>
                  )}
                  {detail && (
                    <ul className="mt-1 grid gap-0.5">
                      {a.students.map((s) => (
                        <li key={s.id}>
                          <Link className="text-primary underline-offset-2 hover:underline" to={`/alumnos/${s.id}/seguimiento`}>
                            {s.full_name}
                          </Link>
                        </li>
                      ))}
                    </ul>
                  )}
                </li>
              ))}
            </ul>
            <div>
              <Button size="sm" variant="outline" onClick={() => setDetail((d) => !d)} aria-expanded={detail}>
                {detail ? "Ocultar el detalle por alumno" : "Ver el detalle por alumno"}
              </Button>
            </div>
          </>
        )}
      </section>
    </aside>
  )
}
