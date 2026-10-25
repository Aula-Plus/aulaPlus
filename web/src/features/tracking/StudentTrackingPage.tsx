import { useCallback, useEffect, useState } from "react"
import { Link, useParams } from "react-router-dom"
import { Ban, CircleCheck, FileText, GraduationCap, HeartHandshake } from "lucide-react"
import { useAuth } from "@/features/auth/AuthContext"
import {
  canEditConfirmedAccommodations,
  canManageAccommodations,
  canManageReports,
  canShareComment,
  canResolveAlert,
  canViewStudentHistory,
  createsAccommodationProposals,
  isPsychopedagogue,
  isSchoolWideStaff,
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
  type CommentShare,
  type StaffMember,
  type GradeAccommodation,
  type StudentTracking,
} from "@/types"
import { AccommodationFormDialog } from "./AccommodationFormDialog"
import { accommodationAppliesToAssessment, describeAccommodationScope } from "./accommodationScope"
import { AccommodationInstanceOverrideForm } from "./AccommodationInstanceOverrideForm"
import { AccommodationProposalsSection } from "./AccommodationProposalsSection"
import { BarrierAccommodationsPanel } from "./BarrierAccommodationsPanel"
import { CommentsPanel } from "./CommentsPanel"
import { PrivateNotesSignal } from "./PrivateNotesSignal"
import { ShareCommentDialog } from "./ShareCommentDialog"
import { SharedExtractsList } from "./SharedExtractsList"
import { StudentReportsSection } from "@/features/reports/StudentReportsSection"
import * as reportsApi from "@/features/reports/reportsApi"
import { TeamSummaryCard } from "./TeamSummaryCard"
import { ScheduledFollowUpsPanel } from "./ScheduledFollowUpsPanel"
import { StudentPerformanceChart } from "./StudentPerformanceChart"
import * as scheduledFollowUpsApi from "./scheduledFollowUpsApi"
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
  const showManageAccommodations = canManageAccommodations(user)
  // Direction proposes (psychopedagogy confirms) and never edits an
  // accommodation in force (ClickUp 86e3qkwkk).
  const proposesAccommodations = createsAccommodationProposals(user)
  const showEditAccommodations = canEditConfirmedAccommodations(user)
  const newAccommodationLabel = proposesAccommodations ? "Proponer ajuste" : "Nuevo ajuste"
  const showHistoryLink = canViewStudentHistory(user)

  const showEditTeamSummary = isPsychopedagogue(user)
  const [tab, setTab] = useState<TabKey>("summary")
  const [tracking, setTracking] = useState<StudentTracking | null>(null)
  const [comments, setComments] = useState<Comment[] | null>(null)
  const [categories, setCategories] = useState<CommentCategory[]>([])
  const [commentShares, setCommentShares] = useState<CommentShare[]>([])
  // People who see the student, for «Elegir personas» in «Visible para».
  const [scopeCandidates, setScopeCandidates] = useState<StaffMember[] | undefined>(undefined)
  const [sharingComment, setSharingComment] = useState<Comment | null>(null)
  const showShareComment = canShareComment(user)
  const [error, setError] = useState<string | null>(null)
  const [accommodationDialogOpen, setAccommodationDialogOpen] = useState(false)
  const [editingAccommodation, setEditingAccommodation] = useState<Accommodation | null>(null)
  const [proposalsRefreshKey, setProposalsRefreshKey] = useState(0)

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

  const loadCommentShares = useCallback(() => {
    return trackingApi
      .fetchStudentCommentShares(studentId)
      .then((data) => setCommentShares(data))
      .catch(() => setCommentShares([]))
  }, [studentId])

  useEffect(() => {
    loadTracking()
    loadComments()
    loadCommentShares()
    trackingApi
      .fetchCommentCategories()
      .then(setCategories)
      .catch(() => setCategories([]))
    scheduledFollowUpsApi
      .fetchFollowUpCandidates(studentId)
      .then(setScopeCandidates)
      .catch(() => setScopeCandidates(undefined))
  }, [loadTracking, loadComments, loadCommentShares, studentId])

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

  function handleNewAccommodation() {
    setEditingAccommodation(null)
    setAccommodationDialogOpen(true)
  }

  function handleEditAccommodation(accommodation: Accommodation) {
    setEditingAccommodation(accommodation)
    setAccommodationDialogOpen(true)
  }

  /**
   * After creating/editing an accommodation, a full refetch is enough (§4).
   * Proposals live in their own (uncached) list, refetched too.
   */
  function handleAccommodationSaved() {
    loadTracking()
    setProposalsRefreshKey((key) => key + 1)
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
            Ver historial de cambios →
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

      {/* Only ever true for the author of the private notes (server-computed). */}
      <PrivateNotesSignal active={tracking.private_notes_signal} />

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
                label="Grupos"
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
              title="Ajustes vigentes"
              action={
                // When the list is empty the CTA lives in the empty state instead,
                // so there is exactly one "Nuevo ajuste" affordance per state.
                showManageAccommodations && tracking.accommodations.length > 0 ? (
                  <Button type="button" size="sm" onClick={handleNewAccommodation}>
                    {newAccommodationLabel}
                  </Button>
                ) : undefined
              }
            >
              {tracking.accommodations.length === 0 ? (
                <EmptyState
                  icon={HeartHandshake}
                  message="Sin ajustes vigentes."
                  action={
                    showManageAccommodations && (
                      <Button type="button" size="sm" variant="outline" onClick={handleNewAccommodation}>
                        {newAccommodationLabel}
                      </Button>
                    )
                  }
                />
              ) : (
                <ul className="grid gap-2">
                  {tracking.accommodations.map((accommodation) => {
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
                            <p className="text-muted-foreground">
                              {capitalize(describeAccommodationScope(accommodation))}
                            </p>
                            {/* This full record only reaches psychopedagogy and direction. */}
                            {(accommodation.origin ||
                              (accommodation.review_status === "confirmed" &&
                                accommodation.reviewed_by_name)) && (
                              <p className="text-xs text-muted-foreground">
                                {[
                                  accommodation.origin && `Origen: ${accommodation.origin}`,
                                  accommodation.review_status === "confirmed" &&
                                    accommodation.reviewed_by_name &&
                                    `Confirmó ${accommodation.reviewed_by_name}`,
                                ]
                                  .filter(Boolean)
                                  .join(" · ")}
                                {accommodation.origin_report_id !== null && (
                                  <Button
                                    type="button"
                                    size="sm"
                                    variant="link"
                                    className="h-auto px-1 py-0 text-xs"
                                    onClick={() => {
                                      setError(null)
                                      reportsApi.openReportFile(accommodation.origin_report_id as number, setError)
                                    }}
                                  >
                                    Abrir archivo
                                  </Button>
                                )}
                              </p>
                            )}
                          </div>
                          <div className="flex items-center gap-2">
                            <AccommodationStatusBadge accommodation={accommodation} />
                            {showEditAccommodations && (
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
                        {accommodation.is_effective && (
                          <AccommodationInstanceOverrideForm
                            accommodationId={accommodation.id}
                            // Only the assessments it applies to (subject +
                            // type): the server 422s any other one.
                            assessments={tracking.recent_assessments.filter((assessment) =>
                              accommodationAppliesToAssessment(accommodation, assessment),
                            )}
                          />
                        )}
                      </li>
                    )
                  })}
                </ul>
              )}
            </SectionCard>
          )}


          {showManageAccommodations && (
            <AccommodationProposalsSection
              studentId={studentId}
              refreshKey={proposalsRefreshKey}
              onEdit={handleEditAccommodation}
              onConfirmed={loadTracking}
            />
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
            beforeList={<SharedExtractsList shares={commentShares} />}
            renderCommentActions={
              showShareComment
                ? (comment) => (
                    <div className="mt-2">
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => setSharingComment(comment)}
                      >
                        Compartir con
                      </Button>
                    </div>
                  )
                : undefined
            }
            showAuthors
            currentUserId={user?.id}
            showCount={isSchoolWideStaff(user)}
            scopeCandidates={scopeCandidates}
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

          {tracking.grades_view.restricted && (
            <p role="note" className="rounded-md border bg-muted/40 p-3 text-sm text-muted-foreground">
              {tracking.grades_view.mode === "all_periodic" && tracking.grades_view.cutoff_at
                ? `Notas de otras materias cargadas antes del corte del ${formatShortDate(tracking.grades_view.cutoff_at)}. Las de tu materia están al día.`
                : tracking.grades_view.mode === "all_periodic"
                  ? "Todavía no hay un corte: por ahora ves solo las notas de tu materia."
                  : "Ves solo las notas de las materias que dictás."}
            </p>
          )}

          <SectionCard
            title="Desempeño por materia"
            action={
              // A teacher limited to their own subjects gets no general
              // average (the server sends null): only their subjects' ones.
              tracking.grades_view.restricted && tracking.grades_view.mode === "own_subject" ? undefined : (
                <OverallAverageBadge value={tracking.overall_average} />
              )
            }
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

          <SectionCard title="Notas" bare={tracking.grades.length > 0}>
            {tracking.grades.length === 0 ? (
              <EmptyState icon={FileText} message="Sin notas para mostrar." />
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="pl-6">Materia</TableHead>
                    <TableHead>Instancia</TableHead>
                    <TableHead>Fecha</TableHead>
                    <TableHead>Nota</TableHead>
                    <TableHead className="pr-6">Ajustes activos ese día</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {tracking.grades.map((grade) => (
                    <TableRow key={grade.id}>
                      <TableCell className="pl-6">{grade.subject_name}</TableCell>
                      <TableCell>{assessmentTypeLabels[grade.type]}</TableCell>
                      <TableCell>{formatShortDate(grade.administered_at)}</TableCell>
                      <TableCell className="tabular-nums">{grade.score ?? "—"}</TableCell>
                      <TableCell className="pr-6">
                        <GradeAccommodations accommodations={grade.accommodations} />
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
            {tracking.grades_truncated && (
              <p className="px-6 py-3 text-xs text-muted-foreground">
                Se muestran las {tracking.grades.length} notas más recientes. Los promedios incluyen todas.
              </p>
            )}
          </SectionCard>


          </>
        )}

        {tab === "documents" && (
          <>
            <StudentReportsSection
              studentId={studentId}
              canManage={canManageReports(user)}
              canSeeContent={canManageReports(user)}
              onAccommodationCreated={loadTracking}
            />
            {showHistoryLink && (
              <p className="text-sm">
                <Link
                  className="text-primary underline-offset-4 hover:underline"
                  to={`/alumnos/${studentId}/historial`}
                >
                  Ver historial de cambios →
                </Link>
              </p>
            )}
          </>
        )}
      </div>

      {showShareComment && (
        <ShareCommentDialog
          studentId={studentId}
          comment={sharingComment}
          onOpenChange={(open) => {
            if (!open) setSharingComment(null)
          }}
          onShared={(share) => setCommentShares((current) => [share, ...current])}
        />
      )}

      {showManageAccommodations && (
        <AccommodationFormDialog
          open={accommodationDialogOpen}
          onOpenChange={setAccommodationDialogOpen}
          studentId={studentId}
          accommodation={editingAccommodation}
          onSaved={handleAccommodationSaved}
          proposal={proposesAccommodations}
        />
      )}
    </div>
  )
}

/**
 * The accommodations that were in force for one grade (name only), as neutral
 * chips; «Ninguno» when there were none. Server-filtered: the list only holds
 * what this viewer may see.
 */
function GradeAccommodations({ accommodations }: { accommodations: GradeAccommodation[] }) {
  if (accommodations.length === 0) {
    return <span className="text-muted-foreground">Ninguno</span>
  }
  return (
    <ul className="flex flex-wrap gap-1" aria-label="Ajustes activos ese día">
      {accommodations.map((accommodation) => (
        <li key={accommodation.id}>
          <Badge tone="neutral">{accommodation.label}</Badge>
        </li>
      ))}
    </ul>
  )
}

/**
 * Status pill for an accommodation in the «Ajustes vigentes» list: the vigency
 * flag `is_effective`. Direction proposals not confirmed yet never land here —
 * they have their own section (AccommodationProposalsSection).
 */
function AccommodationStatusBadge({ accommodation }: { accommodation: Accommodation }) {
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

function capitalize(text: string): string {
  return text.charAt(0).toUpperCase() + text.slice(1)
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
 * What a viewer without clinical-profile access sees in "Ajustes": each support
 * to apply with since when and where it applies — «Ortografía no puntúa — desde
 * el 02/04 — en Idioma Español y Literatura». The server only sends a teacher
 * the general accommodations and those scoped to a subject they teach the
 * student, and never their origin, validator or review date.
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
                <span className="font-medium">{chip.label}</span>
                <span className="text-muted-foreground"> — {describeAccommodationScope(chip)}</span>
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
