import { useEffect, useState } from "react"
import { LogIn, PenLine } from "lucide-react"
import {
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Legend,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts"
import { useAuth } from "@/features/auth/AuthContext"
import { canViewAdoptionDashboard } from "@/lib/permissions"
import { formatShortDate } from "@/lib/utils"
import { EmptyState } from "@/components/ui/empty-state"
import { PageHeader } from "@/components/ui/page-header"
import { SectionCard } from "@/components/ui/section-card"
import { StatCard } from "@/components/ui/stat-card"
import type { AdoptionDashboard, WeeklyContentByTypePoint, WeeklySeriesPoint } from "@/types"
import { fetchAdoptionDashboard } from "./adoptionApi"
import { AdoptionTeachersTab } from "./AdoptionTeachersTab"

export function AdoptionDashboardPage() {
  const { user } = useAuth()
  const schoolId = user?.school?.id
  // UX gate only — the server (SchoolPolicy::viewAdoptionDashboard) is the
  // real boundary and already restricts this to the school's director.
  const allowed = canViewAdoptionDashboard(user)

  const [dashboard, setDashboard] = useState<AdoptionDashboard | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [tab, setTab] = useState<"summary" | "teachers">("summary")

  useEffect(() => {
    if (!allowed || schoolId === undefined) return
    fetchAdoptionDashboard(schoolId)
      .then((data) => setDashboard(data))
      .catch(() => setError("No pudimos cargar el tablero de adopción."))
  }, [allowed, schoolId])

  if (!allowed) {
    return (
      <p className="text-muted-foreground">
        El tablero de adopción está disponible solo para dirección.
      </p>
    )
  }

  if (schoolId === undefined) {
    return <p className="text-muted-foreground">No tenés una escuela asignada.</p>
  }

  if (error) {
    return <p className="text-sm text-destructive">{error}</p>
  }

  if (!dashboard) {
    return <p className="text-muted-foreground">Cargando…</p>
  }

  return (
    <div className="grid gap-6">
      <PageHeader
        title="Tablero de adopción"
        description="Indicadores de uso del piloto (últimos 30 días)"
      />

      <div role="tablist" aria-label="Vistas del tablero" className="flex gap-2">
        <TabButton active={tab === "summary"} onClick={() => setTab("summary")}>
          Resumen
        </TabButton>
        <TabButton active={tab === "teachers"} onClick={() => setTab("teachers")}>
          Por docente
        </TabButton>
      </div>

      {tab === "teachers" ? (
        <AdoptionTeachersTab schoolId={schoolId} />
      ) : (
        <>
          <div className="grid gap-4 sm:grid-cols-2">
            <StatCard
              label="Docentes con login"
              value={dashboard.teacher_login_rate_30d}
              suffix="%"
              icon={LogIn}
            />
            <StatCard
              label="Docentes con planificación"
              value={dashboard.teacher_planning_rate_30d}
              suffix="%"
              icon={PenLine}
            />
          </div>

          <WeeklySeries title="Logins por semana" series={dashboard.weekly_login_series} />
          <ContentByTypeSeries
            title="Contenido creado por semana"
            series={dashboard.weekly_content_by_type}
          />
        </>
      )}
    </div>
  )
}

function TabButton({
  active,
  onClick,
  children,
}: {
  active: boolean
  onClick: () => void
  children: React.ReactNode
}) {
  return (
    <button
      type="button"
      role="tab"
      aria-selected={active}
      onClick={onClick}
      className={
        "rounded-md px-3 py-1.5 text-sm font-medium transition-colors " +
        (active ? "bg-primary text-primary-foreground" : "bg-muted text-muted-foreground")
      }
    >
      {children}
    </button>
  )
}

// Categorical series for the stacked chart — fixed hues readable in both
// themes; the legend and the accessible table name every series.
const CONTENT_TYPES = [
  { key: "annual_plans", label: "Programas", color: "var(--color-primary)" },
  { key: "class_sessions", label: "Clases", color: "#0d9488" },
  { key: "assessments", label: "Evaluaciones", color: "#d97706" },
] as const

/**
 * Content created per week, stacked by type (programs, classes, assessments —
 * the only kinds recorded as usage today). Same accessible-table convention as
 * WeeklySeries.
 */
function ContentByTypeSeries({
  title,
  series,
}: {
  title: string
  series: WeeklyContentByTypePoint[]
}) {
  const data = series.map((point) => ({
    ...point,
    label: formatShortDate(point.week_start),
  }))

  return (
    <SectionCard title={title}>
      {data.length === 0 ? (
        <EmptyState message="Sin datos en este período." />
      ) : (
        <>
          <div className="h-56 w-full">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: -16 }}>
                <CartesianGrid vertical={false} stroke="var(--color-border)" />
                <XAxis
                  dataKey="label"
                  tickLine={false}
                  axisLine={false}
                  tick={{ fill: "var(--color-muted-foreground)", fontSize: 12 }}
                />
                <YAxis
                  allowDecimals={false}
                  tickLine={false}
                  axisLine={false}
                  width={40}
                  tick={{ fill: "var(--color-muted-foreground)", fontSize: 12 }}
                />
                <Tooltip
                  cursor={{ fill: "var(--color-muted)" }}
                  contentStyle={{
                    borderRadius: 8,
                    border: "1px solid var(--color-border)",
                    background: "var(--color-popover)",
                    color: "var(--color-popover-foreground)",
                    fontSize: 12,
                  }}
                  labelStyle={{ color: "var(--color-muted-foreground)" }}
                />
                <Legend wrapperStyle={{ fontSize: 12 }} />
                {CONTENT_TYPES.map((type) => (
                  <Bar
                    key={type.key}
                    dataKey={type.key}
                    name={type.label}
                    stackId="content"
                    fill={type.color}
                    maxBarSize={48}
                  />
                ))}
              </BarChart>
            </ResponsiveContainer>
          </div>

          <div className="sr-only">
            <table aria-label={title}>
              <thead>
                <tr>
                  <th>Semana</th>
                  {CONTENT_TYPES.map((type) => (
                    <th key={type.key}>{type.label}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {data.map((point) => (
                  <tr key={point.week_start}>
                    <td>{point.label}</td>
                    {CONTENT_TYPES.map((type) => (
                      <td key={type.key}>{point[type.key]}</td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </SectionCard>
  )
}

interface WeeklyDatum {
  label: string
  count: number
}

/**
 * Weekly counts as a single-series bar chart — magnitude over time, so bars,
 * one brand hue, no legend (the title names the series). A visually-hidden
 * data table is the accessible/table view of the same numbers (dataviz skill:
 * a table view always exists), and is what non-visual readers get.
 */
function WeeklySeries({ title, series }: { title: string; series: WeeklySeriesPoint[] }) {
  const data: WeeklyDatum[] = series.map((point) => ({
    label: formatShortDate(point.week_start),
    count: point.count,
  }))

  return (
    <SectionCard title={title}>
      {data.length === 0 ? (
        <EmptyState message="Sin datos en este período." />
      ) : (
        <>
          <div className="h-56 w-full">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: -16 }}>
                <CartesianGrid vertical={false} stroke="var(--color-border)" />
                <XAxis
                  dataKey="label"
                  tickLine={false}
                  axisLine={false}
                  tick={{ fill: "var(--color-muted-foreground)", fontSize: 12 }}
                />
                <YAxis
                  allowDecimals={false}
                  tickLine={false}
                  axisLine={false}
                  width={40}
                  tick={{ fill: "var(--color-muted-foreground)", fontSize: 12 }}
                />
                <Tooltip
                  cursor={{ fill: "var(--color-muted)" }}
                  contentStyle={{
                    borderRadius: 8,
                    border: "1px solid var(--color-border)",
                    background: "var(--color-popover)",
                    color: "var(--color-popover-foreground)",
                    fontSize: 12,
                  }}
                  labelStyle={{ color: "var(--color-muted-foreground)" }}
                />
                <Bar
                  dataKey="count"
                  name="Eventos"
                  fill="var(--color-primary)"
                  radius={[4, 4, 0, 0]}
                  maxBarSize={48}
                />
              </BarChart>
            </ResponsiveContainer>
          </div>

          {/* Accessible/table view of the same series (dataviz skill). Named via
              aria-label (not a visible caption) so it doesn't duplicate the
              section title. Wrapped in a sr-only *div* — `sr-only` on a
              `display: table` element is ignored (its 1px width/height act only
              as minimums), leaving a 200px+ off-screen box that adds phantom
              page scroll; a block wrapper collapses correctly. */}
          <div className="sr-only">
            <table aria-label={title}>
              <thead>
                <tr>
                  <th>Semana</th>
                  <th>Eventos</th>
                </tr>
              </thead>
              <tbody>
                {data.map((point) => (
                  <tr key={point.label}>
                    <td>{point.label}</td>
                    <td>{point.count}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </SectionCard>
  )
}
