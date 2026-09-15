import { useEffect, useState } from "react"
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
import { SingleSelect } from "@/components/ui/single-select"
import { getCurrentSchoolYear } from "@/lib/schoolYear"
import { formatShortDate } from "@/lib/utils"
import {
  assessmentTypeLabels,
  performanceMarkColors,
  performanceMarkTypeLabels,
  type PerformanceMark,
  type PerformanceResultPoint,
  type StudentPerformanceTimeline,
} from "@/types"
import { fetchStudentPerformanceTimeline } from "./performanceApi"

interface StudentPerformanceChartProps {
  studentId: number
  /**
   * Subjects the student has data in, passed down by the parent
   * (`StudentTrackingPage` already holds `by_subject`) so the selector lists
   * exactly the relevant subjects without an extra fetch. Absent/empty → the
   * selector still renders with only the "Todas las materias" default.
   */
  subjects?: { id: number; name: string }[]
}

/** The default date window: the whole current school year. */
function defaultRange(): { from: string; to: string } {
  const year = getCurrentSchoolYear()
  return { from: `${year}-01-01`, to: `${year}-12-31` }
}

/**
 * The type-specific detail shown in a mark's tooltip, after its label and date.
 * Only the two variants that carry human-readable content expand — `reason` for
 * an instance override, `title` for a calendar event (spec §5). The rest are
 * fully described by their label alone.
 */
function markDetail(mark: PerformanceMark): string | null {
  switch (mark.type) {
    case "accommodation_instance_override":
      return mark.reason
    case "calendar_event":
      return mark.title
    default:
      return null
  }
}

/** The full tooltip text for a mark: label · date · detail. */
function markTooltip(mark: PerformanceMark): string {
  const detail = markDetail(mark)
  const base = `${performanceMarkTypeLabels[mark.type]} · ${formatShortDate(mark.date)}`
  return detail ? `${base} · ${detail}` : base
}

interface ResultDatum {
  x: number
  score: number
  administered_at: string
  assessment_type: PerformanceResultPoint["assessment_type"]
}

/**
 * Perfil de alumno performance chart (docs/prompts/26-frontend-perfil-de-
 * alumno.md). Consumes GET /students/{student}/performance-timeline (backend
 * Sesión 10) and draws the `results` line plus every entry of `marks` as a
 * mark over the X axis at its own date.
 *
 * The component never filters or hides marks by role — it renders exactly what
 * the response brings; deciding which mark types a viewer may see is the
 * backend's responsibility (CLAUDE.md: authorization lives in server code, the
 * frontend is never the security boundary).
 */
export function StudentPerformanceChart({
  studentId,
  subjects = [],
}: StudentPerformanceChartProps) {
  const [range, setRange] = useState(defaultRange)
  // "" = every subject (the "Todas las materias" default).
  const [subjectId, setSubjectId] = useState("")
  const [timeline, setTimeline] = useState<StudentPerformanceTimeline | null>(null)
  const [error, setError] = useState<string | null>(null)

  const { from, to } = range

  useEffect(() => {
    // Guard against an out-of-order response: changing `from` then `to` fires
    // two fetches, and the slower one must not clobber the newer selection.
    // The cleanup flips `ignore` so a stale in-flight request is dropped (same
    // pattern as AuthProvider).
    let ignore = false
    setError(null)
    fetchStudentPerformanceTimeline(studentId, {
      from,
      to,
      subjectId: subjectId ? Number(subjectId) : undefined,
    })
      .then((data) => {
        if (!ignore) setTimeline(data)
      })
      .catch(() => {
        if (ignore) return
        setError("No pudimos cargar el desempeño del alumno.")
        setTimeline(null)
      })
    return () => {
      ignore = true
    }
  }, [studentId, from, to, subjectId])

  return (
    <SectionCard
      title="Desempeño"
      action={
        <div className="flex flex-wrap items-end gap-3">
          <div className="grid gap-1.5">
            <Label htmlFor="performance-subject">Materia</Label>
            <SingleSelect
              id="performance-subject"
              className="max-w-52"
              options={[
                { value: "", label: "Todas las materias" },
                ...subjects.map((subject) => ({
                  value: String(subject.id),
                  label: subject.name,
                })),
              ]}
              value={subjectId}
              onChange={setSubjectId}
            />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="performance-from">Desde</Label>
            <Input
              id="performance-from"
              type="date"
              value={from}
              max={to || undefined}
              onChange={(event) => setRange((prev) => ({ ...prev, from: event.target.value }))}
              className="max-w-44"
            />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="performance-to">Hasta</Label>
            <Input
              id="performance-to"
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
        <PerformanceChartBody timeline={timeline} />
      )}
    </SectionCard>
  )
}

function PerformanceChartBody({ timeline }: { timeline: StudentPerformanceTimeline }) {
  const { results, marks } = timeline

  // Empty results → an explicit message, never an empty/broken chart (spec §5).
  // Marks are intentionally NOT enough to draw the chart on their own: without a
  // score line there is no Y dimension to place them against.
  if (results.length === 0) {
    return <p className="text-muted-foreground">Sin evaluaciones en este rango.</p>
  }

  const resultData: ResultDatum[] = results.map((result) => ({
    x: new Date(result.administered_at).getTime(),
    score: result.score,
    administered_at: result.administered_at,
    assessment_type: result.assessment_type,
  }))

  const markPoints = marks.map((mark) => ({ ts: new Date(mark.date).getTime(), mark }))

  // X domain covers both the score line and every mark, so a mark that falls
  // before the first assessment or after the last still lands on the axis
  // instead of being clipped. A single-point line gets a ±1 day pad so it is
  // not a degenerate zero-width domain.
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
          <Tooltip cursor={{ strokeDasharray: "3 3" }} content={<ResultTooltip />} />
          <Line
            type="monotone"
            dataKey="score"
            stroke="#2563eb"
            strokeWidth={2}
            isAnimationActive={false}
            dot={{ r: 3 }}
            name="Puntaje"
          />
          {markPoints.map(({ ts, mark }, index) => (
            <ReferenceDot
              // Marks of the same type on the same day are legitimate distinct
              // events, so the index is part of the key — never dedup by date.
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
              {performanceMarkTypeLabels[type]}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

/**
 * The custom shape for one mark: a filled circle in the mark type's palette
 * colour, carrying an SVG `<title>` so hovering it shows the label, date and
 * type-specific detail (spec §5) — a real, accessible hover tooltip that does
 * not depend on chart-cursor geometry.
 */
function MarkDot({
  cx,
  cy,
  mark,
}: {
  cx?: number
  cy?: number
  mark: PerformanceMark
}) {
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

/** Tooltip content for a hovered point on the score line. */
function ResultTooltip({
  active,
  payload,
}: {
  active?: boolean
  payload?: { payload: ResultDatum }[]
}) {
  if (!active || !payload || payload.length === 0) return null
  const datum = payload[0].payload
  return (
    <div className="rounded-md border bg-background p-2 text-xs shadow-sm">
      <p className="font-medium">{assessmentTypeLabels[datum.assessment_type]}</p>
      <p className="text-muted-foreground">{formatShortDate(datum.administered_at)}</p>
      <p>Puntaje: {datum.score}</p>
    </div>
  )
}
