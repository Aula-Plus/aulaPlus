import { useEffect, useState } from "react"
import { CheckCircle2, PenLine } from "lucide-react"
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
import { Card, CardContent } from "@/components/ui/card"
import { EmptyState } from "@/components/ui/empty-state"
import { PageHeader } from "@/components/ui/page-header"
import { SectionCard } from "@/components/ui/section-card"
import { StatCard } from "@/components/ui/stat-card"
import { NOT_AVAILABLE_LABEL, journeyStepLabels } from "@/types"
import type { AdoptionDashboard, JourneyDataSince, JourneyStep, WeeklyContentByTypePoint } from "@/types"
import { fetchAdoptionDashboard } from "./adoptionApi"
import { AdoptionTeachersTab } from "./AdoptionTeachersTab"
import { NoAccessPage } from "@/pages/NoAccessPage"

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
    return <NoAccessPage />
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
            {dashboard.teacher_started_rate_30d === null ? (
              <NotAvailableStat label="Docentes que empezaron a crear" />
            ) : (
              <StatCard
                label="Docentes que empezaron a crear"
                value={dashboard.teacher_started_rate_30d}
                suffix="%"
                icon={PenLine}
              />
            )}
            <StatCard
              label="Docentes con creaciones confirmadas"
              value={dashboard.teacher_planning_rate_30d}
              suffix="%"
              icon={CheckCircle2}
            />
          </div>

          <ContentByTypeSeries
            title="Contenido creado por semana"
            series={dashboard.weekly_content_by_type}
          />

          <DataSinceList dataSince={dashboard.data_since} />
        </>
      )}
    </div>
  )
}

/** A headline metric whose step has no record yet: never shown as 0. */
function NotAvailableStat({ label }: { label: string }) {
  return (
    <Card>
      <CardContent className="grid gap-0.5">
        <p className="text-base font-medium text-muted-foreground">{NOT_AVAILABLE_LABEL}</p>
        <p className="text-sm text-muted-foreground">{label}</p>
      </CardContent>
    </Card>
  )
}

// Steps listed in "Desde cuándo hay datos", in journey order.
const DATA_SINCE_STEPS: JourneyStep[] = [
  "form_opened",
  "filling_started",
  "generation_requested",
  "generation_succeeded",
  "generation_failed",
  "creation_confirmed",
  "draft_discarded",
]

/**
 * Since when each recorded step has data in this school — the earliest
 * record of it — so a missing figure reads as "not recorded yet", never as
 * "the teacher didn't do it".
 */
function DataSinceList({ dataSince }: { dataSince: JourneyDataSince }) {
  return (
    <SectionCard title="Desde cuándo hay datos">
      <dl className="grid gap-2 text-sm sm:grid-cols-2">
        {DATA_SINCE_STEPS.map((step) => (
          <div key={step} className="flex flex-wrap justify-between gap-x-4">
            <dt className="text-muted-foreground">{journeyStepLabels[step]}</dt>
            <dd>
              {dataSince[step] ? `desde ${formatShortDate(dataSince[step])}` : NOT_AVAILABLE_LABEL}
            </dd>
          </div>
        ))}
      </dl>
    </SectionCard>
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
 * the only kinds recorded as usage today). A visually-hidden data table is the
 * accessible/table view of the same numbers (dataviz skill), wrapped in a
 * sr-only *div*: `sr-only` on a `display: table` element is ignored and would
 * add phantom page scroll.
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
