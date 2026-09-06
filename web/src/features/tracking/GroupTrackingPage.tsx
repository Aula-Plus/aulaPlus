import { useCallback, useEffect, useState } from "react"
import { Link, useParams } from "react-router-dom"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import type { Comment, GroupTracking } from "@/types"
import { CommentsPanel } from "./CommentsPanel"
import * as trackingApi from "./trackingApi"

export function GroupTrackingPage() {
  const { id } = useParams<{ id: string }>()
  const groupId = Number(id)

  const [tracking, setTracking] = useState<GroupTracking | null>(null)
  const [comments, setComments] = useState<Comment[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  const loadTracking = useCallback(() => {
    return trackingApi
      .fetchGroupTracking(groupId)
      .then((data) => setTracking(data))
      .catch(() => setError("No pudimos cargar el seguimiento de la clase."))
  }, [groupId])

  const loadComments = useCallback(() => {
    return trackingApi
      .fetchGroupComments(groupId)
      .then((data) => setComments(data))
      .catch(() => setComments([]))
  }, [groupId])

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

  const { group, trend } = tracking

  return (
    <div className="grid gap-6">
      <div>
        <Link className="text-sm text-primary underline-offset-4 hover:underline" to="/clases">
          ← Volver a clases
        </Link>
        <h1 className="mt-1 text-2xl font-semibold">Seguimiento — {group.name}</h1>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Tendencia (últimos {trend.period_days} días)</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4 sm:grid-cols-2">
          <div>
            <p className="text-3xl font-semibold">{trend.assessments_count}</p>
            <p className="text-sm text-muted-foreground">Evaluaciones tomadas</p>
          </div>
          <div>
            <p className="text-3xl font-semibold">{trend.comments_count}</p>
            <p className="text-sm text-muted-foreground">Comentarios cargados</p>
          </div>
        </CardContent>
      </Card>

      <section className="grid gap-3">
        <h2 className="text-lg font-semibold">Alumnos</h2>
        {tracking.students.length === 0 ? (
          <p className="text-muted-foreground">La clase no tiene alumnos.</p>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Nombre</TableHead>
                <TableHead>Alertas abiertas</TableHead>
                <TableHead>Medidas de apoyo</TableHead>
                <TableHead />
              </TableRow>
            </TableHeader>
            <TableBody>
              {tracking.students.map((student) => (
                <TableRow key={student.id}>
                  <TableCell>{student.full_name}</TableCell>
                  <TableCell>{student.open_alerts_count}</TableCell>
                  <TableCell>{student.has_active_accommodations ? "✓" : "—"}</TableCell>
                  <TableCell className="text-right">
                    <Link
                      className="text-primary underline-offset-4 hover:underline"
                      to={`/alumnos/${student.id}/seguimiento`}
                    >
                      Ver seguimiento
                    </Link>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </section>

      <CommentsPanel
        subject={{ type: "group", id: groupId }}
        comments={comments ?? []}
        onCommentAdded={loadComments}
      />
    </div>
  )
}
