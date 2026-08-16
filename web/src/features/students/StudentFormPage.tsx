import { useEffect, useState } from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { useNavigate, useParams } from "react-router-dom"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select } from "@/components/ui/select"
import { Textarea } from "@/components/ui/textarea"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { studentStatusLabels } from "@/types"
import type { Group } from "@/types"
import * as studentsApi from "./studentsApi"
import * as groupsApi from "@/features/groups/groupsApi"

const studentSchema = z.object({
  first_name: z.string().min(1, "Ingresá un nombre"),
  last_name: z.string().min(1, "Ingresá un apellido"),
  birth_date: z.string().optional(),
  group_id: z.string().optional(),
  status: z.enum(["active", "inactive"]),
  family_contact_name: z.string().optional(),
  family_contact_phone: z.string().optional(),
  family_contact_email: z.string().email("Email inválido").optional().or(z.literal("")),
  pedagogical_notes: z.string().optional(),
})

type StudentValues = z.infer<typeof studentSchema>

export function StudentFormPage() {
  const { id } = useParams()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const [formError, setFormError] = useState<string | null>(null)
  const [groups, setGroups] = useState<Group[]>([])

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<StudentValues>({
    resolver: zodResolver(studentSchema),
    defaultValues: {
      first_name: "",
      last_name: "",
      birth_date: "",
      group_id: "",
      status: "active",
      family_contact_name: "",
      family_contact_phone: "",
      family_contact_email: "",
      pedagogical_notes: "",
    },
  })

  useEffect(() => {
    groupsApi.fetchGroups().then(setGroups)
  }, [])

  useEffect(() => {
    if (!id) return
    studentsApi.fetchStudent(Number(id)).then((student) => {
      reset({
        first_name: student.first_name,
        last_name: student.last_name,
        birth_date: student.birth_date ?? "",
        group_id: student.group_id ? String(student.group_id) : "",
        status: student.status,
        family_contact_name: student.family_contact_name ?? "",
        family_contact_phone: student.family_contact_phone ?? "",
        family_contact_email: student.family_contact_email ?? "",
        pedagogical_notes: student.pedagogical_notes ?? "",
      })
    })
  }, [id, reset])

  async function onSubmit(values: StudentValues) {
    setFormError(null)
    try {
      const input = {
        ...values,
        group_id: values.group_id ? Number(values.group_id) : null,
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

  return (
    <Card className="max-w-lg">
      <CardHeader>
        <CardTitle>{isEdit ? "Editar alumno" : "Nuevo alumno"}</CardTitle>
      </CardHeader>
      <CardContent>
        <form onSubmit={handleSubmit(onSubmit)} className="grid gap-4" noValidate>
          <div className="grid gap-2">
            <Label htmlFor="first_name">Nombre</Label>
            <Input id="first_name" {...register("first_name")} />
            {errors.first_name && (
              <p className="text-sm text-destructive">{errors.first_name.message}</p>
            )}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="last_name">Apellido</Label>
            <Input id="last_name" {...register("last_name")} />
            {errors.last_name && (
              <p className="text-sm text-destructive">{errors.last_name.message}</p>
            )}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="birth_date">Fecha de nacimiento</Label>
            <Input id="birth_date" type="date" {...register("birth_date")} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="group_id">Clase</Label>
            <Select id="group_id" {...register("group_id")}>
              <option value="">Sin clase asignada</option>
              {groups.map((group) => (
                <option key={group.id} value={group.id}>
                  {group.name}
                </option>
              ))}
            </Select>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="status">Estado</Label>
            <Select id="status" {...register("status")}>
              {Object.entries(studentStatusLabels).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </Select>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="family_contact_name">Contacto de familia</Label>
            <Input id="family_contact_name" {...register("family_contact_name")} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="family_contact_phone">Teléfono de contacto</Label>
            <Input id="family_contact_phone" {...register("family_contact_phone")} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="family_contact_email">Email de contacto</Label>
            <Input id="family_contact_email" type="email" {...register("family_contact_email")} />
            {errors.family_contact_email && (
              <p className="text-sm text-destructive">{errors.family_contact_email.message}</p>
            )}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="pedagogical_notes">Notas pedagógicas</Label>
            <Textarea id="pedagogical_notes" {...register("pedagogical_notes")} />
          </div>
          {formError && (
            <p role="alert" className="text-sm text-destructive">
              {formError}
            </p>
          )}
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? "Guardando…" : "Guardar"}
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}
