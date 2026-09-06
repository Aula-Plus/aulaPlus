import { useCallback, useEffect, useState } from "react"
import { Link, useParams } from "react-router-dom"
import { useAuth } from "@/features/auth/AuthContext"
import { canResolveAlert } from "@/lib/permissions"
import { formatShortDate } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import {
  alertSeverityLabels,
  alertTypeLabels,
  assessmentTypeLabels,
  type Alert,
  type Comment,
  type StudentTracking,
} from "@/types"
import { CommentsPanel } from "./CommentsPanel"
import * as trackingApi from "./trackingApi"
import type { CommentInput } from "./trackingApi"

const severityBadgeClass: Record<Alert["severity"], string> = {
  low: "bg-muted text-muted-foreground",
  medium: "bg-amber-100 text-amber-800",
  high: "bg-red-100 text-red-800",
}

export function StudentTrackingPage() {
  const { id } = useParams<{ id: string }>()
  const studentId = Number(id)
  const { user } = useAuth()
  const showResolve = canResolveAlert(user)

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

  async function handleCreateComment(input: CommentInput) {
    await trackingApi.createStudentComment(studentId, input)
    await loadComments()
  }

  async function handleResolveAlert(alertId: number) {
    await trackingApi.resolveAlert(alertId)
    await loadTracking()
  }

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
        <Indicator label="Adaptaciones vigentes" value={tracking.accommodations_count} />
        <Indicator label="Barreras activas" value={tracking.barriers_count} />
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Datos del alumno</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-2 text-sm">
          <Row label="Año de ingreso" value={String(student.enrollment_year)} />
          <Row label="Fecha de nacimiento" value={formatShortDate(student.birth_date)} />
          <Row
            label="Acompañante terapéutico"
            value={student.has_therapeutic_companion ? "Sí" : "No"}
          />
          <Row
            label="Clases"
            value={student.groups.map((group) => group.name).join(", ") || "—"}
          />
        </CardContent>
      </Card>

      <section className="grid gap-3">
        <h2 className="text-lg font-semibold">Alertas abiertas</h2>
        {tracking.alerts ? (
          tracking.alerts.length === 0 ? (
            <p className="text-muted-foreground">Sin alertas abiertas.</p>
          ) : (
            <ul className="grid gap-3">
              {tracking.alerts.map((alert) => (
                <li
                  key={alert.id}
                  className="flex items-start justify-between gap-4 rounded-md border p-3"
                >
                  <div>
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
                  </div>
                  {showResolve && (
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => handleResolveAlert(alert.id)}
                    >
                      Resolver
                    </Button>
                  )}
                </li>
              ))}
            </ul>
          )
        ) : (
          <p className="text-muted-foreground">
            {tracking.open_alerts_count} alerta(s) abierta(s). No tenés permiso para ver el detalle
            clínico.
          </p>
        )}
      </section>

      {tracking.accommodations && (
        <section className="grid gap-3">
          <h2 className="text-lg font-semibold">Adaptaciones vigentes</h2>
          {tracking.accommodations.length === 0 ? (
            <p className="text-muted-foreground">Sin adaptaciones vigentes.</p>
          ) : (
            <ul className="grid gap-2">
              {tracking.accommodations.map((accommodation) => (
                <li key={accommodation.id} className="rounded-md border p-3 text-sm">
                  <span className="font-medium">{accommodation.type}</span>
                  {accommodation.description && (
                    <p className="text-muted-foreground">{accommodation.description}</p>
                  )}
                </li>
              ))}
            </ul>
          )}
        </section>
      )}

      {tracking.barriers && (
        <section className="grid gap-3">
          <h2 className="text-lg font-semibold">Barreras activas</h2>
          {tracking.barriers.length === 0 ? (
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
          )}
        </section>
      )}

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
                  <TableCell>{assessmentTypeLabels[assessment.type]}</TableCell>
                  <TableCell>{assessment.variant_number ?? "—"}</TableCell>
                  <TableCell>{formatShortDate(assessment.created_at)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </section>

      <CommentsPanel
        comments={comments ?? []}
        onCreate={handleCreateComment}
        title="Comentarios del alumno"
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

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between border-b py-1 last:border-b-0">
      <span className="text-muted-foreground">{label}</span>
      <span className="font-medium">{value}</span>
    </div>
  )
}
