import { useCallback, useEffect, useState } from "react"
import { Link, useParams } from "react-router-dom"
import { Ban, CircleCheck, FileText, HeartHandshake, TriangleAlert } from "lucide-react"
import { useAuth } from "@/features/auth/AuthContext"
import {
  canApproveAccommodation,
  canManageAccommodations,
  canResolveAlert,
  canViewStudentHistory,
} from "@/lib/permissions"
import { formatShortDate } from "@/lib/utils"
import { Badge, type BadgeTone } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { EmptyState } from "@/components/ui/empty-state"
import { PageHeader } from "@/components/ui/page-header"
import { SectionCard } from "@/components/ui/section-card"
import { StatCard } from "@/components/ui/stat-card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import {
  accommodationCategoryLabels,
  alertSeverityLabels,
  alertTypeLabels,
  assessmentTypeLabels,
  type Accommodation,
  type Alert,
  type Comment,
  type StudentTracking,
} from "@/types"
import { AccommodationFormDialog } from "./AccommodationFormDialog"
import { AccommodationInstanceOverrideForm } from "./AccommodationInstanceOverrideForm"
import { BarrierAccommodationsPanel } from "./BarrierAccommodationsPanel"
import { CommentsPanel } from "./CommentsPanel"
import { ScheduledFollowUpsPanel } from "./ScheduledFollowUpsPanel"
import { StudentPerformanceChart } from "./StudentPerformanceChart"
import * as trackingApi from "./trackingApi"
import type { CommentInput } from "./trackingApi"

const severityTone: Record<Alert["severity"], BadgeTone> = {
  low: "neutral",
  medium: "warning",
  high: "danger",
}

