import { useEffect, useState } from "react"
import { SectionCard } from "@/components/ui/section-card"
import { fetchChart, type ChartAxis, type ChartData, type ChartPoint, type IndicatorDataCatalog } from "./indicatorsApi"
import { formatNumber, populationText, sourceLabel } from "./indicatorText"

const WIDTH = 720
const HEIGHT = 420
const PAD = 56

/**
 * «Gráfico de cruces» (P30-GRA-02): one datum per axis and a group or the
 * whole school. Each point is a student with both data; the dotted lines are
 * the band cuts the school configured. No order and no score list: it is a
 * chart and not a table precisely so it never becomes a ranking. The name
 * shows only on hover (the point's <title>).
 *
 * Indicators marked «Gráfico de cruces» come first; the other data can be
 * chosen too, so a Primaria subject read by level of progress has its own
 * axis of levels, in the scale's order (never turned into a number).
 */
export function CrossChartTab({ catalog }: { catalog: IndicatorDataCatalog }) {
  const options = [
    ...catalog.sources.filter((source) => source.type === "indicator" && source.in_cross_chart),
    ...catalog.sources.filter((source) => source.type !== "indicator"),
  ]
  const [x, setX] = useState(options[0] ? `${options[0].type}:${options[0].id}` : "")
  const [y, setY] = useState(options[1] ? `${options[1].type}:${options[1].id}` : "")
  const [groupId, setGroupId] = useState("")
  const [chart, setChart] = useState<ChartData | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!x || !y) return
    let cancelled = false
    const [xType, xId] = x.split(":")
    const [yType, yId] = y.split(":")
    fetchChart(
      { type: xType as ChartAxis["type"], id: Number(xId) },
      { type: yType as ChartAxis["type"], id: Number(yId) },
      groupId ? Number(groupId) : undefined,
    ).then(
      (data) => {
        if (cancelled) return
        setError(null)
        setChart(data)
      },
      () => {
        if (!cancelled) setError("No pudimos armar el gráfico.")
      },
    )
    return () => {
      cancelled = true
    }
  }, [x, y, groupId])

  if (options.length < 2) {
    return (
      <SectionCard title="Gráfico de cruces">
        <p className="text-sm text-muted-foreground">
          Hacen falta dos datos para el gráfico: un indicador marcado para verse en el gráfico de cruces, u otro dato.
        </p>
      </SectionCard>
    )
  }

  const select = (label: string, value: string, onChange: (value: string) => void) => (
    <label className="grid gap-1 text-sm font-medium">
      {label}
      <select
        className="h-9 rounded-md border bg-background px-2 text-sm"
        value={value}
        onChange={(event) => onChange(event.target.value)}
      >
        {options.map((source) => (
          <option key={`${source.type}:${source.id}`} value={`${source.type}:${source.id}`}>
            {sourceLabel(source.type, source.name)}
          </option>
        ))}
      </select>
    </label>
  )

  return (
    <SectionCard title="Gráfico de cruces">
      <div className="grid gap-4">
        <div className="grid gap-3 sm:grid-cols-3">
          {select("Eje horizontal", x, setX)}
          {select("Eje vertical", y, setY)}
          <label className="grid gap-1 text-sm font-medium">
            Grupo
            <select
              className="h-9 rounded-md border bg-background px-2 text-sm"
              value={groupId}
              onChange={(event) => setGroupId(event.target.value)}
            >
              <option value="">Todo el colegio</option>
              {catalog.groups.map((group) => (
                <option key={group.id} value={String(group.id)}>
                  {group.name}
                </option>
              ))}
            </select>
          </label>
        </div>

        {error ? (
          <p className="text-sm text-destructive">{error}</p>
        ) : chart === null ? (
          <p className="text-sm text-muted-foreground">Cargando el gráfico…</p>
        ) : (
          <>
            <ScatterSvg chart={chart} />
            <p className="text-xs text-muted-foreground">
              {chart.points.length} {chart.points.length === 1 ? "alumno" : "alumnos"} con los dos datos. Las líneas
              punteadas son los cortes de franja que configuró el colegio. Sin orden ni puntaje: pasá el cursor por un
              punto para ver quién es.
            </p>
          </>
        )}
      </div>
    </SectionCard>
  )
}

interface Range {
  min: number
  max: number
}

/** The axis range: from the data and the cuts, padded (as the prototype). */
function rangeOf(axis: ChartAxis, values: number[]): Range {
  if (axis.kind === "level" && axis.levels.length > 0) {
    const positions = axis.levels.map((level) => level.position)
    return { min: Math.min(...positions) - 0.5, max: Math.max(...positions) + 0.5 }
  }
  const all = [...values, ...axis.cuts]
  if (all.length === 0) return { min: 0, max: 1 }
  const low = Math.min(...all)
  const high = Math.max(...all)
  const spread = high - low || 1
  return { min: low - spread * 0.06, max: high + spread * 0.06 }
}

