import { useCallback, useEffect, useState } from "react"
import { Link, useParams } from "react-router-dom"
import { Ban, CircleCheck, FileText, GraduationCap, HeartHandshake } from "lucide-react"
import { useAuth } from "@/features/auth/AuthContext"
import {
  canApproveAccommodation,
  canManageAccommodations,
  canResolveAlert,
  canViewStudentHistory,
  isPsychopedagogue,
} from "@/lib/permissions"
import { cn, formatShortDate } from "@/lib/utils"
import { Badge, type BadgeTone } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { EmptyState } from "@/components/ui/empty-state"
import { PageHeader } from "@/components/ui/page-header"
import { SectionCard } from "@/components/ui/section-card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import {
  accommodationCategoryLabels,
  alertSeverityLabels,
  alertTypeLabels,
  assessmentTypeLabels,
  type Accommodation,
  type Alert,
  type Comment,
  type CommentCategory,
  type StudentTracking,
} from "@/types"
import { AccommodationFormDialog } from "./AccommodationFormDialog"
import { AccommodationInstanceOverrideForm } from "./AccommodationInstanceOverrideForm"
import { BarrierAccommodationsPanel } from "./BarrierAccommodationsPanel"
import { CommentsPanel } from "./CommentsPanel"
import { TeamSummaryCard } from "./TeamSummaryCard"
import { ScheduledFollowUpsPanel } from "./ScheduledFollowUpsPanel"
import { StudentPerformanceChart } from "./StudentPerformanceChart"
import * as trackingApi from "./trackingApi"
import type { CommentInput, TeamSummaryInput } from "./trackingApi"

type TabKey = "summary" | "evolution" | "adjustments" | "observations" | "assessments" | "documents"

