import { useCallback, useEffect, useState } from "react"
import { useParams } from "react-router-dom"
import { Users } from "lucide-react"
import { useAuth } from "@/features/auth/AuthContext"
import {
  canManageScreeningTests,
  canRecalculateScreeningTestApplication,
  isSchoolWideStaff,
} from "@/lib/permissions"
import { Badge } from "@/components/ui/badge"
import { EmptyState } from "@/components/ui/empty-state"
import { PageHeader } from "@/components/ui/page-header"
import { RowLink } from "@/components/ui/row-link"
import { SectionCard } from "@/components/ui/section-card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import type { Comment, CommentCategory, GroupTracking } from "@/types"
import { CommentsPanel } from "./CommentsPanel"
import { GroupAccommodationsSummary } from "./GroupAccommodationsSummary"
import { GroupOpenAlerts } from "./GroupOpenAlerts"
import { GroupOverdueFollowUps } from "./GroupOverdueFollowUps"
import { GroupPerformanceChart } from "./GroupPerformanceChart"
import * as trackingApi from "./trackingApi"
import type { CommentInput } from "./trackingApi"

export function GroupTrackingPage() {
  const { id } = useParams<{ id: string }>()
  const groupId = Number(id)
  const { user } = useAuth()
  // Dirección reaches the applications too, to «Recalcular con el nivel actual».
  const canScreen = canManageScreeningTests(user) || canRecalculateScreeningTestApplication(user)
  // Teachers don't get the per-student accommodation flag (the API omits it):
  // a yes/no per name is a roster. They read the aggregate table below.
  const showAccommodations = isSchoolWideStaff(user)

  const [tracking, setTracking] = useState<GroupTracking | null>(null)
  const [comments, setComments] = useState<Comment[] | null>(null)
  const [categories, setCategories] = useState<CommentCategory[]>([])
  const [error, setError] = useState<string | null>(null)

  const loadTracking = useCallback(() => {
    return trackingApi
      .fetchGroupTracking(groupId)
      .then((data) => setTracking(data))
      .catch(() => setError("No pudimos cargar el perfil del grupo."))
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
    trackingApi
      .fetchCommentCategories()
      .then(setCategories)
      .catch(() => setCategories([]))
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

  const { group } = tracking

  return (
    <div className="grid gap-6">
      <PageHeader
        backTo="/clases"
        backLabel="Volver a grupos"
        title={`Perfil del grupo — ${group.name}`}
      >
        <RowLink to={`/clases/${group.id}/evaluaciones`}>Evaluaciones</RowLink>
        {canScreen && (
          <RowLink to={`/clases/${group.id}/pruebas-de-sondeo`}>Screenings</RowLink>
        )}
      </PageHeader>

      <GroupOpenAlerts
        groupId={group.id}
        studentNames={Object.fromEntries(tracking.students.map((s) => [s.id, s.full_name]))}
      />

      <SectionCard title="Alumnos" bare={tracking.students.length > 0}>
        {tracking.students.length === 0 ? (
          <EmptyState icon={Users} message="El grupo no tiene alumnos." />
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-6">Nombre</TableHead>
                <TableHead>Alertas abiertas</TableHead>
                {showAccommodations && <TableHead>Ajustes activos</TableHead>}
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
                  {showAccommodations && (
                    <TableCell>{student.has_active_accommodations ? "Sí" : "No"}</TableCell>
                  )}
                  <TableCell className="pr-6 text-right">
                    <RowLink to={`/alumnos/${student.id}/seguimiento`}>Ver alumno</RowLink>
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
        title="Comentarios del grupo"
        categories={categories}
      />
    </div>
  )
}
