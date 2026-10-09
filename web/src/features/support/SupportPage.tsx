import { useState } from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { useLocation } from "react-router-dom"
import { z } from "zod"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { PageHeader } from "@/components/ui/page-header"
import { SectionCard } from "@/components/ui/section-card"
import { Textarea } from "@/components/ui/textarea"
import type { SupportMessageKind } from "@/types"
import { sendSupportMessage } from "./supportApi"

// Client validation is UX only; the backend (StoreSupportMessageRequest) is
// the real boundary.
const schema = z.object({
  kind: z.enum(["issue", "improvement"]),
  message: z
    .string()
    .trim()
    .min(5, "Contanos un poco más (mínimo 5 caracteres)")
    .max(3000, "Máximo 3000 caracteres"),
})

type Values = z.infer<typeof schema>

const kindOptions: { value: SupportMessageKind; label: string; hint: string }[] = [
  { value: "issue", label: "Algo no funciona", hint: "Soporte" },
  { value: "improvement", label: "Proponé una mejora", hint: "Cosas que les vendrían bien" },
]

/**
 * "Ayuda y sugerencias" — one form for everyone, two paths (support vs.
 * improvement). The screen the user came from is passed through the sidebar
 * link's router state and sent along; role and school are resolved server-side.
 */
export function SupportPage() {
  const location = useLocation()
  const from = (location.state as { from?: string } | null)?.from ?? null
  const [sent, setSent] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { kind: "issue", message: "" },
  })

  async function onSubmit(values: Values) {
    setError(null)
    setSent(false)
    try {
      await sendSupportMessage({ kind: values.kind, message: values.message, screen: from })
      reset({ kind: values.kind, message: "" })
      setSent(true)
    } catch {
      setError("No pudimos enviar tu mensaje. Probá de nuevo en unos minutos.")
    }
  }

  return (
    <div className="grid gap-6">
      <PageHeader
        title="Ayuda y sugerencias"
        description="Contanos si algo no funciona o qué mejora les vendría bien."
      />

      <SectionCard title="Tu mensaje">
        <form onSubmit={handleSubmit(onSubmit)} className="grid gap-4" noValidate>
          <fieldset className="grid gap-2">
            <legend className="text-sm font-medium">¿Qué querés contarnos?</legend>
            {kindOptions.map((option) => (
              <label key={option.value} className="flex items-center gap-2 text-sm">
                <input type="radio" value={option.value} {...register("kind")} />
                <span>
                  {option.label} <span className="text-muted-foreground">— {option.hint}</span>
                </span>
              </label>
            ))}
          </fieldset>

          <p role="note" className="rounded-md bg-muted p-3 text-sm">
            No escribas nombres de alumnos ni datos de salud: estos mensajes salen del circuito de
            permisos de Aula+.
          </p>

          <div className="grid gap-2">
            <Label htmlFor="message">Mensaje</Label>
            <Textarea id="message" rows={6} {...register("message")} />
            {errors.message && <p className="text-sm text-destructive">{errors.message.message}</p>}
          </div>

          <div className="flex items-center gap-3">
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? "Enviando…" : "Enviar"}
            </Button>
            {sent && (
              <p role="status" className="text-sm text-muted-foreground">
                ¡Gracias! Recibimos tu mensaje.
              </p>
            )}
            {error && (
              <p role="alert" className="text-sm text-destructive">
                {error}
              </p>
            )}
          </div>
        </form>
      </SectionCard>
    </div>
  )
}
