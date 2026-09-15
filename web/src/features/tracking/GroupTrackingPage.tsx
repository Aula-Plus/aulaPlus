import { useCallback, useEffect, useState } from "react"
import { useParams } from "react-router-dom"
import { FileText, MessageSquare, Users } from "lucide-react"
import { useAuth } from "@/features/auth/AuthContext"
import { canManageScreeningTests } from "@/lib/permissions"
import { Badge } from "@/components/ui/badge"
import { EmptyState } from "@/components/ui/empty-state"
import { PageHeader } from "@/components/ui/page-header"
import { RowLink } from "@/components/ui/row-link"
import { SectionCard } from "@/components/ui/section-card"
import { StatCard } from "@/components/ui/stat-card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import type { Comment, GroupTracking } from "@/types"
import { CommentsPanel } from "./CommentsPanel"
import { GroupAccommodationsSummary } from "./GroupAccommodationsSummary"
import { GroupOverdueFollowUps } from "./GroupOverdueFollowUps"
import { GroupPerformanceChart } from "./GroupPerformanceChart"
import * as trackingApi from "./trackingApi"
import type { CommentInput } from "./trackingApi"

export function GroupTrackingPage() {
  const { id } = useParams<{ id: string }>()
  const groupId = Number(id)
  const { user } = useAuth()
  const canScreen = canManageScreeningTests(user)

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

  async function handleCreateComment(input: CommentInput) {
    await trackingApi.createGroupComment(groupId, input)
    await loadComments()
  }

  if (error) {
    return <p className="text-sm text-destructive">{error}</p>
  }

  if (!tracking) {
    return <p className="text-muted-foreground">Cargando…</p>
  }

  const { group, trend } = tracking

  return (
    <div className="grid gap-6">
      <PageHeader
        backTo="/clases"
        backLabel="Volver a clases"
        title={`Seguimiento — ${group.name}`}
        description={`Tendencia de los últimos ${trend.period_days} días`}
      >
        <RowLink to={`/clases/${group.id}/evaluaciones`}>Evaluaciones</RowLink>
        {canScreen && (
          <RowLink to={`/clases/${group.id}/pruebas-de-sondeo`}>Pruebas de sondeo</RowLink>
        )}
      </PageHeader>

      {/*
        `comments_count` is omitted by the backend for a viewer without a
        school-wide role (docs/prompts/19-comentarios-alcance.md §5), so we
        hide that card entirely instead of rendering a misleading `0`, and drop
        to a single column when it is absent rather than leaving a gap.
      */}
      <div className={`grid gap-4 ${trend.comments_count !== undefined ? "sm:grid-cols-2" : ""}`}>
        <StatCard label="Evaluaciones tomadas" value={trend.assessments_count} icon={FileText} />
        {trend.comments_count !== undefined && (
          <StatCard label="Comentarios cargados" value={trend.comments_count} icon={MessageSquare} />
        )}
      </div>

      <SectionCard title="Alumnos" bare={tracking.students.length > 0}>
        {tracking.students.length === 0 ? (
          <EmptyState icon={Users} message="La clase no tiene alumnos." />
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-6">Nombre</TableHead>
                <TableHead>Alertas abiertas</TableHead>
                <TableHead>Adaptaciones activas</TableHead>
                <TableHead className="pr-6" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {tracking.students.map((student) => (
                <TableRow key={student.id}>
                  <TableCell className="pl-6 font-medium">{student.full_name}</TableCell>
                  <TableCell>
                    {student.open_alerts_count > 0 ? (
                      <Badge tone="danger">{student.open_alerts_count}</Badge>
                    ) : (
                      <span className="text-muted-foreground">0</span>
                    )}
                  </TableCell>
                  <TableCell>{student.has_active_accommodations ? "Sí" : "No"}</TableCell>
                  <TableCell className="pr-6 text-right">
                    <RowLink to={`/alumnos/${student.id}/seguimiento`}>Ver seguimiento</RowLink>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </SectionCard>

      <GroupAccommodationsSummary groupId={group.id} />

      <GroupPerformanceChart groupId={group.id} studentCount={tracking.students.length} />

      <GroupOverdueFollowUps groupId={group.id} />

      <CommentsPanel
        comments={comments ?? []}
        onCreate={handleCreateComment}
        title="Comentarios de la clase"
      />
    </div>
  )
}
