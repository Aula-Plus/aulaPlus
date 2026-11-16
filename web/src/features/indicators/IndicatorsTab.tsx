import { useEffect, useState } from "react"
import { Plus, Trash2 } from "lucide-react"
import { Button } from "@/components/ui/button"
import { ConfirmDialog } from "@/components/ui/confirm-dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { SectionCard } from "@/components/ui/section-card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { Textarea } from "@/components/ui/textarea"
import { screeningColorLabels, screeningColors, type ScreeningColor } from "@/types"
import {
  deleteIndicator,
  fetchIndicators,
  previewIndicator,
  saveIndicator,
  type Indicator,
  type IndicatorBand,
  type IndicatorCalculation,
  type IndicatorDataCatalog,
  type IndicatorDisplayPlace,
  type IndicatorInput,
  type IndicatorSummary,
  type SourceRef,
  type ZScorePopulation,
} from "./indicatorsApi"
import {
  calculationLabels,
  displayPlaceLabels,
  populationText,
  sourceLabel,
  zPopulationLabels,
} from "./indicatorText"

const CALCULATIONS = Object.keys(calculationLabels) as IndicatorCalculation[]
const DISPLAY_PLACES = Object.keys(displayPlaceLabels) as IndicatorDisplayPlace[]
const POPULATIONS = Object.keys(zPopulationLabels) as ZScorePopulation[]

/** How many data each calculation takes (mirror of IndicatorCalculation::inputRange). */
const inputRange: Record<IndicatorCalculation, [number, number]> = {
  raw: [1, 1],
  z_score: [1, 1],
  average: [2, 6],
  difference: [2, 2],
}

const emptyBand = (color: ScreeningColor, max: number | null): IndicatorBand => ({ name: "", color, max, meaning: "" })

function blankInput(): IndicatorInput {
  return {
    name: "",
    calculation: "raw",
    inputs: [],
    base_100: false,
    z_population: null,
    bands: [emptyBand("red", 39), emptyBand("yellow", 59), emptyBand("green", null)],
    display_places: ["cross_chart"],
    teacher_visible: false,
  }
}

/**
 * «Indicadores» (P30-IND-02/03): the school's indicators and the form to
 * build one. The bands and what each one means are written by the school;
 * nothing comes from the AI. The preview is aggregate: how many students
 * have a value and how many fall in each band, never who.
 */
