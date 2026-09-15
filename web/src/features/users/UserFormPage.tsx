import { useEffect, useState } from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { useNavigate, useOutletContext, useParams } from "react-router-dom"
import { isAxiosError } from "axios"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { SingleSelect } from "@/components/ui/single-select"
import { roleLabels, type Role } from "@/types"
import * as usersApi from "./usersApi"

const ROLE_OPTIONS: { value: Role; label: string }[] = (Object.keys(roleLabels) as Role[]).map(
  (role) => ({ value: role, label: roleLabels[role] }),
)

const schema = z.object({
  name: z.string().min(1, "Ingresá el nombre"),
  email: z.string().min(1, "Ingresá el email").email("Email inválido"),
  role: z.enum(["teacher", "director", "psychopedagogue"]),
})

type FormValues = z.infer<typeof schema>

export interface UserFormOutletContext {
  onSaved: () => void
}

/**
 * Create ("Invitar usuario") / edit form, shown as a modal over the
 * `UsersListPage` list (route `/usuarios/nueva` | `/usuarios/:id`). In create
 * mode all three fields are editable and success sends an invitation email;
 * in edit mode the email is read-only (immutable server-side, see
 * `UserController::update`, which never accepts an `email` key).
 */
export function UserFormPage() {
  const navigate = useNavigate()
  const { id } = useParams()
  const isEdit = id !== undefined
  const outletContext = useOutletContext<UserFormOutletContext | null>()

  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    reset,
    watch,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { name: "", email: "", role: "teacher" },
  })

  // In edit mode, hydrate from the single-resource endpoint.
  useEffect(() => {
    if (!isEdit) return
    usersApi
      .fetchUser(Number(id))
      .then((user) => {
        reset({ name: user.name, email: user.email, role: user.roles[0] ?? "teacher" })
      })
      .catch(() => {
        setFormError("No pudimos cargar el usuario.")
      })
  }, [id, isEdit, reset])

  function close() {
    navigate("/usuarios")
  }

  async function onSubmit(values: FormValues) {
    setFormError(null)
    try {
      if (isEdit) {
        await usersApi.updateUser(Number(id), { name: values.name, role: values.role })
      } else {
        await usersApi.createUser(values)
      }
      outletContext?.onSaved()
      close()
    } catch (error) {
      if (isAxiosError(error) && error.response?.status === 422) {
        setFormError("Revisá los datos. ¿Ya existe un usuario con ese email?")
      } else {
        setFormError("No pudimos guardar el usuario.")
      }
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && close()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{isEdit ? "Editar usuario" : "Invitar usuario"}</DialogTitle>
        </DialogHeader>
        <form onSubmit={handleSubmit(onSubmit)} className="grid gap-4" noValidate>
          <div className="grid gap-2">
            <Label htmlFor="name">Nombre</Label>
            <Input id="name" {...register("name")} />
            {errors.name && <p className="text-sm text-destructive">{errors.name.message}</p>}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="email">Email</Label>
            <Input id="email" type="email" readOnly={isEdit} {...register("email")} />
            {isEdit && (
              <p className="text-xs text-muted-foreground">El email no se puede modificar.</p>
            )}
            {errors.email && <p className="text-sm text-destructive">{errors.email.message}</p>}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="role">Rol</Label>
            <SingleSelect
              id="role"
              options={ROLE_OPTIONS}
              value={watch("role")}
              onChange={(value) => setValue("role", value as Role)}
            />
          </div>
          {formError && (
            <p role="alert" className="text-sm text-destructive">
              {formError}
            </p>
          )}
          <div className="flex justify-end gap-2">
            <Button type="button" variant="ghost" onClick={close}>
              Cancelar
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? "Guardando…" : isEdit ? "Guardar cambios" : "Invitar"}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
