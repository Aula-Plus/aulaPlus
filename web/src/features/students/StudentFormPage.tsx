import { useEffect, useState } from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { useNavigate, useParams } from "react-router-dom"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select } from "@/components/ui/select"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { ConfirmDialog } from "@/components/ui/confirm-dialog"
import { Textarea } from "@/components/ui/textarea"
import { getCurrentSchoolYear } from "@/lib/schoolYear"
import { useAuth } from "@/features/auth/AuthContext"
import type { Group } from "@/types"
import * as studentsApi from "./studentsApi"
import * as groupsApi from "@/features/groups/groupsApi"

function isValidJsonObjectOrEmpty(value: string | undefined): boolean {
  if (!value) return true
  try {
    const parsed = JSON.parse(value)
    return typeof parsed === "object" && parsed !== null
  } catch {
    return false
  }
}

const studentSchema = z.object({
  full_name: z.string().min(1, "Ingresá un nombre"),
  photo_url: z.string().optional(),
  birth_date: z.string().optional(),
  enrollment_year: z.string().min(1, "Ingresá el año de ingreso"),
  has_therapeutic_companion: z.boolean(),
  group_id: z.string().optional(),
  learning_profile: z.string().optional().refine(isValidJsonObjectOrEmpty, "Ingresá un JSON válido"),
  tracking_notes: z.string().optional(),
  individual_profile: z
    .string()
    .optional()
    .refine(isValidJsonObjectOrEmpty, "Ingresá un JSON válido"),
  related_documents: z
    .string()
    .optional()
    .refine(isValidJsonObjectOrEmpty, "Ingresá un JSON válido"),
})

type StudentValues = z.infer<typeof studentSchema>