// Same six tabs for every role (prototype screen 04); what changes is the content.
const TABS: { key: TabKey; label: string }[] = [
  { key: "summary", label: "Resumen" },
  { key: "evolution", label: "Evolución" },
  { key: "adjustments", label: "Ajustes" },
  { key: "observations", label: "Observaciones" },
  { key: "assessments", label: "Evaluaciones" },
  { key: "documents", label: "Documentos" },
]

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

  const showEditTeamSummary = isPsychopedagogue(user)
  const [tab, setTab] = useState<TabKey>("summary")
  const [tracking, setTracking] = useState<StudentTracking | null>(null)
  const [comments, setComments] = useState<Comment[] | null>(null)
  const [categories, setCategories] = useState<CommentCategory[]>([])
  const [error, setError] = useState<string | null>(null)
  const [accommodationDialogOpen, setAccommodationDialogOpen] = useState(false)
  const [editingAccommodation, setEditingAccommodation] = useState<Accommodation | null>(null)

  const loadTracking = useCallback(() => {
    return trackingApi
      .fetchStudentTracking(studentId)
      .then((data) => setTracking(data))
      .catch(() => setError("No pudimos cargar el perfil del alumno."))
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
    trackingApi
      .fetchCommentCategories()
      .then(setCategories)
      .catch(() => setCategories([]))
  }, [loadTracking, loadComments])

  async function handleCreateComment(input: CommentInput) {
    await trackingApi.createStudentComment(studentId, input)
    await loadComments()
  }

  async function handleSaveTeamSummary(input: TeamSummaryInput) {
    const team_summary = await trackingApi.updateTeamSummary(studentId, input)
    setTracking((current) => (current ? { ...current, team_summary } : current))
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
        title={`Perfil del alumno — ${student.full_name}`}
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

      {/* Recurrence mark (psychopedagogy/direction only — absent for a teacher).
          A mark to look at, not an alert: no owner, no deadline. */}
      {(tracking.comment_trends ?? []).map((trend) => (
        <div
          key={trend.category_id}
          role="note"
          className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
        >
          Tendencia: {trend.count} comentarios de «{trend.category_name}» de {trend.authors}{" "}
          {trend.authors === 1 ? "persona" : "personas"} en {trend.days} días
        </div>
      ))}

      <div role="tablist" aria-label="Secciones del perfil" className="flex flex-wrap gap-1 border-b">
        {TABS.map((item) => (
          <button
            key={item.key}
            type="button"
            role="tab"
            id={`tab-${item.key}`}
            aria-selected={tab === item.key}
            aria-controls={`panel-${item.key}`}
            onClick={() => setTab(item.key)}
            className={cn(
              "-mb-px border-b-2 px-3 py-2 text-sm font-medium",
              tab === item.key
                ? "border-primary text-foreground"
                : "border-transparent text-muted-foreground hover:text-foreground",
            )}
          >
            {item.label}
          </button>
        ))}
      </div>

      <div role="tabpanel" id={`panel-${tab}`} aria-labelledby={`tab-${tab}`} className="grid gap-6">
        {tab === "summary" && (
          <>
            <TeamSummaryCard
              summary={tracking.team_summary}
              accommodations={tracking.support_chips.accommodations}
              barriers={tracking.support_chips.barriers}
              canEdit={showEditTeamSummary}
              onSave={handleSaveTeamSummary}
              onChipClick={() => setTab("adjustments")}
            />

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
                      {alert.can?.act ? (
                        // The three ways out live on the Alertas screen (ClickUp 86e3jpzdp).
                        <Button asChild variant="outline" size="sm">
                          <Link to="/alertas">Elegir una salida</Link>
                        </Button>
                      ) : (
                        (alert.can ? alert.can.resolve : showResolve) && (
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={() => handleResolveAlert(alert.id)}
                          >
                            Resolver
                          </Button>
                        )
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


            <ScheduledFollowUpsPanel studentId={studentId} />
          </>
        )}

        {tab === "evolution" && (
          <StudentPerformanceChart
            studentId={studentId}
            subjects={tracking.by_subject.map((subject) => ({
              id: subject.subject_id,
              name: subject.subject_name,
            }))}
          />
        )}

        {tab === "adjustments" && (
          <>
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

            {!tracking.accommodations && !tracking.barriers && (
              <SupportsReadOnly chips={tracking.support_chips} />
            )}
          </>
        )}

        {tab === "observations" && (
          <CommentsPanel
            comments={comments ?? []}
            onCreate={handleCreateComment}
            title="Observaciones"
            categories={categories}
          />
        )}

        {tab === "assessments" && (
          <>
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

          <SectionCard
            title="Desempeño por materia"
            action={<OverallAverageBadge value={tracking.overall_average} />}
          >
            {tracking.by_subject.length === 0 ? (
              <p className="text-muted-foreground">Sin evaluaciones por materia.</p>
            ) : (
              <ul className="grid gap-2">
                {tracking.by_subject.map((subject) => (
                  <li
                    key={subject.subject_id}
                    className="flex items-center justify-between gap-3 rounded-md border p-3 text-sm"
                  >
                    <span className="font-medium">{subject.subject_name}</span>
                    <span className="tabular-nums text-muted-foreground">
                      {subject.average} ·{" "}
                      {subject.assessment_count === 1
                        ? "1 evaluación"
                        : `${subject.assessment_count} evaluaciones`}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </SectionCard>


          </>
        )}

        {tab === "documents" && (
          <SectionCard title="Documentos">
            <EmptyState icon={FileText} message="Todavía no hay documentos para mostrar." />
            {showHistoryLink && (
              <p className="mt-3 text-sm">
                <Link
                  className="text-primary underline-offset-4 hover:underline"
                  to={`/alumnos/${studentId}/historial`}
                >
                  Ver registro de quién abrió esta ficha →
                </Link>
              </p>
            )}
          </SectionCard>
        )}
      </div>

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

/**
 * The "Promedio general" chip shown alongside the per-subject section. Renders
 * an em dash when the average is null so "no scores yet" is never confused with
 * a genuine average of 0.
 */
function OverallAverageBadge({ value }: { value: number | null }) {
  return (
    <Card className="border-info-background bg-info-background/40">
      <CardContent className="flex items-center gap-2 px-3 py-1.5">
        <GraduationCap className="size-4 text-info" aria-hidden="true" />
        <span className="text-sm text-muted-foreground">Promedio general</span>
        <span className="text-base font-semibold tabular-nums text-info">
          {value ?? "—"}
        </span>
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

/**
 * What a viewer without clinical-profile access sees in "Ajustes": the names of
 * the supports to apply, nothing else (the server sends no detail).
 */
function SupportsReadOnly({ chips }: { chips: StudentTracking["support_chips"] }) {
  return (
    <>
      <SectionCard title="Ajustes vigentes">
        {chips.accommodations.length === 0 ? (
          <EmptyState icon={HeartHandshake} message="Sin ajustes vigentes." />
        ) : (
          <ul className="grid gap-2">
            {chips.accommodations.map((chip) => (
              <li key={chip.id} className="rounded-md border p-3 text-sm">
                {chip.label}
              </li>
            ))}
          </ul>
        )}
      </SectionCard>
      <SectionCard title="Barreras activas">
        {chips.barriers.length === 0 ? (
          <EmptyState icon={Ban} message="Sin barreras activas." />
        ) : (
          <ul className="grid gap-2">
            {chips.barriers.map((chip) => (
              <li key={chip.id} className="rounded-md border p-3 text-sm">
                {chip.label}
              </li>
            ))}
          </ul>
        )}
      </SectionCard>
    </>
  )
}