export function IndicatorsTab({ catalog, onChanged }: { catalog: IndicatorDataCatalog; onChanged: () => Promise<void> }) {
  const [indicators, setIndicators] = useState<Indicator[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const [editing, setEditing] = useState<{ id?: number; input: IndicatorInput } | null>(null)

  async function load() {
    try {
      setIndicators(await fetchIndicators())
    } catch {
      setError("No pudimos cargar los indicadores.")
    }
  }

  useEffect(() => {
    void load()
  }, [])

  async function remove(indicator: Indicator) {
    try {
      await deleteIndicator(indicator.id)
      setActionError(null)
      await Promise.all([load(), onChanged()])
    } catch {
      setActionError(`No se puede borrar «${indicator.name}»: lo usa otro indicador o un cruce.`)
    }
  }

  async function afterSave() {
    setEditing(null)
    await Promise.all([load(), onChanged()])
  }

  return (
    <div className="grid gap-6">
      <SectionCard
        title="Indicadores"
        bare={!!indicators && indicators.length > 0}
        action={
          <Button type="button" size="sm" onClick={() => setEditing({ input: blankInput() })}>
            <Plus aria-hidden="true" />
            Nuevo indicador
          </Button>
        }
      >
        {actionError && (
          <p role="alert" className="p-4 text-sm text-destructive">
            {actionError}
          </p>
        )}
        {error ? (
          <p className="text-sm text-destructive">{error}</p>
        ) : indicators === null ? (
          <p className="text-sm text-muted-foreground">Cargando indicadores…</p>
        ) : indicators.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            Todavía no hay indicadores. Cada colegio arma los suyos con los datos que ya tiene.
          </p>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Indicador</TableHead>
                <TableHead>Qué se calcula</TableHead>
                <TableHead>Franjas</TableHead>
                <TableHead>Dónde se ve</TableHead>
                <TableHead>Quién lo ve</TableHead>
                <TableHead>Con los datos de hoy</TableHead>
                <TableHead>
                  <span className="sr-only">Acciones</span>
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {indicators.map((indicator) => (
                <TableRow key={indicator.id}>
                  <TableCell className="font-medium">{indicator.name}</TableCell>
                  <TableCell>
                    {calculationLabels[indicator.calculation]}
                    {indicator.base_100 ? ", en base 100" : ""}:{" "}
                    {indicator.inputs.map((input) => sourceLabel(input.type, input.name)).join(" · ")}
                    {indicator.z_population && ` (${zPopulationLabels[indicator.z_population].toLowerCase()})`}
                  </TableCell>
                  <TableCell>{indicator.bands.map((band) => band.name).join(" · ")}</TableCell>
                  <TableCell>
                    {indicator.display_places.length === 0
                      ? "Solo en esta pantalla"
                      : indicator.display_places.map((place) => displayPlaceLabels[place]).join(" · ")}
                  </TableCell>
                  <TableCell>
                    {indicator.teacher_visible ? "Psicopedagogía, Dirección y docentes" : "Psicopedagogía y Dirección"}
                  </TableCell>
                  <TableCell>
                    <SummaryText summary={indicator.summary} />
                  </TableCell>
                  <TableCell className="whitespace-nowrap">
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={() =>
                        setEditing({
                          id: indicator.id,
                          input: {
                            name: indicator.name,
                            calculation: indicator.calculation,
                            inputs: indicator.inputs.map(({ type, id }) => ({ type, id })),
                            base_100: indicator.base_100,
                            z_population: indicator.z_population,
                            bands: indicator.bands,
                            display_places: indicator.display_places,
                            teacher_visible: indicator.teacher_visible,
                          },
                        })
                      }
                    >
                      Editar
                    </Button>{" "}
                    <ConfirmDialog
                      trigger={
                        <Button type="button" size="sm" variant="ghost" aria-label={`Borrar ${indicator.name}`}>
                          <Trash2 aria-hidden="true" />
                        </Button>
                      }
                      title={`¿Borrar «${indicator.name}»?`}
                      description="Se borra el indicador. Los datos de los que sale no se tocan."
                      confirmLabel="Borrar"
                      onConfirm={() => void remove(indicator)}
                    />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </SectionCard>

      {editing && (
        <IndicatorForm
          key={editing.id ?? "new"}
          id={editing.id}
          initial={editing.input}
          catalog={catalog}
          onCancel={() => setEditing(null)}
          onSaved={afterSave}
        />
      )}

    </div>
  )
}

export function SummaryText({ summary }: { summary: IndicatorSummary }) {
  return (
    <span>
      {summary.students_with_data} de {summary.roster_count} alumnos con dato
      {summary.students_with_data > 0 && `: ${summary.bands.map((band) => `${band.name} ${band.count}`).join(" · ")}`}
      {summary.populations.length > 0 && (
        <span className="block text-xs text-muted-foreground">
          Puntaje Z {summary.populations.map(populationText).join("; ")}
        </span>
      )}
    </span>
  )
}

function IndicatorForm({
  id,
  initial,
  catalog,
  onCancel,
  onSaved,
}: {
  id?: number
  initial: IndicatorInput
  catalog: IndicatorDataCatalog
  onCancel: () => void
  onSaved: () => Promise<void>
}) {
  const [input, setInput] = useState<IndicatorInput>(initial)
  const [summary, setSummary] = useState<IndicatorSummary | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  // Any datum but this indicator itself. A level of progress without the
  // school's equivalence has no number, so it is left out of calculations.
  const options = catalog.sources.filter(
    (source) => !(source.type === "indicator" && source.id === id) && !(source.number_coverage === 0 && source.level_coverage > 0),
  )
  const [min, max] = inputRange[input.calculation]

  function update(patch: Partial<IndicatorInput>) {
    setInput((current) => ({ ...current, ...patch }))
    setSummary(null)
  }

  function updateBand(index: number, patch: Partial<IndicatorBand>) {
    update({ bands: input.bands.map((band, position) => (position === index ? { ...band, ...patch } : band)) })
  }

  function setInputAt(index: number, value: string) {
    const [type, rawId] = value.split(":")
    const next = [...input.inputs]
    next[index] = { type: type as SourceRef["type"], id: Number(rawId) }
    update({ inputs: next })
  }

  function check(): string | null {
    if (!input.name.trim()) return "Poné un nombre al indicador."
    if (input.inputs.length < min || input.inputs.length > max) {
      return min === max
        ? `${calculationLabels[input.calculation]} usa ${min === 1 ? "un dato" : `${min} datos`}.`
        : `${calculationLabels[input.calculation]} usa entre ${min} y ${max} datos.`
    }
    if (input.bands.length < 2 || input.bands.length > 5) return "Un indicador tiene de 2 a 5 franjas."
    if (input.bands.some((band) => !band.name.trim())) return "Cada franja necesita un nombre."
    if (input.bands.some((band) => !band.meaning.trim())) return "«Qué significa» es obligatorio en cada franja."
    const cuts = input.bands.slice(0, -1).map((band) => band.max)
    if (cuts.some((cut) => cut === null || Number.isNaN(cut))) return "Cada franja menos la última necesita hasta dónde llega."
    if (cuts.some((cut, index) => index > 0 && (cut as number) <= (cuts[index - 1] as number))) {
      return "Cada franja tiene que llegar más alto que la anterior."
    }
    return null
  }

  function payload(): IndicatorInput {
    return {
      ...input,
      name: input.name.trim(),
      bands: input.bands.map((band, index) => ({
        ...band,
        name: band.name.trim(),
        meaning: band.meaning.trim(),
        max: index === input.bands.length - 1 ? null : band.max,
      })),
      z_population: input.calculation === "z_score" ? (input.z_population ?? "grade_year") : null,
      base_100: input.calculation === "z_score" ? false : input.base_100,
    }
  }

  async function preview() {
    const problem = check()
    if (problem) return setError(problem)
    setError(null)
    setBusy(true)
    try {
      setSummary(await previewIndicator(payload()))
    } catch {
      setError("No pudimos calcular la vista previa. Revisá los datos y las franjas.")
    } finally {
      setBusy(false)
    }
  }

  async function save() {
    const problem = check()
    if (problem) return setError(problem)
    setError(null)
    setBusy(true)
    try {
      await saveIndicator(payload(), id)
      await onSaved()
    } catch {
      setError("No pudimos guardar el indicador. Revisá los datos y las franjas.")
      setBusy(false)
    }
  }

  return (
    <SectionCard title={id ? `Editar «${initial.name}»` : "Nuevo indicador"}>
      <div className="grid gap-5">
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="grid gap-1">
            <Label htmlFor="indicator-name">Nombre</Label>
            <Input
              id="indicator-name"
              placeholder="Por ejemplo: Herramienta de lectura (comprensión)"
              value={input.name}
              onChange={(event) => update({ name: event.target.value })}
            />
          </div>
          <label className="grid gap-1 text-sm font-medium">
            Qué se calcula
            <select
              className="h-9 rounded-md border bg-background px-2 text-sm"
              value={input.calculation}
              onChange={(event) => update({ calculation: event.target.value as IndicatorCalculation })}
            >
              {CALCULATIONS.map((calculation) => (
                <option key={calculation} value={calculation}>
                  {calculationLabels[calculation]}
                </option>
              ))}
            </select>
          </label>
        </div>

        <fieldset className="grid gap-2">
          <legend className="text-sm font-medium">Con qué datos</legend>
          {input.inputs.map((ref, index) => (
            <div key={index} className="flex items-center gap-2">
              <select
                aria-label={`Dato ${index + 1}`}
                className="h-9 flex-1 rounded-md border bg-background px-2 text-sm"
                value={`${ref.type}:${ref.id}`}
                onChange={(event) => setInputAt(index, event.target.value)}
              >
                {options.map((source) => (
                  <option key={`${source.type}:${source.id}`} value={`${source.type}:${source.id}`}>
                    {sourceLabel(source.type, source.name)}
                  </option>
                ))}
              </select>
              <Button
                type="button"
                size="sm"
                variant="ghost"
                aria-label={`Quitar el dato ${index + 1}`}
                onClick={() => update({ inputs: input.inputs.filter((_, position) => position !== index) })}
              >
                <Trash2 aria-hidden="true" />
              </Button>
            </div>
          ))}
          {input.inputs.length < max && options.length > 0 && (
            <div>
              <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() => update({ inputs: [...input.inputs, { type: options[0].type, id: options[0].id }] })}
              >
                <Plus aria-hidden="true" />
                Agregar dato
              </Button>
            </div>
          )}
          {options.length === 0 && (
            <p className="text-sm text-muted-foreground">Todavía no hay datos con números para calcular.</p>
          )}
          {input.calculation === "difference" && (
            <p className="text-xs text-muted-foreground">La diferencia es el primer dato menos el segundo.</p>
          )}
          {input.calculation === "z_score" ? (
            <label className="grid max-w-sm gap-1 text-sm font-medium">
              Población del puntaje Z
              <select
                className="h-9 rounded-md border bg-background px-2 text-sm"
                value={input.z_population ?? "grade_year"}
                onChange={(event) => update({ z_population: event.target.value as ZScorePopulation })}
              >
                {POPULATIONS.map((population) => (
                  <option key={population} value={population}>
                    {zPopulationLabels[population]}
                  </option>
                ))}
              </select>
              <span className="text-xs font-normal text-muted-foreground">
                Al lado de cada puntaje se muestra con qué población se calculó.
              </span>
            </label>
          ) : (
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={input.base_100}
                onChange={(event) => update({ base_100: event.target.checked })}
              />
              Usar todo en base 100 (siempre se guarda el número original)
            </label>
          )}
        </fieldset>

        <fieldset className="grid gap-2">
          <legend className="text-sm font-medium">Franjas</legend>
          <p className="text-xs text-muted-foreground">
            De 2 a 5. Cada una con nombre, color, hasta dónde llega y qué significa para el colegio. «Qué significa» es
            obligatorio.
          </p>
          {input.bands.map((band, index) => {
            const last = index === input.bands.length - 1
            return (
              <div key={index} className="grid gap-2 rounded-md border p-3 sm:grid-cols-[1fr_8rem_7rem]">
                <div className="grid gap-1">
                  <Label htmlFor={`band-${index}-name`}>Nombre de la franja {index + 1}</Label>
                  <Input
                    id={`band-${index}-name`}
                    value={band.name}
                    onChange={(event) => updateBand(index, { name: event.target.value })}
                  />
                </div>
                <label className="grid gap-1 text-sm font-medium">
                  Color
                  <select
                    className="h-9 rounded-md border bg-background px-2 text-sm"
                    value={band.color}
                    onChange={(event) => updateBand(index, { color: event.target.value as ScreeningColor })}
                  >
                    {screeningColors.map((color) => (
                      <option key={color} value={color}>
                        {screeningColorLabels[color]}
                      </option>
                    ))}
                  </select>
                </label>
                <div className="grid gap-1">
                  <Label htmlFor={`band-${index}-max`}>Hasta</Label>
                  {last ? (
                    <p id={`band-${index}-max`} className="h-9 py-2 text-sm text-muted-foreground">
                      Sin tope
                    </p>
                  ) : (
                    <Input
                      id={`band-${index}-max`}
                      type="number"
                      value={band.max ?? ""}
                      onChange={(event) =>
                        updateBand(index, { max: event.target.value === "" ? null : Number(event.target.value) })
                      }
                    />
                  )}
                </div>
                <div className="grid gap-1 sm:col-span-3">
                  <Label htmlFor={`band-${index}-meaning`}>Qué significa</Label>
                  <Textarea
                    id={`band-${index}-meaning`}
                    rows={2}
                    value={band.meaning}
                    onChange={(event) => updateBand(index, { meaning: event.target.value })}
                  />
                </div>
              </div>
            )
          })}
          <div className="flex gap-2">
            {input.bands.length < 5 && (
              <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() => {
                  const bands = [...input.bands]
                  const previous = bands[bands.length - 2]?.max ?? 0
                  bands.splice(bands.length - 1, 0, emptyBand("orange", (previous ?? 0) + 10))
                  update({ bands })
                }}
              >
                Agregar franja
              </Button>
            )}
            {input.bands.length > 2 && (
              <Button
                type="button"
                size="sm"
                variant="ghost"
                onClick={() => {
                  const bands = input.bands.slice(0, -1)
                  bands[bands.length - 1] = { ...bands[bands.length - 1], max: null }
                  update({ bands })
                }}
              >
                Quitar la última
              </Button>
            )}
          </div>
        </fieldset>

        <fieldset className="grid gap-2">
          <legend className="text-sm font-medium">Dónde se ve</legend>
          <div className="grid gap-1 sm:grid-cols-2">
            {DISPLAY_PLACES.map((place) => (
              <label key={place} className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={input.display_places.includes(place)}
                  onChange={(event) =>
                    update({
                      display_places: event.target.checked
                        ? [...input.display_places, place]
                        : input.display_places.filter((candidate) => candidate !== place),
                    })
                  }
                />
                {displayPlaceLabels[place]}
              </label>
            ))}
          </div>
          <p className="text-xs text-muted-foreground">
            En la ficha del alumno y en el perfil del grupo se muestra en una próxima entrega; hoy se guarda la elección.
          </p>
        </fieldset>

        <fieldset className="grid gap-2">
          <legend className="text-sm font-medium">Quién lo ve</legend>
          <p className="text-sm">Psicopedagogía y Dirección · siempre</p>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={input.teacher_visible}
              onChange={(event) => update({ teacher_visible: event.target.checked })}
            />
            + Docentes
          </label>
          <p className="text-xs text-muted-foreground">
            Viene apagado. Si se prende, el docente lo ve en la ficha de cada alumno y en su grupo, nunca en los cruces.
          </p>
        </fieldset>

        {summary && (
          <div className="rounded-md border bg-muted/40 p-3 text-sm" aria-live="polite">
            <p className="font-medium">Así queda con los datos de hoy</p>
            <SummaryText summary={summary} />
          </div>
        )}
        {error && (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        )}

        <div className="flex flex-wrap gap-2">
          <Button type="button" disabled={busy} onClick={() => void save()}>
            Guardar el indicador
          </Button>
          <Button type="button" variant="outline" disabled={busy} onClick={() => void preview()}>
            Ver cómo queda con los datos de hoy
          </Button>
          <Button type="button" variant="ghost" onClick={onCancel}>
            Cancelar
          </Button>
        </div>
      </div>
    </SectionCard>
  )
}
