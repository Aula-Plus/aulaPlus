import { useCallback, useEffect, useState } from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { Link, useParams } from "react-router-dom"
import { useAuth } from "@/features/auth/AuthContext"
import { canManageAssessments } from "@/lib/permissions"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select } from "@/components/ui/select"
import { Textarea } from "@/components/ui/textarea"
import { ConfirmDialog } from "@/components/ui/confirm-dialog"
import { assessmentTypeLabels } from "@/types"
import type { Assessment, AssessmentType, Group, GroupTrackingStudent } from "@/types"
import * as trackingApi from "@/features/tracking/trackingApi"
import * as assessmentsApi from "./assessmentsApi"
import { AssessmentResultsEditor } from "./AssessmentResultsEditor"

const ASSESSMENT_TYPES = Object.keys(assessmentTypeLabels) as [AssessmentType, ...AssessmentType[]]

const assessmentSchema = z.object({
  type: z.enum(ASSESSMENT_TYPES),
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
  const [error, setError] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
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
  }, [groupId, loadAssessments])

  const canManage = canManageAssessments(user, group)

  async function onCreate(values: AssessmentValues) {
    setFormError(null)
    try {
      await assessmentsApi.createAssessment(groupId, {
        type: values.type,
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
      <div>
        <Link
          className="text-sm text-primary underline-offset-4 hover:underline"
          to={`/clases/${groupId}/seguimiento`}
        >
          ← Volver al seguimiento
        </Link>
        <h1 className="mt-1 text-2xl font-semibold">Evaluaciones — {group.name}</h1>
      </div>

      {canManage && (
        <Card>
          <CardHeader>
            <CardTitle>Nueva evaluación</CardTitle>
          </CardHeader>
          <CardContent>
            <form onSubmit={handleSubmit(onCreate)} className="grid gap-4 sm:grid-cols-2" noValidate>
              <div className="grid gap-2">
                <Label htmlFor="type">Tipo</Label>
                <Select id="type" {...register("type")}>
                  {ASSESSMENT_TYPES.map((type) => (
                    <option key={type} value={type}>
                      {assessmentTypeLabels[type]}
                    </option>
                  ))}
                </Select>
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
        <p className="text-muted-foreground">Todavía no hay evaluaciones en esta clase.</p>
      ) : (
        <section className="grid gap-4">
          <h2 className="text-lg font-semibold">Evaluaciones cargadas</h2>
          {assessments.map((assessment) => (
            <Card key={assessment.id}>
              <CardHeader>
                <div className="flex items-start justify-between gap-4">
                  <div>
                    <CardTitle>{assessmentTypeLabels[assessment.type]}</CardTitle>
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
