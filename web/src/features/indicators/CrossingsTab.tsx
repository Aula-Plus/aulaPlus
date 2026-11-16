import { useEffect, useState } from "react"
import { Plus, Trash2 } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { ConfirmDialog } from "@/components/ui/confirm-dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { SectionCard } from "@/components/ui/section-card"
import { Textarea } from "@/components/ui/textarea"
import { formatShortDate } from "@/lib/utils"
import {
  deleteCrossing,
  fetchCrossings,
  previewCrossing,
  saveCrossing,
  type Band,
  type Crossing,
  type CrossingCondition,
  type CrossingInput,
  type CrossingOperator,
  type CrossingPreview,
  type IndicatorDataCatalog,
  type ScaleLevel,
  type SourceType,
} from "./indicatorsApi"
import { formatNumber, operatorLabels, previewText, sourceLabel } from "./indicatorText"

/** One datum a condition can read, with what it offers. */
interface ConditionSource {
  key: string
  type: SourceType
  id: number
  label: string
  bands: Band[]
  levels: ScaleLevel[]
  hasNumbers: boolean
}

function conditionSources(catalog: IndicatorDataCatalog): ConditionSource[] {
  return [
    ...catalog.sources.map((source) => ({
      key: `${source.type}:${source.id}`,
      type: source.type,
      id: source.id,
      label: sourceLabel(source.type, source.name),
      bands: source.bands,
      levels: source.levels,
      hasNumbers: source.number_coverage > 0 || source.level_coverage === 0,
    })),
    // A crossing can be on the grade a student got in one assessment (PO
    // decision 2). Rubric criteria come with per-criterion grading.
    ...catalog.assessments.map((assessment) => ({
      key: `assessment:${assessment.id}`,
      type: "assessment" as const,
      id: assessment.id,
      label: `Evaluación · ${[assessment.subject_name, assessment.group_name, assessment.administered_at ? formatShortDate(assessment.administered_at) : null].filter(Boolean).join(" · ")}`,
      bands: [],
      levels: assessment.levels,
      hasNumbers: true,
    })),
  ]
}

function operatorsFor(source: ConditionSource | undefined): CrossingOperator[] {
  if (!source) return []
  const operators: CrossingOperator[] = []
  if (source.bands.length > 0) operators.push("band")
  if (source.levels.length > 0) operators.push("level")
  if (source.hasNumbers) operators.push("below", "above")
  return operators
}

function defaultValue(source: ConditionSource | undefined, operator: CrossingOperator): string | number {
  if (operator === "band") return source?.bands[0]?.name ?? ""
  if (operator === "level") return source?.levels[0]?.id ?? 0
  return 0
}

function freshCondition(source: ConditionSource | undefined): CrossingCondition | null {
  if (!source) return null
  const operator = operatorsFor(source)[0] ?? "below"
  return { source: { type: source.type, id: source.id }, operator, value: defaultValue(source, operator) }
}

/** «Herramienta de lectura en la franja «Bajo»», for the rule that holds. */
function conditionText(
  condition: CrossingCondition & { source: { name?: string | null } },
  sources: ConditionSource[],
): string {
  const source = sources.find((candidate) => candidate.key === `${condition.source.type}:${condition.source.id}`)
  const label = source?.label ?? sourceLabel(condition.source.type, condition.source.name ?? null)
  if (condition.operator === "band") return `${label} ${operatorLabels.band} «${condition.value}»`
  if (condition.operator === "level") {
    const level = source?.levels.find((candidate) => candidate.id === Number(condition.value))
    return `${label} ${operatorLabels.level} «${level?.name ?? condition.value}»`
  }
  return `${label} ${operatorLabels[condition.operator]} ${formatNumber(Number(condition.value))}`
}

/**
 * «Cruces» (P30-CRU-02): each crossing is one or two conditions that must
 * all hold and the reading the school writes; the AI writes nothing. The
 * preview counts per group with no names (PO decision 5): «3 alumnos de 5.°A
 * · 1 de 5.°B».
 */
