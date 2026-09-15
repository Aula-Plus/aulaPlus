import { useCallback, useEffect, useState } from "react"
import { Controller, useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { useParams } from "react-router-dom"
import { ClipboardList } from "lucide-react"
import { useAuth } from "@/features/auth/AuthContext"
import { canManageAssessments } from "@/lib/permissions"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { EmptyState } from "@/components/ui/empty-state"
import { PageHeader } from "@/components/ui/page-header"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { SingleSelect } from "@/components/ui/single-select"
import { Textarea } from "@/components/ui/textarea"
import { ConfirmDialog } from "@/components/ui/confirm-dialog"
import { assessmentTypeLabels } from "@/types"
import type { Assessment, AssessmentType, Group, GroupTrackingStudent } from "@/types"
import * as trackingApi from "@/features/tracking/trackingApi"
import * as subjectsApi from "@/features/subjects/subjectsApi"
import * as assessmentsApi from "./assessmentsApi"
import { AssessmentResultsEditor } from "./AssessmentResultsEditor"

const ASSESSMENT_TYPES = Object.keys(assessmentTypeLabels) as [AssessmentType, ...AssessmentType[]]

const assessmentSchema = z.object({
  type: z.enum(ASSESSMENT_TYPES),
  // The backend (Sesión 3) requires an assessment to name its subject and only
  // accepts subjects the teacher is assigned in the group; the client mirrors
  // that as a required field for UX (never as the security boundary). The
  // picker's onChange always writes a number, so `z.number()` (not `coerce`)
  // keeps the resolver's input and output types aligned.
  subject_id: z
    .number({ message: "Elegí una materia" })
    .int()
    .positive({ message: "Elegí una materia" }),
  administered_at: z.string().min(1, "Ingresá la fecha"),
  purpose: z.string().optional(),
})

type AssessmentValues = z.infer<typeof assessmentSchema>

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

/**
 * Assessments screen for a group (docs/prompts/14-frontend-evaluaciones-
 * resultados.md §4), route `/clases/:id/evaluaciones`. A plain form (NOT the
 * Bloque 2 AI assistant): create an assessment, then load a numeric score +
 * optional feedback per student. The roster and group name come from the group
 * tracking aggregate — the only teacher-visible source of a group's students in
 * the SPA today.
 */
export function AssessmentsPage() {
  const { id } = useParams<{ id: string }>()
  const groupId = Number(id)
  const { user } = useAuth()

  const [group, setGroup] = useState<Group | null>(null)
  const [students, setStudents] = useState<GroupTrackingStudent[]>([])
  const [assessments, setAssessments] = useState<Assessment[] | null>(null)
  const [subjects, setSubjects] = useState<{ id: number; name: string }[]>([])
  const [error, setError] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    control,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<AssessmentValues>({
    resolver: zodResolver(assessmentSchema),
    defaultValues: { type: "written", administered_at: today(), purpose: "" },
  })

  const loadAssessments = useCallback(() => {
    return assessmentsApi
      .fetchAssessments(groupId)
      .then(setAssessments)
      .catch(() => setError("No pudimos cargar las evaluaciones."))
  }, [groupId])

  useEffect(() => {
    trackingApi
      .fetchGroupTracking(groupId)
      .then((tracking) => {
        setGroup(tracking.group)
        setStudents(tracking.students)
      })
      .catch(() => setError("No pudimos cargar la clase."))
    loadAssessments()
    // Offer only the subjects THIS teacher is assigned in the group (backend
    // Sesión 3 validates the same rule). Only the leading teacher sees the
    // create form (canManageAssessments), so filtering by the authed user id is
    // exactly the assignable set. A failure just leaves the picker empty — the
    // backend stays the authority — so it never blocks the rest of the page.
    subjectsApi
      .fetchGroupAssignments(groupId)
      .then((rows) => {
        const distinct = new Map<number, string>()
        for (const row of rows) {
          if (row.teacher_id === user?.id && !distinct.has(row.subject_id)) {
            distinct.set(row.subject_id, row.subject_name ?? `Materia ${row.subject_id}`)
          }
        }
        setSubjects(Array.from(distinct, ([id, name]) => ({ id, name })))
      })
      .catch(() => undefined)
  }, [groupId, loadAssessments, user?.id])

  const canManage = canManageAssessments(user, group)

  async function onCreate(values: AssessmentValues) {
    setFormError(null)
    try {
      await assessmentsApi.createAssessment(groupId, {
        type: values.type,
        subject_id: values.subject_id,
        administered_at: values.administered_at,
        purpose: values.purpose?.trim() ? values.purpose.trim() : null,
      })
      reset({ type: "written", administered_at: today(), purpose: "" })
      await loadAssessments()
    } catch {
      setFormError("No pudimos crear la evaluación.")
    }
  }

  async function onDelete(assessmentId: number) {
    setError(null)
    try {
      await assessmentsApi.deleteAssessment(assessmentId)
      await loadAssessments()
    } catch {
      setError("No pudimos eliminar la evaluación.")
    }
  }

  if (error) {
    return <p className="text-sm text-destructive">{error}</p>
  }

  if (!assessments || !group) {
    return <p className="text-muted-foreground">Cargando…</p>
  }

  return (
    <div className="grid gap-6">
      <PageHeader
        backTo={`/clases/${groupId}/seguimiento`}
        backLabel="Volver al seguimiento"
        title={`Evaluaciones — ${group.name}`}
      />

      {canManage && (
        <Card>
          <CardHeader>
            <CardTitle>Nueva evaluación</CardTitle>
          </CardHeader>
          <CardContent>
            <form onSubmit={handleSubmit(onCreate)} className="grid gap-4 sm:grid-cols-2" noValidate>
              <div className="grid gap-2">
                <Label htmlFor="type">Tipo</Label>
                <Controller
                  name="type"
                  control={control}
                  render={({ field }) => (
                    <SingleSelect
                      id="type"
                      options={ASSESSMENT_TYPES.map((type) => ({
                        value: type,
                        label: assessmentTypeLabels[type],
                      }))}
                      value={field.value}
                      onChange={field.onChange}
                    />
                  )}
                />
              </div>
              <div className="grid gap-2">
                <Label htmlFor="subject_id">Materia</Label>
                <Controller
                  name="subject_id"
                  control={control}
                  render={({ field }) => (
                    <SingleSelect
                      id="subject_id"
                      placeholder="Seleccioná una materia"
                      options={subjects.map((subject) => ({
                        value: String(subject.id),
                        label: subject.name,
                      }))}
                      value={field.value ? String(field.value) : ""}
                      onChange={(value) => field.onChange(Number(value))}
                    />
                  )}
                />
                {errors.subject_id && (
                  <p className="text-sm text-destructive">{errors.subject_id.message}</p>
                )}
              </div>
              <div className="grid gap-2">
                <Label htmlFor="administered_at">Fecha</Label>
                <Input id="administered_at" type="date" {...register("administered_at")} />
                {errors.administered_at && (
                  <p className="text-sm text-destructive">{errors.administered_at.message}</p>
                )}
              </div>
              <div className="grid gap-2 sm:col-span-2">
                <Label htmlFor="purpose">Propósito (opcional)</Label>
                <Textarea id="purpose" {...register("purpose")} />
              </div>
              {formError && (
                <p role="alert" className="text-sm text-destructive sm:col-span-2">
                  {formError}
                </p>
              )}
              <div className="sm:col-span-2">
                <Button type="submit" disabled={isSubmitting}>
                  {isSubmitting ? "Creando…" : "Crear evaluación"}
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>
      )}

      {assessments.length === 0 ? (
        <EmptyState icon={ClipboardList} message="Todavía no hay evaluaciones en esta clase." />
      ) : (
        <section className="grid gap-4">
          <h2 className="text-lg font-semibold">Evaluaciones cargadas</h2>
          {assessments.map((assessment) => (
            <Card key={assessment.id}>
              <CardHeader>
                <div className="flex items-start justify-between gap-4">
                  <div>
                    <CardTitle>
                      {assessmentTypeLabels[assessment.type]}
                      {assessment.subject_name && (
                        <span className="text-muted-foreground font-normal">
                          {" · "}
                          {assessment.subject_name}
                        </span>
                      )}
                    </CardTitle>
                    <p className="text-sm text-muted-foreground">
                      {assessment.administered_at ?? "Sin fecha"}
                    </p>
                    {assessment.purpose && (
                      <p className="mt-1 text-sm text-muted-foreground">{assessment.purpose}</p>
                    )}
                  </div>
                  {canManage && (
                    <ConfirmDialog
                      trigger={
                        <Button type="button" variant="destructive" size="sm">
                          Eliminar
                        </Button>
                      }
                      title="Eliminar evaluación"
                      description="Se eliminarán también las notas cargadas. Esta acción no se puede deshacer."
                      confirmLabel="Eliminar"
                      onConfirm={() => onDelete(assessment.id)}
                    />
                  )}
                </div>
              </CardHeader>
              <CardContent>
                <AssessmentResultsEditor
                  assessment={assessment}
                  students={students}
                  canManage={canManage}
                />
              </CardContent>
            </Card>
          ))}
        </section>
      )}
    </div>
  )
}
