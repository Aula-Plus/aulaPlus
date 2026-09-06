import { useCallback, useEffect, useState } from "react"
import { Link, useParams } from "react-router-dom"
import { formatShortDate } from "@/lib/utils"
import { Card, CardContent } from "@/components/ui/card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import {
  alertSeverityLabels,
  alertTypeLabels,
  type Alert,
  type Comment,
  type StudentTracking,
} from "@/types"
import { CommentsPanel } from "./CommentsPanel"
import * as trackingApi from "./trackingApi"

const severityBadgeClass: Record<Alert["severity"], string> = {
  low: "bg-muted text-muted-foreground",
  medium: "bg-amber-100 text-amber-800",
  high: "bg-red-100 text-red-800",
}

export function StudentTrackingPage() {
  const { id } = useParams<{ id: string }>()
  const studentId = Number(id)

  const [tracking, setTracking] = useState<StudentTracking | null>(null)
  const [comments, setComments] = useState<Comment[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  const loadTracking = useCallback(() => {
    return trackingApi
      .fetchStudentTracking(studentId)
      .then((data) => setTracking(data))
      .catch(() => setError("No pudimos cargar el seguimiento del alumno."))
  }, [studentId])

  const loadComments = useCallback(() => {
    return trackingApi
      .fetchStudentComments(studentId)
      .then((data) => setComments(data))
      .catch(() => setComments([]))
  }, [studentId])

  useEffect(() => {
    loadTracking()
    loadComments()
  }, [loadTracking, loadComments])

  if (error) {
    return <p className="text-sm text-destructive">{error}</p>
  }

  if (!tracking) {
    return <p className="text-muted-foreground">Cargando…</p>
  }

  const { student } = tracking

  return (
    <div className="grid gap-6">
      <div>
        <Link className="text-sm text-primary underline-offset-4 hover:underline" to="/alumnos">
          ← Volver a alumnos
        </Link>
        <h1 className="mt-1 text-2xl font-semibold">Seguimiento — {student.full_name}</h1>
      </div>

      <div className="grid gap-4 sm:grid-cols-3">
        <Indicator label="Alertas abiertas" value={tracking.open_alerts_count} />
        <Indicator label="Medidas de apoyo vigentes" value={tracking.accommodations_count} />
        <Indicator label="Barreras activas" value={tracking.barriers_count} />
      </div>

      <section className="grid gap-3">
        <h2 className="text-lg font-semibold">Evaluaciones recientes</h2>
        {tracking.recent_assessments.length === 0 ? (
          <p className="text-muted-foreground">Sin evaluaciones recientes.</p>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Tipo</TableHead>
                <TableHead>Variante</TableHead>
                <TableHead>Fecha</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {tracking.recent_assessments.map((assessment) => (
                <TableRow key={assessment.id}>
                  <TableCell>{assessment.type}</TableCell>
                  <TableCell>{assessment.variant_number}</TableCell>
                  <TableCell>{formatShortDate(assessment.created_at)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </section>

      <section className="grid gap-3">
        <h2 className="text-lg font-semibold">Medidas de apoyo</h2>
        {tracking.accommodations ? (
          tracking.accommodations.length === 0 ? (
            <p className="text-muted-foreground">Sin medidas de apoyo registradas.</p>
          ) : (
            <ul className="grid gap-2">
              {tracking.accommodations.map((accommodation) => (
                <li key={accommodation.id} className="rounded-md border p-3 text-sm">
                  <div className="flex items-center gap-2">
                    <span className="font-medium">{accommodation.type}</span>
                    <span
                      className={`rounded px-2 py-0.5 text-xs font-medium ${
                        accommodation.is_effective
                          ? "bg-green-100 text-green-800"
                          : "bg-muted text-muted-foreground"
                      }`}
                    >
                      {accommodation.is_effective ? "Vigente" : "No vigente"}
                    </span>
                  </div>
                  <p className="mt-1 text-muted-foreground">{accommodation.description}</p>
                  {accommodation.focus_area && (
                    <p className="text-xs text-muted-foreground">
                      Área: {accommodation.focus_area}
                    </p>
                  )}
                </li>
              ))}
            </ul>
          )
        ) : (
          <p className="text-muted-foreground">
            {tracking.accommodations_count} medida(s) de apoyo registrada(s).
          </p>
        )}
      </section>

      <section className="grid gap-3">
        <h2 className="text-lg font-semibold">Barreras</h2>
        {tracking.barriers ? (
          tracking.barriers.length === 0 ? (
            <p className="text-muted-foreground">Sin barreras activas.</p>
          ) : (
            <ul className="grid gap-2">
              {tracking.barriers.map((barrier) => (
                <li key={barrier.id} className="rounded-md border p-3 text-sm">
                  <p>{barrier.description}</p>
                  {barrier.coping_strategy && (
                    <p className="text-muted-foreground">Estrategia: {barrier.coping_strategy}</p>
                  )}
                </li>
              ))}
            </ul>
          )
        ) : (
          <p className="text-muted-foreground">{tracking.barriers_count} barrera(s) registrada(s).</p>
        )}
      </section>

      <section className="grid gap-3">
        <h2 className="text-lg font-semibold">Alertas</h2>
        {tracking.alerts ? (
          tracking.alerts.length === 0 ? (
            <p className="text-muted-foreground">Sin alertas.</p>
          ) : (
            <ul className="grid gap-3">
              {tracking.alerts.map((alert) => (
                <li key={alert.id} className="rounded-md border p-3">
                  <div className="flex items-center gap-2">
                    <span
                      className={`rounded px-2 py-0.5 text-xs font-medium ${severityBadgeClass[alert.severity]}`}
                    >
                      {alertSeverityLabels[alert.severity]}
                    </span>
                    <span className="text-xs text-muted-foreground">
                      {alertTypeLabels[alert.type]} · {formatShortDate(alert.created_at)}
                    </span>
                  </div>
                  <p className="mt-1 text-sm">{alert.description}</p>
                </li>
              ))}
            </ul>
          )
        ) : (
          <p className="text-muted-foreground">
            {tracking.open_alerts_count} alerta(s) abierta(s).
          </p>
        )}
      </section>

      <CommentsPanel
        subject={{ type: "student", id: studentId }}
        comments={comments ?? []}
        onCommentAdded={loadComments}
      />
    </div>
  )
}

function Indicator({ label, value }: { label: string; value: number }) {
  return (
    <Card>
      <CardContent className="pt-6">
        <p className="text-3xl font-semibold">{value}</p>
        <p className="text-sm text-muted-foreground">{label}</p>
      </CardContent>
    </Card>
  )
}
