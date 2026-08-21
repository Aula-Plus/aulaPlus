import { useEffect, useState } from "react"
import { Controller, useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { useNavigate, useOutletContext, useParams } from "react-router-dom"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { MultiSelect } from "@/components/ui/multi-select"
import { ConfirmDialog } from "@/components/ui/confirm-dialog"
import { getCurrentSchoolYear } from "@/lib/schoolYear"
import * as groupsApi from "./groupsApi"
import type { Teacher } from "./groupsApi"

const groupSchema = z.object({
  name: z.string().min(1, "Ingresá un nombre"),
  level: z.string().optional(),
  school_year: z.string().min(1, "Ingresá el año lectivo"),
  teacher_ids: z.array(z.number()),
})

type GroupValues = z.infer<typeof groupSchema>

export interface GroupFormOutletContext {
  onSaved: () => void
}

export function GroupFormPage() {
  const { id } = useParams()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const outletContext = useOutletContext<GroupFormOutletContext | null>()
  const [formError, setFormError] = useState<string | null>(null)
  const [isDeleting, setIsDeleting] = useState(false)
  const [teachers, setTeachers] = useState<Teacher[]>([])

  const {
    register,
    handleSubmit,
    reset,
    control,
    formState: { errors, isSubmitting },
  } = useForm<GroupValues>({
    resolver: zodResolver(groupSchema),
    defaultValues: {
      name: "",
      level: "",
      school_year: String(getCurrentSchoolYear()),
      teacher_ids: [],
    },
  })

  useEffect(() => {
    groupsApi.fetchTeachers().then(setTeachers)
  }, [])

  useEffect(() => {
    if (!id) return
    groupsApi.fetchGroup(Number(id)).then((group) => {
      reset({
        name: group.name,
        level: group.level ?? "",
        school_year: String(group.school_year),
        teacher_ids: group.teachers.map((teacher) => teacher.id),
      })
    })
  }, [id, reset])

  async function onSubmit(values: GroupValues) {
    setFormError(null)
    try {
      const input = {
        name: values.name,
        level: values.level,
        school_year: Number(values.school_year),
        teacher_ids: values.teacher_ids,
      }
      if (isEdit) {
        await groupsApi.updateGroup(Number(id), input)
      } else {
        await groupsApi.createGroup(input)
      }
      outletContext?.onSaved()
      navigate("/clases", { replace: true })
    } catch {
      setFormError("No pudimos guardar la clase.")
    }
  }

  async function onDelete() {
    if (!id) return
    setFormError(null)
    setIsDeleting(true)
    try {
      await groupsApi.deleteGroup(Number(id))
      outletContext?.onSaved()
      navigate("/clases", { replace: true })
    } catch {
      setFormError("No pudimos eliminar la clase.")
      setIsDeleting(false)
    }
  }

  function handleOpenChange(open: boolean) {
    if (!open) navigate("/clases", { replace: true })
  }

  const teacherOptions = teachers.map((teacher) => ({ id: teacher.id, label: teacher.name }))

  return (
    <Dialog open onOpenChange={handleOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>{isEdit ? "Editar clase" : "Nueva clase"}</DialogTitle>
        </DialogHeader>
        <form onSubmit={handleSubmit(onSubmit)} className="grid gap-4" noValidate>
          <div className="grid gap-2">
            <Label htmlFor="name">Nombre</Label>
            <Input id="name" {...register("name")} />
            {errors.name && <p className="text-sm text-destructive">{errors.name.message}</p>}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="level">Nivel</Label>
            <Input id="level" {...register("level")} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="school_year">Año lectivo</Label>
            <Input id="school_year" type="number" {...register("school_year")} />
            {errors.school_year && (
              <p className="text-sm text-destructive">{errors.school_year.message}</p>
            )}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="teacher_ids">Docentes</Label>
            <Controller
              name="teacher_ids"
              control={control}
              render={({ field }) => (
                <MultiSelect
                  id="teacher_ids"
                  options={teacherOptions}
                  selected={field.value}
                  onChange={field.onChange}
                />
              )}
            />
          </div>
          {formError && (
            <p role="alert" className="text-sm text-destructive">
              {formError}
            </p>
          )}
          <div className="flex gap-2">
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? "Guardando…" : "Guardar"}
            </Button>
            {isEdit && (
              <ConfirmDialog
                trigger={
                  <Button type="button" variant="destructive" disabled={isDeleting}>
                    {isDeleting ? "Eliminando…" : "Eliminar clase"}
                  </Button>
                }
                title="Eliminar clase"
                description="Esta acción no se puede deshacer."
                confirmLabel="Eliminar"
                isConfirming={isDeleting}
                onConfirm={onDelete}
              />
            )}
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
