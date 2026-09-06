import { useEffect, useState } from "react"
import { useAuth } from "@/features/auth/AuthContext"
import { canViewAdoptionDashboard } from "@/lib/permissions"
import { formatShortDate } from "@/lib/utils"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import type { AdoptionDashboard, WeeklySeriesPoint } from "@/types"
import { fetchAdoptionDashboard } from "./adoptionApi"

export function AdoptionDashboardPage() {
  const { user } = useAuth()
  const schoolId = user?.school?.id
  // UX gate only — the server (SchoolPolicy::viewAdoptionDashboard) is the
  // real boundary and already restricts this to the school's director.
  const allowed = canViewAdoptionDashboard(user)

  const [dashboard, setDashboard] = useState<AdoptionDashboard | null>(null)
  const [error, setError] = useState<string | null>(null)

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
      <div>
        <h1 className="text-2xl font-semibold">Tablero de adopción</h1>
        <p className="text-muted-foreground">
          Indicadores de uso del piloto (últimos 30 días).
        </p>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <Rate label="Docentes con login" value={dashboard.teacher_login_rate_30d} />
        <Rate label="Docentes con planificación" value={dashboard.teacher_planning_rate_30d} />
      </div>

      <SeriesTable title="Logins por semana" series={dashboard.weekly_login_series} />
      <SeriesTable title="Contenido creado por semana" series={dashboard.weekly_content_series} />
    </div>
  )
}

function Rate({ label, value }: { label: string; value: number }) {
  return (
    <Card>
      <CardContent className="pt-6">
        <p className="text-3xl font-semibold">{value}%</p>
        <p className="text-sm text-muted-foreground">{label}</p>
      </CardContent>
    </Card>
  )
}

function SeriesTable({ title, series }: { title: string; series: WeeklySeriesPoint[] }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
      </CardHeader>
      <CardContent>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Semana</TableHead>
              <TableHead>Eventos</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {series.map((point) => (
              <TableRow key={point.week_start}>
                <TableCell>{formatShortDate(point.week_start)}</TableCell>
                <TableCell>{point.count}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </CardContent>
    </Card>
  )
}
