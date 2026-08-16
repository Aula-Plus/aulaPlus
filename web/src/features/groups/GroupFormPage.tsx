import { useEffect, useState } from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { useNavigate, useParams } from "react-router-dom"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import * as groupsApi from "./groupsApi"

const groupSchema = z.object({
  name: z.string().min(1, "Ingresá un nombre"),
  level: z.string().optional(),
  year: z.string().optional(),
})

type GroupValues = z.infer<typeof groupSchema>

export function GroupFormPage() {
  const { id } = useParams()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const [formError, setFormError] = useState<string | null>(null)
  const [isDeleting, setIsDeleting] = useState(false)

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<GroupValues>({
    resolver: zodResolver(groupSchema),
    defaultValues: { name: "", level: "", year: "" },
  })

  useEffect(() => {
    if (!id) return
    groupsApi.fetchGroup(Number(id)).then((group) => {
      reset({ name: group.name, level: group.level ?? "", year: group.year ?? "" })
    })
  }, [id, reset])

  async function onSubmit(values: GroupValues) {
    setFormError(null)
    try {
      if (isEdit) {
        await groupsApi.updateGroup(Number(id), values)
      } else {
        await groupsApi.createGroup(values)
      }
      navigate("/clases", { replace: true })
    } catch {
      setFormError("No pudimos guardar la clase.")
    }
  }

  async function onDelete() {
    if (!id) return
    if (!window.confirm("¿Eliminar esta clase? Esta acción no se puede deshacer.")) return

    setFormError(null)
    setIsDeleting(true)
    try {
      await groupsApi.deleteGroup(Number(id))
      navigate("/clases", { replace: true })
    } catch {
      setFormError("No pudimos eliminar la clase.")
      setIsDeleting(false)
    }
  }

  return (
    <Card className="max-w-lg">
      <CardHeader>
        <CardTitle>{isEdit ? "Editar clase" : "Nueva clase"}</CardTitle>
      </CardHeader>
      <CardContent>
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
            <Label htmlFor="year">Año</Label>
            <Input id="year" {...register("year")} />
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
              <Button type="button" variant="destructive" disabled={isDeleting} onClick={onDelete}>
                {isDeleting ? "Eliminando…" : "Eliminar clase"}
              </Button>
            )}
          </div>
        </form>
      </CardContent>
    </Card>
  )
}