export function CrossingsTab({ catalog }: { catalog: IndicatorDataCatalog }) {
  const [crossings, setCrossings] = useState<Crossing[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [editing, setEditing] = useState<{ id?: number; input: CrossingInput } | null>(null)
  const sources = conditionSources(catalog)

  async function load() {
    try {
      setCrossings(await fetchCrossings())
    } catch {
      setError("No pudimos cargar los cruces.")
    }
  }

  useEffect(() => {
    void load()
  }, [])

  function startNew() {
    const first = freshCondition(sources.find((source) => source.type === "indicator") ?? sources[0])
    setEditing({ input: { name: "", reading: "", conditions: first ? [first] : [] } })
  }

  async function remove(crossing: Crossing) {
    try {
      await deleteCrossing(crossing.id)
      await load()
    } catch {
      setError("No pudimos borrar el cruce.")
    }
  }

  return (
    <div className="grid gap-6">
      <SectionCard
        title="Cruces"
        action={
          <Button type="button" size="sm" onClick={startNew} disabled={sources.length === 0}>
            <Plus aria-hidden="true" />
            Nuevo cruce
          </Button>
        }
      >
        {error ? (
          <p className="text-sm text-destructive">{error}</p>
        ) : crossings === null ? (
          <p className="text-sm text-muted-foreground">Cargando cruces…</p>
        ) : crossings.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            {sources.length === 0 ? "Primero hacen falta datos o un indicador." : "Todavía no hay cruces."}
          </p>
        ) : (
          <ul className="grid gap-3">
            {crossings.map((crossing) => (
              <li key={crossing.id} className="grid gap-1 rounded-md border p-3">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-medium">{crossing.name}</span>
                  <Badge tone="neutral">Escrita por el colegio</Badge>
                </div>
                <p className="text-sm text-muted-foreground">
                  Se cumple si: {crossing.conditions.map((condition) => conditionText(condition, sources)).join(" y ")}.
                </p>
                <p className="text-sm">«{crossing.reading}»</p>
                <p className="text-sm text-muted-foreground">
                  Vista previa con los datos de hoy: {previewText(crossing.preview)}
                </p>
                <div className="flex gap-2 pt-1">
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() =>
                      setEditing({
                        id: crossing.id,
                        input: {
                          name: crossing.name,
                          reading: crossing.reading,
                          conditions: crossing.conditions.map(({ source, operator, value }) => ({
                            source: { type: source.type, id: source.id },
                            operator,
                            value,
                          })),
                        },
                      })
                    }
                  >
                    Editar
                  </Button>
                  <ConfirmDialog
                    trigger={
                      <Button type="button" size="sm" variant="ghost" aria-label={`Borrar ${crossing.name}`}>
                        <Trash2 aria-hidden="true" />
                      </Button>
                    }
                    title={`¿Borrar «${crossing.name}»?`}
                    description="Se borra el cruce y su lectura. Los datos no se tocan."
                    confirmLabel="Borrar"
                    onConfirm={() => void remove(crossing)}
                  />
                </div>
              </li>
            ))}
          </ul>
        )}
        <p className="mt-3 text-xs text-muted-foreground">
          Cada cruce es una condición sobre uno o dos datos y un texto que escribe el colegio. «No se asigna una
          conclusión cerrada» es una forma válida de escribirla. La lectura muestra siempre la regla que se cumplió. Los
          cruces no se abren a los docentes.
        </p>
      </SectionCard>

      {editing && (
        <CrossingForm
          key={editing.id ?? "new"}
          id={editing.id}
          initial={editing.input}
          sources={sources}
          onCancel={() => setEditing(null)}
          onSaved={async () => {
            setEditing(null)
            await load()
          }}
        />
      )}
    </div>
  )
}

