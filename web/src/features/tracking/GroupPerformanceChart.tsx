import { useCallback, useEffect, useState } from "react"
import {
  CartesianGrid,
  ComposedChart,
  Line,
  ReferenceDot,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { SectionCard } from "@/components/ui/section-card"
import { getCurrentSchoolYear } from "@/lib/schoolYear"
import { formatShortDate } from "@/lib/utils"
import {
  assessmentTypeLabels,
  groupPerformanceMarkTypeLabels,
  performanceMarkColors,
  type GroupPerformanceMark,
  type GroupPerformanceResultPoint,
  type GroupPerformanceTimeline,
} from "@/types"
import { fetchGroupPerformanceTimeline } from "./performanceApi"

interface GroupPerformanceChartProps {
  groupId: number
  /**
   * The group's total student count, used only as the denominator in a point's
   * tooltip ("22 de 25 alumnos", spec §6). Optional: when omitted the tooltip
   * shows just the numerator ("22 alumnos"), since the timeline endpoint itself
   * does not carry the group size.
   */
  studentCount?: number
}

/** The default date window: the whole current school year. */
function defaultRange(): { from: string; to: string } {
  const year = getCurrentSchoolYear()
  return { from: `${year}-01-01`, to: `${year}-12-31` }
}

/**
 * The tooltip text for one aggregated mark: its label, its count and any
 * type-specific detail (spec §6, e.g. "Adaptaciones activadas: 2"). Every mark
 * is a (type, date) aggregate — none carries a student identifier, so the
 * tooltip never can either.
 */
function markTooltip(mark: GroupPerformanceMark): string {
  const label = groupPerformanceMarkTypeLabels[mark.type]
  const date = formatShortDate(mark.date)
  switch (mark.type) {
    case "accommodation_activated":
    case "accommodation_deactivated":
      return `${label}: ${mark.count} · ${mark.accommodation_type} · ${date}`
    case "barrier_registered":
    case "concerning_comment":
      return `${label}: ${mark.count} · ${date}`
    case "calendar_event":
      return `${label} · ${mark.title} · ${date}`
  }
}

export interface ResultDatum {
  x: number
  average_score: number
  results_count: number
  administered_at: string
  assessment_type: GroupPerformanceResultPoint["assessment_type"]
}

/**
 * Perfil de grupo performance chart (docs/prompts/27-frontend-perfil-de-grupo.md
 * §6). Reuses the Recharts ComposedChart pattern of StudentPerformanceChart
 * (Sesión 14) — same score line + marks-as-ReferenceDots layout, same palette
 * (`performanceMarkColors`) — with two group-specific differences the shared
 * shape does not cover: the line is `average_score` (not a single score) and a
 * point's tooltip reports `results_count`. It never filters marks by role;
 * which mark types appear was decided server-side (CLAUDE.md).
 */
export function GroupPerformanceChart({ groupId, studentCount }: GroupPerformanceChartProps) {
  const [range, setRange] = useState(defaultRange)
  const [timeline, setTimeline] = useState<GroupPerformanceTimeline | null>(null)
  const [error, setError] = useState<string | null>(null)

  const { from, to } = range

  const load = useCallback(async () => {
    setError(null)
    try {
      const data = await fetchGroupPerformanceTimeline(groupId, { from, to })
      setTimeline(data)
    } catch {
      setError("No pudimos cargar el desempeño de la clase.")
      setTimeline(null)
    }
  }, [groupId, from, to])

  useEffect(() => {
    load()
  }, [load])

  return (
    <SectionCard
      title="Desempeño del grupo"
      action={
        <div className="flex flex-wrap items-end gap-3">
          <div className="grid gap-1.5">
            <Label htmlFor="group-performance-from">Desde</Label>
            <Input
              id="group-performance-from"
              type="date"
              value={from}
              max={to || undefined}
              onChange={(event) => setRange((prev) => ({ ...prev, from: event.target.value }))}
              className="max-w-44"
            />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="group-performance-to">Hasta</Label>
            <Input
              id="group-performance-to"
              type="date"
              value={to}
              min={from || undefined}
              onChange={(event) => setRange((prev) => ({ ...prev, to: event.target.value }))}
              className="max-w-44"
            />
          </div>
        </div>
      }
    >
      {error ? (
        <p className="text-sm text-destructive">{error}</p>
      ) : timeline === null ? (
        <p className="text-muted-foreground">Cargando…</p>
      ) : (
        <GroupPerformanceChartBody timeline={timeline} studentCount={studentCount} />
      )}
    </SectionCard>
  )
}

function GroupPerformanceChartBody({
  timeline,
  studentCount,
}: {
  timeline: GroupPerformanceTimeline
  studentCount?: number
}) {
  const { results, marks } = timeline

  // Empty results → an explicit message, never an empty/broken chart. Marks
  // alone are not enough to draw: without a score line there is no Y dimension
  // to place them against (same rule as StudentPerformanceChart).
  if (results.length === 0) {
    return <p className="text-muted-foreground">Sin evaluaciones en este rango.</p>
  }

  const resultData: ResultDatum[] = results.map((result) => ({
    x: new Date(result.administered_at).getTime(),
    average_score: result.average_score,
    results_count: result.results_count,
    administered_at: result.administered_at,
    assessment_type: result.assessment_type,
  }))

  const markPoints = marks.map((mark) => ({ ts: new Date(mark.date).getTime(), mark }))

  // X domain covers both the score line and every mark so a mark before the
  // first assessment or after the last still lands on the axis. A single point
  // gets a ±1 day pad so the domain is not degenerate.
  const timestamps = [...resultData.map((d) => d.x), ...markPoints.map((m) => m.ts)]
  let min = Math.min(...timestamps)
  let max = Math.max(...timestamps)
  if (min === max) {
    const DAY = 86_400_000
    min -= DAY
    max += DAY
  }

  const presentMarkTypes = [...new Set(marks.map((mark) => mark.type))]

  return (
    <div className="grid gap-3">
      <ResponsiveContainer width="100%" height={320}>
        <ComposedChart data={resultData} margin={{ top: 16, right: 16, bottom: 8, left: 0 }}>
          <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
          <XAxis
            dataKey="x"
            type="number"
            scale="time"
            domain={[min, max]}
            tickFormatter={(value: number) => formatShortDate(new Date(value).toISOString())}
            tick={{ fontSize: 12 }}
          />
          <YAxis domain={[0, "auto"]} tick={{ fontSize: 12 }} allowDecimals={false} />
          <Tooltip
            cursor={{ strokeDasharray: "3 3" }}
            content={<ResultTooltip studentCount={studentCount} />}
          />
          <Line
            type="monotone"
            dataKey="average_score"
            stroke="#2563eb"
            strokeWidth={2}
            isAnimationActive={false}
            dot={{ r: 3 }}
            name="Promedio"
          />
          {markPoints.map(({ ts, mark }, index) => (
            <ReferenceDot
              // Marks of the same type on the same day are legitimate distinct
              // aggregates, so the index is part of the key — never dedup by date.
              key={`${mark.type}-${ts}-${index}`}
              x={ts}
              y={0}
              ifOverflow="extendDomain"
              shape={(props) => <MarkDot cx={props.cx} cy={props.cy} mark={mark} />}
            />
          ))}
        </ComposedChart>
      </ResponsiveContainer>

      {presentMarkTypes.length > 0 && (
        <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
          {presentMarkTypes.map((type) => (
            <li key={type} className="flex items-center gap-1.5">
              <span
                aria-hidden="true"
                className="inline-block h-2.5 w-2.5 rounded-full"
                style={{ backgroundColor: performanceMarkColors[type] }}
              />
              {groupPerformanceMarkTypeLabels[type]}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

/**
 * One aggregated mark: a filled circle in the mark type's palette colour with an
 * SVG `<title>` so hovering shows its label, count and detail — a real,
 * accessible tooltip that does not depend on chart-cursor geometry.
 */
function MarkDot({ cx, cy, mark }: { cx?: number; cy?: number; mark: GroupPerformanceMark }) {
  if (cx === undefined || cy === undefined) return null
  return (
    <g>
      <circle
        cx={cx}
        cy={cy}
        r={6}
        fill={performanceMarkColors[mark.type]}
        stroke="#ffffff"
        strokeWidth={1.5}
      />
      <title>{markTooltip(mark)}</title>
    </g>
  )
}

/**
 * Tooltip for a hovered point on the group score line: the assessment type, its
 * date, the average score and how many students have a score loaded — e.g.
 * "7.4 (22 de 25 alumnos)" when the group size is known, "7.4 (22 alumnos)"
 * otherwise (spec §6).
 */
export function ResultTooltip({
  active,
  payload,
  studentCount,
}: {
  active?: boolean
  payload?: { payload: ResultDatum }[]
  studentCount?: number
}) {
  if (!active || !payload || payload.length === 0) return null
  const datum = payload[0].payload
  const coverage =
    studentCount !== undefined
      ? `${datum.results_count} de ${studentCount} alumnos`
      : `${datum.results_count} alumnos`
  return (
    <div className="rounded-md border bg-background p-2 text-xs shadow-sm">
      <p className="font-medium">{assessmentTypeLabels[datum.assessment_type]}</p>
      <p className="text-muted-foreground">{formatShortDate(datum.administered_at)}</p>
      <p>
        {datum.average_score} ({coverage})
      </p>
    </div>
  )
}