export function StudentFormPage() {
  const { id } = useParams()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const [formError, setFormError] = useState<string | null>(null)
  const [isDeleting, setIsDeleting] = useState(false)
  const [groups, setGroups] = useState<Group[]>([])
  const currentYear = getCurrentSchoolYear()
  const { user } = useAuth()
  const canEditClinicalProfile =
    user?.roles.some((role) => role === "director" || role === "psychopedagogue") ?? false
  const canDelete = user?.roles.includes("director") ?? false

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<StudentValues>({
    resolver: zodResolver(studentSchema),
    defaultValues: {
      full_name: "",
      photo_url: "",
      birth_date: "",
      enrollment_year: "",
      has_therapeutic_companion: false,
      group_id: "",
      learning_profile: "",
      tracking_notes: "",
      individual_profile: "",
      related_documents: "",
    },
  })

  useEffect(() => {
    groupsApi.fetchGroups().then((allGroups) => {
      setGroups(allGroups.filter((group) => group.school_year === currentYear))
    })
  }, [currentYear])

  useEffect(() => {
    if (!id) return
    studentsApi.fetchStudent(Number(id)).then((student) => {
      const currentGroup = student.groups.find((group) => group.school_year === currentYear)
      reset({
        full_name: student.full_name,
        photo_url: student.photo_url ?? "",
        birth_date: student.birth_date ?? "",
        enrollment_year: String(student.enrollment_year),
        has_therapeutic_companion: student.has_therapeutic_companion,
        group_id: currentGroup ? String(currentGroup.id) : "",
        learning_profile:
          student.learning_profile != null
            ? JSON.stringify(student.learning_profile, null, 2)
            : "",
        tracking_notes: student.tracking_notes ?? "",
        individual_profile:
          student.individual_profile != null
            ? JSON.stringify(student.individual_profile, null, 2)
            : "",
        related_documents:
          student.related_documents != null
            ? JSON.stringify(student.related_documents, null, 2)
            : "",
      })
    })
  }, [id, reset, currentYear])

  async function onSubmit(values: StudentValues) {
    setFormError(null)
    try {
      const clinicalFields = canEditClinicalProfile
        ? {
            learning_profile: values.learning_profile
              ? JSON.parse(values.learning_profile)
              : null,
            tracking_notes: values.tracking_notes || undefined,
            individual_profile: values.individual_profile
              ? JSON.parse(values.individual_profile)
              : null,
            related_documents: values.related_documents
              ? JSON.parse(values.related_documents)
              : null,
          }
        : {}

      const input = {
        full_name: values.full_name,
        photo_url: values.photo_url || undefined,
        birth_date: values.birth_date || undefined,
        enrollment_year: Number(values.enrollment_year),
        has_therapeutic_companion: values.has_therapeutic_companion,
        group_id: values.group_id ? Number(values.group_id) : null,
        school_year: values.group_id ? currentYear : undefined,
        ...clinicalFields,
      }
      if (isEdit) {
        await studentsApi.updateStudent(Number(id), input)
      } else {
        await studentsApi.createStudent(input)
      }
      navigate("/alumnos", { replace: true })
    } catch {
      setFormError("No pudimos guardar el alumno.")
    }
  }

  async function onDelete() {
    if (!id) return
    setFormError(null)
    setIsDeleting(true)
    try {
      await studentsApi.deleteStudent(Number(id))
      navigate("/alumnos", { replace: true })
    } catch {
      setFormError("No pudimos eliminar el alumno.")
      setIsDeleting(false)
    }
  }

  return (
    <Card className="max-w-lg">
      <CardHeader>
        <CardTitle>{isEdit ? "Editar alumno" : "Nuevo alumno"}</CardTitle>
      </CardHeader>
      <CardContent>
        <form onSubmit={handleSubmit(onSubmit)} className="grid gap-4" noValidate>
          <div className="grid gap-2">
            <Label htmlFor="full_name">Nombre completo</Label>
            <Input id="full_name" {...register("full_name")} />
            {errors.full_name && (
              <p className="text-sm text-destructive">{errors.full_name.message}</p>
            )}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="photo_url">URL de foto</Label>
            <Input id="photo_url" {...register("photo_url")} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="birth_date">Fecha de nacimiento</Label>
            <Input id="birth_date" type="date" {...register("birth_date")} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="enrollment_year">Año de ingreso</Label>
            <Input id="enrollment_year" type="number" {...register("enrollment_year")} />
            {errors.enrollment_year && (
              <p className="text-sm text-destructive">{errors.enrollment_year.message}</p>
            )}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="group_id">Clase ({currentYear})</Label>
            <Select id="group_id" {...register("group_id")}>
              <option value="">Sin clase asignada</option>
              {groups.map((group) => (
                <option key={group.id} value={group.id}>
                  {group.name}
                </option>
              ))}
            </Select>
          </div>
          <div className="flex items-center gap-2">
            <input
              id="has_therapeutic_companion"
              type="checkbox"
              className="size-4"
              {...register("has_therapeutic_companion")}
            />
            <Label htmlFor="has_therapeutic_companion">Tiene acompañante terapéutico</Label>
          </div>
          {canEditClinicalProfile && (
            <>
              <div className="grid gap-2">
                <Label htmlFor="tracking_notes">Notas de seguimiento</Label>
                <Textarea id="tracking_notes" {...register("tracking_notes")} />
              </div>
              <div className="grid gap-2">
                <Label htmlFor="learning_profile">Perfil de aprendizaje (JSON)</Label>
                <Textarea id="learning_profile" {...register("learning_profile")} />
                {errors.learning_profile && (
                  <p className="text-sm text-destructive">{errors.learning_profile.message}</p>
                )}
              </div>
              <div className="grid gap-2">
                <Label htmlFor="individual_profile">Perfil individual (JSON)</Label>
                <Textarea id="individual_profile" {...register("individual_profile")} />
                {errors.individual_profile && (
                  <p className="text-sm text-destructive">{errors.individual_profile.message}</p>
                )}
              </div>
              <div className="grid gap-2">
                <Label htmlFor="related_documents">Documentos relacionados (JSON)</Label>
                <Textarea id="related_documents" {...register("related_documents")} />
                {errors.related_documents && (
                  <p className="text-sm text-destructive">{errors.related_documents.message}</p>
                )}
              </div>
            </>
          )}
          {formError && (
            <p role="alert" className="text-sm text-destructive">
              {formError}
            </p>
          )}
          <div className="flex gap-2">
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? "Guardando…" : "Guardar"}
            </Button>
            {isEdit && canDelete && (
              <ConfirmDialog
                trigger={
                  <Button type="button" variant="destructive" disabled={isDeleting}>
                    {isDeleting ? "Eliminando…" : "Eliminar alumno"}
                  </Button>
                }
                title="Eliminar alumno"
                description="Esta acción no se puede deshacer."
                confirmLabel="Eliminar"
                isConfirming={isDeleting}
                onConfirm={onDelete}
              />
            )}
          </div>
        </form>
      </CardContent>
    </Card>
  )
}
