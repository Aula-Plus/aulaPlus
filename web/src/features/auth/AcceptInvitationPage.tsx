import { useEffect, useState } from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { useNavigate, useSearchParams } from "react-router-dom"
import { isAxiosError } from "axios"
import { Button } from "@/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import * as usersApi from "@/features/users/usersApi"

// Mirror of the backend rule (Password::min(8)->letters()->numbers()).
const schema = z
  .object({
    password: z
      .string()
      .min(8, "Mínimo 8 caracteres")
      .regex(/[a-zA-Z]/, "Debe incluir letras")
      .regex(/[0-9]/, "Debe incluir números"),
    confirm: z.string(),
  })
  .refine((data) => data.password === data.confirm, {
    path: ["confirm"],
    message: "Las contraseñas no coinciden",
  })

type FormValues = z.infer<typeof schema>

/**
 * Public invitation-acceptance screen (route `/aceptar-invitacion`, outside the
 * protected layout). Validates the `?token=` param, then lets the invitee set
 * their password to activate the account.
 */
export function AcceptInvitationPage() {
  const [params] = useSearchParams()
  const token = params.get("token") ?? ""
  const navigate = useNavigate()

  const [invitation, setInvitation] = useState<{ email: string; school_name: string } | null>(null)
  const [invalid, setInvalid] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { password: "", confirm: "" },
  })

  useEffect(() => {
    if (!token) {
      setInvalid(true)
      return
    }
    usersApi
      .fetchInvitation(token)
      .then(setInvitation)
      .catch(() => setInvalid(true))
  }, [token])

  async function onSubmit(values: FormValues) {
    setFormError(null)
    try {
      await usersApi.acceptInvitation(token, values.password)
      navigate("/login", {
        replace: true,
        state: { message: "Tu cuenta fue activada. Ya podés iniciar sesión." },
      })
    } catch (error) {
      if (isAxiosError(error) && error.response?.status === 410) {
        setInvalid(true)
      } else {
        setFormError("No pudimos activar tu cuenta. Intentá de nuevo.")
      }
    }
  }

  return (
    <div className="flex min-h-svh items-center justify-center bg-muted/40 p-4">
      <Card className="w-full max-w-sm">
        {invalid ? (
          <>
            <CardHeader>
              <CardTitle className="text-2xl">Invitación no válida</CardTitle>
              <CardDescription>
                Este enlace ya fue usado o venció. Pedile al director que te reenvíe la invitación.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <Button className="w-full" onClick={() => navigate("/login")}>
                Ir a iniciar sesión
              </Button>
            </CardContent>
          </>
        ) : !invitation ? (
          <CardHeader>
            <CardTitle className="text-2xl">Aula+</CardTitle>
            <CardDescription>Cargando invitación…</CardDescription>
          </CardHeader>
        ) : (
          <>
            <CardHeader>
              <CardTitle className="text-2xl">Activá tu cuenta</CardTitle>
              <CardDescription>
                {invitation.school_name} · <span>{invitation.email}</span>
              </CardDescription>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleSubmit(onSubmit)} className="grid gap-4" noValidate>
                <div className="grid gap-2">
                  <Label htmlFor="password">Contraseña</Label>
                  <Input
                    id="password"
                    type="password"
                    autoComplete="new-password"
                    {...register("password")}
                  />
                  {errors.password && (
                    <p className="text-sm text-destructive">{errors.password.message}</p>
                  )}
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="confirm">Repetir contraseña</Label>
                  <Input
                    id="confirm"
                    type="password"
                    autoComplete="new-password"
                    {...register("confirm")}
                  />
                  {errors.confirm && (
                    <p className="text-sm text-destructive">{errors.confirm.message}</p>
                  )}
                </div>
                {formError && (
                  <p role="alert" className="text-sm text-destructive">
                    {formError}
                  </p>
                )}
                <Button type="submit" disabled={isSubmitting}>
                  {isSubmitting ? "Activando…" : "Activar cuenta"}
                </Button>
              </form>
            </CardContent>
          </>
        )}
      </Card>
    </div>
  )
}