export function StudentTrackingPage() {
  const { id } = useParams<{ id: string }>()
  const studentId = Number(id)
  const { user } = useAuth()
  const showResolve = canResolveAlert(user)
  const showApprove = canApproveAccommodation(user)
  const showManageAccommodations = canManageAccommodations(user)
  const showHistoryLink = canViewStudentHistory(user)

  const [tracking, setTracking] = useState<StudentTracking | null>(null)
  const [comments, setComments] = useState<Comment[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [accommodationDialogOpen, setAccommodationDialogOpen] = useState(false)
  const [editingAccommodation, setEditingAccommodation] = useState<Accommodation | null>(null)

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

  /**
   * Approve/reject a single accommodation IN PLACE using the response payload,
   * not a full `loadTracking()` refetch (docs/prompts/09 §3). The tracking
   * aggregate is cached ~60s on the server, so a refetch right after the write
   * may still return the old value; splicing the returned Accommodation into
   * local state avoids that stale window entirely.
   */
  function replaceAccommodation(next: Accommodation) {
    setTracking((current) => {
      if (!current || !current.accommodations) return current
      return {
        ...current,
        accommodations: current.accommodations.map((accommodation) =>
          accommodation.id === next.id ? next : accommodation,
        ),
      }
    })
  }

  async function handleApprove(accommodationId: number) {
    const updated = await trackingApi.approveAccommodation(accommodationId)
    replaceAccommodation(updated)
  }

  async function handleReject(accommodationId: number) {
    const updated = await trackingApi.rejectAccommodation(accommodationId)
    replaceAccommodation(updated)
  }

  function handleNewAccommodation() {
    setEditingAccommodation(null)
    setAccommodationDialogOpen(true)
  }

  function handleEditAccommodation(accommodation: Accommodation) {
    setEditingAccommodation(accommodation)
    setAccommodationDialogOpen(true)
  }

  /**
   * After creating/editing an accommodation, a full refetch is enough (§4):
   * unlike approve/reject this writes a field no other path changes, so it does
   * not race the ~60s server cache the way the in-place splice avoids.
   */
  function handleAccommodationSaved() {
    loadTracking()
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
      <PageHeader
        backTo="/alumnos"
        backLabel="Volver a alumnos"
        title={`Seguimiento — ${student.full_name}`}
      >
        {showHistoryLink && (
          <Link
            className="text-sm text-primary underline-offset-4 hover:underline"
            to={`/alumnos/${studentId}/historial`}
          >
            Ver historial de auditoría →
          </Link>
        )}
      </PageHeader>

      <div className="grid gap-4 sm:grid-cols-3">
        <StatCard
          label="Alertas abiertas"
          value={tracking.open_alerts_count}
          icon={TriangleAlert}
          tone="danger"
        />
        <StatCard
          label="Adaptaciones vigentes"
          value={tracking.accommodations_count}
          icon={HeartHandshake}
          tone="info"
        />
        <StatCard
          label="Barreras activas"
          value={tracking.barriers_count}
          icon={Ban}
          tone="warning"
        />
      </div>

      <SectionCard title="Datos del alumno">
        <div className="grid gap-2 text-sm">
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
        </div>
      </SectionCard>

      <SectionCard title="Alertas abiertas">
        {tracking.alerts ? (
          tracking.alerts.length === 0 ? (
            <EmptyState icon={CircleCheck} message="Sin alertas abiertas." />
          ) : (
            <ul className="grid gap-3">
              {tracking.alerts.map((alert) => (
                <li
                  key={alert.id}
                  className="flex items-start justify-between gap-4 rounded-md border p-3"
                >
                  <div>
                    <div className="flex items-center gap-2">
                      <Badge tone={severityTone[alert.severity]}>
                        {alertSeverityLabels[alert.severity]}
                      </Badge>
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
          <p className="text-sm text-muted-foreground">
            {tracking.open_alerts_count} alerta(s) abierta(s). No tenés permiso para ver el detalle
            clínico.
          </p>
        )}
      </SectionCard>

      {tracking.accommodations && (
        <SectionCard
          title="Adaptaciones vigentes"
          action={
            // When the list is empty the CTA lives in the empty state instead,
            // so there is exactly one "Nueva adaptación" affordance per state.
            showManageAccommodations && tracking.accommodations.length > 0 ? (
              <Button type="button" size="sm" onClick={handleNewAccommodation}>
                Nueva adaptación
              </Button>
            ) : undefined
          }
        >
          {tracking.accommodations.length === 0 ? (
            <EmptyState
              icon={HeartHandshake}
              message="Sin adaptaciones vigentes."
              action={
                showManageAccommodations && (
                  <Button type="button" size="sm" variant="outline" onClick={handleNewAccommodation}>
                    Nueva adaptación
                  </Button>
                )
              }
            />
          ) : (
            <ul className="grid gap-2">
              {tracking.accommodations.map((accommodation) => {
                const pendingApproval =
                  accommodation.requires_external_approval && accommodation.approved === null
                return (
                  <li
                    key={accommodation.id}
                    className="rounded-md border p-3 text-sm"
                  >
                    <div className="flex items-start justify-between gap-3">
                      <div>
                        <span className="font-medium">{accommodation.type}</span>
                        {accommodation.category && (
                          <Badge tone="neutral" className="ml-2">
                            {accommodationCategoryLabels[accommodation.category]}
                          </Badge>
                        )}
                        {accommodation.description && (
                          <p className="text-muted-foreground">{accommodation.description}</p>
                        )}
                      </div>
                      <div className="flex items-center gap-2">
                        <AccommodationStatusBadge accommodation={accommodation} />
                        {showManageAccommodations && (
                          <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => handleEditAccommodation(accommodation)}
                          >
                            Editar
                          </Button>
                        )}
                      </div>
                    </div>
                    {pendingApproval && showApprove && (
                      <div className="mt-2 flex gap-2">
                        <Button
                          type="button"
                          size="sm"
                          onClick={() => handleApprove(accommodation.id)}
                        >
                          Aprobar
                        </Button>
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          onClick={() => handleReject(accommodation.id)}
                        >
                          Rechazar
                        </Button>
                      </div>
                    )}
                    {accommodation.is_effective && (
                      <AccommodationInstanceOverrideForm
                        accommodationId={accommodation.id}
                        assessments={tracking.recent_assessments}
                      />
                    )}
                  </li>
                )
              })}
            </ul>
          )}
        </SectionCard>
      )}

      {tracking.barriers && (
        <SectionCard title="Barreras activas">
          {tracking.barriers.length === 0 ? (
            <EmptyState icon={Ban} message="Sin barreras activas." />
          ) : (
            <ul className="grid gap-2">
              {tracking.barriers.map((barrier) => (
                <li key={barrier.id} className="rounded-md border p-3 text-sm">
                  <p>{barrier.description}</p>
                  {barrier.coping_strategy && (
                    <p className="text-muted-foreground">Estrategia: {barrier.coping_strategy}</p>
                  )}
                  <BarrierAccommodationsPanel
                    barrierId={barrier.id}
                    studentAccommodations={tracking.accommodations ?? []}
                  />
                </li>
              ))}
            </ul>
          )}
        </SectionCard>
      )}

      <SectionCard title="Evaluaciones recientes" bare={tracking.recent_assessments.length > 0}>
        {tracking.recent_assessments.length === 0 ? (
          <EmptyState icon={FileText} message="Sin evaluaciones recientes." />
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-6">Tipo</TableHead>
                <TableHead>Variante</TableHead>
                <TableHead className="pr-6">Fecha</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {tracking.recent_assessments.map((assessment) => (
                <TableRow key={assessment.id}>
                  <TableCell className="pl-6">{assessmentTypeLabels[assessment.type]}</TableCell>
                  <TableCell>{assessment.variant_number ?? "—"}</TableCell>
                  <TableCell className="pr-6">{formatShortDate(assessment.created_at)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </SectionCard>

      <StudentPerformanceChart studentId={studentId} />

      <ScheduledFollowUpsPanel studentId={studentId} />

      <CommentsPanel
        comments={comments ?? []}
        onCreate={handleCreateComment}
        title="Comentarios del alumno"
      />

      {showManageAccommodations && (
        <AccommodationFormDialog
          open={accommodationDialogOpen}
          onOpenChange={setAccommodationDialogOpen}
          studentId={studentId}
          accommodation={editingAccommodation}
          onSaved={handleAccommodationSaved}
        />
      )}
    </div>
  )
}

/**
 * Status pill for an accommodation. Two independent axes:
 *   - When `requires_external_approval`: show the approval decision
 *     ("Aprobada" / "Rechazada") or, if still `null`, "Pendiente de
 *     aprobación".
 *   - Otherwise: fall back to the vigency flag `is_effective` — no approval
 *     badge is added when approval does not apply (docs/prompts/09 §3).
 */
function AccommodationStatusBadge({ accommodation }: { accommodation: Accommodation }) {
  if (accommodation.requires_external_approval) {
    if (accommodation.approved === true) {
      return <Badge tone="success">Aprobada</Badge>
    }
    if (accommodation.approved === false) {
      return <Badge tone="danger">Rechazada</Badge>
    }
    return <Badge tone="warning">Pendiente de aprobación</Badge>
  }

  return accommodation.is_effective ? (
    <Badge tone="neutral">Vigente</Badge>
  ) : (
    <Badge tone="neutral">No vigente</Badge>
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