function CrossingForm({
  id,
  initial,
  sources,
  onCancel,
  onSaved,
}: {
  id?: number
  initial: CrossingInput
  sources: ConditionSource[]
  onCancel: () => void
  onSaved: () => Promise<void>
}) {
  const [input, setInput] = useState<CrossingInput>(initial)
  const [preview, setPreview] = useState<CrossingPreview | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  function setCondition(index: number, condition: CrossingCondition) {
    setInput((current) => ({
      ...current,
      conditions: current.conditions.map((candidate, position) => (position === index ? condition : candidate)),
    }))
    setPreview(null)
  }

  function check(requireText: boolean): string | null {
    if (input.conditions.length === 0) return "Agregá al menos una condición."
    if (input.conditions.some((condition) => condition.operator === "band" && condition.value === "")) {
      return "Elegí la franja de cada condición."
    }
    if (requireText && !input.name.trim()) return "Poné un nombre al cruce."
    if (requireText && !input.reading.trim()) return "Escribí la lectura del colegio."
    return null
  }

  async function runPreview() {
    const problem = check(false)
    if (problem) return setError(problem)
    setError(null)
    setBusy(true)
    try {
      setPreview(await previewCrossing(input.conditions))
    } catch {
      setError("No pudimos calcular la vista previa.")
    } finally {
      setBusy(false)
    }
  }

  async function save() {
    const problem = check(true)
    if (problem) return setError(problem)
    setError(null)
    setBusy(true)
    try {
      await saveCrossing({ ...input, name: input.name.trim(), reading: input.reading.trim() }, id)
      await onSaved()
    } catch {
      setError("No pudimos guardar el cruce. Revisá las condiciones.")
      setBusy(false)
    }
  }

  return (
    <SectionCard title={id ? `Editar «${initial.name}»` : "Nuevo cruce"}>
      <div className="grid gap-5">
        <div className="grid gap-1">
          <Label htmlFor="crossing-name">Nombre</Label>
          <Input
            id="crossing-name"
            placeholder="Por ejemplo: Patrón instrumental a revisar"
            value={input.name}
            onChange={(event) => setInput((current) => ({ ...current, name: event.target.value }))}
          />
        </div>

        <fieldset className="grid gap-3">
          <legend className="text-sm font-medium">Se cumple si (todas a la vez)</legend>
          {input.conditions.map((condition, index) => {
            const key = `${condition.source.type}:${condition.source.id}`
            const source = sources.find((candidate) => candidate.key === key)
            const operators = operatorsFor(source)
            return (
              <div key={index} className="grid gap-2 rounded-md border p-3 sm:grid-cols-[1fr_12rem_12rem_auto]">
                <select
                  aria-label={`Dato de la condición ${index + 1}`}
                  className="h-9 rounded-md border bg-background px-2 text-sm"
                  value={key}
                  onChange={(event) => {
                    const next = freshCondition(sources.find((candidate) => candidate.key === event.target.value))
                    if (next) setCondition(index, next)
                  }}
                >
                  {sources.map((candidate) => (
                    <option key={candidate.key} value={candidate.key}>
                      {candidate.label}
                    </option>
                  ))}
                </select>
                <select
                  aria-label={`Cómo se lee la condición ${index + 1}`}
                  className="h-9 rounded-md border bg-background px-2 text-sm"
                  value={condition.operator}
                  onChange={(event) => {
                    const operator = event.target.value as CrossingOperator
                    setCondition(index, { ...condition, operator, value: defaultValue(source, operator) })
                  }}
                >
                  {operators.map((operator) => (
                    <option key={operator} value={operator}>
                      {operatorLabels[operator]}
                    </option>
                  ))}
                </select>
                {condition.operator === "band" ? (
                  <select
                    aria-label={`Franja de la condición ${index + 1}`}
                    className="h-9 rounded-md border bg-background px-2 text-sm"
                    value={String(condition.value)}
                    onChange={(event) => setCondition(index, { ...condition, value: event.target.value })}
                  >
                    {source?.bands.map((band) => (
                      <option key={band.name} value={band.name}>
                        {band.name}
                      </option>
                    ))}
                  </select>
                ) : condition.operator === "level" ? (
                  <select
                    aria-label={`Nivel de la condición ${index + 1}`}
                    className="h-9 rounded-md border bg-background px-2 text-sm"
                    value={String(condition.value)}
                    onChange={(event) => setCondition(index, { ...condition, value: Number(event.target.value) })}
                  >
                    {source?.levels.map((level) => (
                      <option key={level.id} value={String(level.id)}>
                        {level.name}
                      </option>
                    ))}
                  </select>
                ) : (
                  <Input
                    aria-label={`Valor de la condición ${index + 1}`}
                    type="number"
                    value={String(condition.value)}
                    onChange={(event) => setCondition(index, { ...condition, value: Number(event.target.value) })}
                  />
                )}
                <Button
                  type="button"
                  size="sm"
                  variant="ghost"
                  aria-label={`Quitar la condición ${index + 1}`}
                  onClick={() => {
                    setInput((current) => ({
                      ...current,
                      conditions: current.conditions.filter((_, position) => position !== index),
                    }))
                    setPreview(null)
                  }}
                >
                  <Trash2 aria-hidden="true" />
                </Button>
              </div>
            )
          })}
          {input.conditions.length < 2 && sources.length > 0 && (
            <div>
              <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() => {
                  const next = freshCondition(sources[0])
                  if (next) setInput((current) => ({ ...current, conditions: [...current.conditions, next] }))
                  setPreview(null)
                }}
              >
                <Plus aria-hidden="true" />
                Agregar condición
              </Button>
            </div>
          )}
        </fieldset>

        <div className="grid gap-1">
          <Label htmlFor="crossing-reading">Qué dice la lectura (la escribe el colegio)</Label>
          <Textarea
            id="crossing-reading"
            rows={3}
            placeholder="Por ejemplo: No se asigna una conclusión cerrada; el equipo revisa la herramienta de lectura."
            value={input.reading}
            onChange={(event) => setInput((current) => ({ ...current, reading: event.target.value }))}
          />
        </div>

        {preview && (
          <p className="rounded-md border bg-muted/40 p-3 text-sm" aria-live="polite">
            Vista previa con los datos de hoy: {previewText(preview)}
          </p>
        )}
        {error && (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        )}

        <div className="flex flex-wrap gap-2">
          <Button type="button" disabled={busy} onClick={() => void save()}>
            Guardar el cruce
          </Button>
          <Button type="button" variant="outline" disabled={busy} onClick={() => void runPreview()}>
            Ver a quiénes alcanza hoy
          </Button>
          <Button type="button" variant="ghost" onClick={onCancel}>
            Cancelar
          </Button>
        </div>
      </div>
    </SectionCard>
  )
}