function ticksOf(axis: ChartAxis, range: Range): { value: number; label: string }[] {
  if (axis.kind === "level") return axis.levels.map((level) => ({ value: level.position, label: level.name }))
  const step = (range.max - range.min) / 4
  return Array.from({ length: 5 }, (_, index) => {
    const value = range.min + step * index
    return { value, label: formatNumber(Math.round(value * 10) / 10) }
  })
}

function pointTitle(point: ChartPoint, chart: ChartData): string {
  const part = (axis: ChartAxis, value: number, label: string | null, population: ChartPoint["x_population"]) => {
    const shown = axis.kind === "level" ? (label ?? "") : `${formatNumber(value)}${label ? ` (${label})` : ""}`
    return `${axis.name ?? ""} ${shown}${population ? `, ${populationText(population)}` : ""}`
  }
  return [
    point.name,
    point.group_name,
    part(chart.x, point.x, point.x_label, point.x_population),
    part(chart.y, point.y, point.y_label, point.y_population),
  ].join(" · ")
}

/**
 * On an axis of levels many students share a position: a small fixed offset
 * (from the point's index, not its value) keeps them from hiding each other.
 */
function jitter(axis: ChartAxis, index: number): number {
  return axis.kind === "level" ? (((index * 37) % 7) - 3) * 0.06 : 0
}

function ScatterSvg({ chart }: { chart: ChartData }) {
  if (chart.points.length === 0) {
    return <p className="text-sm text-muted-foreground">Ningún alumno tiene los dos datos todavía.</p>
  }

  const xRange = rangeOf(chart.x, chart.points.map((point) => point.x))
  const yRange = rangeOf(chart.y, chart.points.map((point) => point.y))
  const toX = (value: number) => PAD + ((WIDTH - 2 * PAD) * (value - xRange.min)) / (xRange.max - xRange.min || 1)
  const toY = (value: number) => HEIGHT - PAD - ((HEIGHT - 2 * PAD) * (value - yRange.min)) / (yRange.max - yRange.min || 1)

  return (
    <div className="overflow-x-auto">
      <svg
        viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
        width="100%"
        className="max-w-3xl"
        role="img"
        aria-label={`Gráfico de cruces: ${chart.x.name ?? ""} y ${chart.y.name ?? ""}`}
      >
        <rect
          x={PAD}
          y={PAD}
          width={WIDTH - 2 * PAD}
          height={HEIGHT - 2 * PAD}
          className="fill-muted/30 stroke-border"
        />
        {ticksOf(chart.x, xRange).map((tick) => (
          <text key={`xt-${tick.value}`} x={toX(tick.value)} y={HEIGHT - PAD + 16} textAnchor="middle" fontSize="11" className="fill-muted-foreground">
            {tick.label}
          </text>
        ))}
        {ticksOf(chart.y, yRange).map((tick) => (
          <text key={`yt-${tick.value}`} x={PAD - 6} y={toY(tick.value) + 4} textAnchor="end" fontSize="11" className="fill-muted-foreground">
            {tick.label}
          </text>
        ))}
        {chart.x.cuts.map((cut) => (
          <line
            key={`xc-${cut}`}
            data-testid="band-cut"
            x1={toX(cut)}
            x2={toX(cut)}
            y1={PAD}
            y2={HEIGHT - PAD}
            strokeDasharray="4 4"
            className="stroke-muted-foreground/60"
          />
        ))}
        {chart.y.cuts.map((cut) => (
          <line
            key={`yc-${cut}`}
            data-testid="band-cut"
            y1={toY(cut)}
            y2={toY(cut)}
            x1={PAD}
            x2={WIDTH - PAD}
            strokeDasharray="4 4"
            className="stroke-muted-foreground/60"
          />
        ))}
        <text x={WIDTH / 2} y={HEIGHT - 12} textAnchor="middle" fontSize="12" className="fill-muted-foreground">
          {chart.x.name}
        </text>
        <text
          x={14}
          y={HEIGHT / 2}
          textAnchor="middle"
          fontSize="12"
          transform={`rotate(-90 14 ${HEIGHT / 2})`}
          className="fill-muted-foreground"
        >
          {chart.y.name}
        </text>
        {chart.points.map((point, index) => (
          <circle
            key={index}
            data-testid="chart-point"
            cx={toX(point.x + jitter(chart.x, index))}
            cy={toY(point.y + jitter(chart.y, index))}
            r={5}
            className="fill-primary/60 stroke-background"
          >
            <title>{pointTitle(point, chart)}</title>
          </circle>
        ))}
      </svg>
    </div>
  )
}
