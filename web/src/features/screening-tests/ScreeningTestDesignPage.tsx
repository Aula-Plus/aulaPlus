import { useCallback, useEffect, useState } from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { useAuth } from "@/features/auth/AuthContext"
import { canApproveScreeningTestDesign, canManageScreeningTests } from "@/lib/permissions"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import type { ScreeningTestDesign, ScreeningTestType } from "@/types"
import * as screeningApi from "./screeningTestsApi"
import { ScreeningTestDesignCard } from "./ScreeningTestDesignCard"

const typeSchema = z.object({
  name: z.string().min(1, "Ingresá un nombre"),
})

type TypeValues = z.infer<typeof typeSchema>

// Cutoffs are kept as strings (RHF number inputs yield strings) and validated
// as real numbers here, so an empty field fails instead of silently coercing to
// 0. Converted to numbers only on submit.
const numericString = z
  .string()
  .min(1, "Ingresá un número")
  .refine((value) => !Number.isNaN(Number(value)), "Número inválido")

const designSchema = z
  .object({
    cutoff_low: numericString,
    cutoff_high: numericString,
    meaning_red: z.string().min(1, "Requerido"),
    meaning_yellow: z.string().min(1, "Requerido"),
    meaning_green: z.string().min(1, "Requerido"),
  })
  // UX-only mirror of the backend `gte:cutoff_low` rule — the server remains the
  // real boundary (docs/prompts/12 §4).
  .refine((values) => Number(values.cutoff_low) < Number(values.cutoff_high), {
    path: ["cutoff_high"],
    message: "El corte alto debe ser mayor que el bajo",
  })

type DesignValues = z.infer<typeof designSchema>

/**
 * Design screen (pantalla 8), route `/pruebas-de-sondeo/tipos`. Lists the
 * school's screening-test types with their in-force approved design, lets
 * psychopedagogy create a type and author/edit a design, and lets a director
 * approve/reject a pending design.
 *
 * Contract note: the API exposes no listing of non-approved designs — the type
 * resource only carries `current_design` (the approved one). A pending design
 * is therefore known only as the response to creating it, held here in
 * `pendingByType` for the rest of the session. See the PR notes: a director
 * loading this page fresh cannot see a design a psychopedagogue left pending in
 * another session until the backend adds a pending-designs endpoint.
 */
export function ScreeningTestDesignPage() {
  const { user } = useAuth()
  const canManage = canManageScreeningTests(user)
  const canApprove = canApproveScreeningTestDesign(user)

  const [types, setTypes] = useState<ScreeningTestType[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [typeFormError, setTypeFormError] = useState<string | null>(null)
  // Designs discovered this session (POST responses / approve-reject results),
  // keyed by type id, since the API cannot list pending designs on load.
  const [pendingByType, setPendingByType] = useState<Record<number, ScreeningTestDesign>>({})
  const [busyDesignId, setBusyDesignId] = useState<number | null>(null)

  const loadTypes = useCallback(() => {
    return screeningApi
      .fetchScreeningTestTypes()
      .then(setTypes)
      .catch(() => setError("No pudimos cargar los tipos de prueba."))
  }, [])

  useEffect(() => {
    loadTypes()
  }, [loadTypes])

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<TypeValues>({ resolver: zodResolver(typeSchema), defaultValues: { name: "" } })

  async function onCreateType(values: TypeValues) {
    setTypeFormError(null)
    try {
      await screeningApi.createScreeningTestType({ name: values.name.trim() })
      reset({ name: "" })
      await loadTypes()
    } catch {
      setTypeFormError("No pudimos crear el tipo de prueba.")
    }
  }

  function handleDesignCreated(typeId: number, design: ScreeningTestDesign) {
    setPendingByType((prev) => ({ ...prev, [typeId]: design }))
  }

  async function decide(design: ScreeningTestDesign, approve: boolean) {
    setBusyDesignId(design.id)
    try {
      const updated = approve
        ? await screeningApi.approveScreeningTestDesign(design.id)
        : await screeningApi.rejectScreeningTestDesign(design.id)
      setPendingByType((prev) => ({ ...prev, [design.screening_test_type_id]: updated }))
      // An approval promotes the design to the type's in-force one; refresh the
      // list so `current_design` reflects it.
      if (approve) {
        await loadTypes()
      }
    } catch {
      setError("No pudimos actualizar el diseño.")
    } finally {
      setBusyDesignId(null)
    }
  }

  if (error) {
    return <p className="text-sm text-destructive">{error}</p>
  }

  if (!types) {
    return <p className="text-muted-foreground">Cargando…</p>
  }

  return (
    <div className="grid gap-6">
      <h1 className="text-2xl font-semibold">Pruebas de sondeo — Diseño</h1>

      {canManage && (
        <Card>
          <CardHeader>
            <CardTitle>Nuevo tipo</CardTitle>
          </CardHeader>
          <CardContent>
            <form
              onSubmit={handleSubmit(onCreateType)}
              className="flex flex-wrap items-end gap-3"
              noValidate
            >
              <div className="grid flex-1 gap-2">
                <Label htmlFor="name">Nombre del tipo</Label>
                <Input id="name" {...register("name")} />
                {errors.name && <p className="text-sm text-destructive">{errors.name.message}</p>}
              </div>
              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting ? "Creando…" : "Crear tipo"}
              </Button>
              {typeFormError && (
                <p role="alert" className="w-full text-sm text-destructive">
                  {typeFormError}
                </p>
              )}
            </form>
          </CardContent>
        </Card>
      )}

      {types.length === 0 ? (
        <p className="text-muted-foreground">Todavía no hay tipos de prueba.</p>
      ) : (
        <section className="grid gap-4">
          {types.map((type) => {
            const pending = pendingByType[type.id]
            return (
              <Card key={type.id}>
                <CardHeader>
                  <CardTitle>{type.name}</CardTitle>
                </CardHeader>
                <CardContent className="grid gap-4">
                  <div>
                    <h3 className="text-sm font-semibold">Diseño vigente</h3>
                    {type.current_design ? (
                      <div className="mt-2">
                        <ScreeningTestDesignCard
                          design={type.current_design}
                          canApprove={canApprove}
                          onApprove={(d) => decide(d, true)}
                          onReject={(d) => decide(d, false)}
                          busy={busyDesignId === type.current_design.id}
                        />
                      </div>
                    ) : (
                      <p className="mt-1 text-sm text-muted-foreground">
                        Sin diseño aprobado.
                      </p>
                    )}
                  </div>

                  {/* A design created/decided this session (the API cannot list
                      pending ones on load). Hidden once approved — it then shows
                      as the vigente design above after the list refresh. */}
                  {pending && pending.approved !== true && (
                    <div>
                      <h3 className="text-sm font-semibold">Diseño de esta sesión</h3>
                      <div className="mt-2">
                        <ScreeningTestDesignCard
                          design={pending}
                          canApprove={canApprove}
                          onApprove={(d) => decide(d, true)}
                          onReject={(d) => decide(d, false)}
                          busy={busyDesignId === pending.id}
                        />
                      </div>
                    </div>
                  )}

                  {canManage && (
                    <DesignForm
                      typeId={type.id}
                      hasCurrentDesign={type.current_design !== null}
                      onCreated={(design) => handleDesignCreated(type.id, design)}
                    />
                  )}
                </CardContent>
              </Card>
            )
          })}
        </section>
      )}
    </div>
  )
}

/**
 * The per-type design authoring form (psychopedagogy only). Each submit creates
 * a NEW pending version; "editing" a design is exactly this.
 */
function DesignForm({
  typeId,
  hasCurrentDesign,
  onCreated,
}: {
  typeId: number
  hasCurrentDesign: boolean
  onCreated: (design: ScreeningTestDesign) => void
}) {
  const [formError, setFormError] = useState<string | null>(null)
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<DesignValues>({
    resolver: zodResolver(designSchema),
    defaultValues: {
      cutoff_low: "",
      cutoff_high: "",
      meaning_red: "",
      meaning_yellow: "",
      meaning_green: "",
    },
  })

  async function onSubmit(values: DesignValues) {
    setFormError(null)
    try {
      const design = await screeningApi.createScreeningTestDesign(typeId, {
        cutoff_low: Number(values.cutoff_low),
        cutoff_high: Number(values.cutoff_high),
        meaning_red: values.meaning_red.trim(),
        meaning_yellow: values.meaning_yellow.trim(),
        meaning_green: values.meaning_green.trim(),
      })
      reset({
        cutoff_low: "",
        cutoff_high: "",
        meaning_red: "",
        meaning_yellow: "",
        meaning_green: "",
      })
      onCreated(design)
    } catch {
      setFormError("No pudimos guardar el diseño.")
    }
  }

  return (
    <div>
      <h3 className="text-sm font-semibold">
        {hasCurrentDesign ? "Nueva versión del diseño" : "Nuevo diseño"}
      </h3>
      <form onSubmit={handleSubmit(onSubmit)} className="mt-2 grid gap-3 sm:grid-cols-2" noValidate>
        <div className="grid gap-2">
          <Label htmlFor={`cutoff_low_${typeId}`}>Corte bajo (rojo ≤)</Label>
          <Input
            id={`cutoff_low_${typeId}`}
            type="number"
            step="any"
            {...register("cutoff_low")}
          />
          {errors.cutoff_low && (
            <p className="text-sm text-destructive">{errors.cutoff_low.message}</p>
          )}
        </div>
        <div className="grid gap-2">
          <Label htmlFor={`cutoff_high_${typeId}`}>Corte alto (verde ≥)</Label>
          <Input
            id={`cutoff_high_${typeId}`}
            type="number"
            step="any"
            {...register("cutoff_high")}
          />
          {errors.cutoff_high && (
            <p className="text-sm text-destructive">{errors.cutoff_high.message}</p>
          )}
        </div>
        <div className="grid gap-2">
          <Label htmlFor={`meaning_red_${typeId}`}>Significado rojo</Label>
          <Textarea id={`meaning_red_${typeId}`} {...register("meaning_red")} />
          {errors.meaning_red && (
            <p className="text-sm text-destructive">{errors.meaning_red.message}</p>
          )}
        </div>
        <div className="grid gap-2">
          <Label htmlFor={`meaning_yellow_${typeId}`}>Significado amarillo</Label>
          <Textarea id={`meaning_yellow_${typeId}`} {...register("meaning_yellow")} />
          {errors.meaning_yellow && (
            <p className="text-sm text-destructive">{errors.meaning_yellow.message}</p>
          )}
        </div>
        <div className="grid gap-2">
          <Label htmlFor={`meaning_green_${typeId}`}>Significado verde</Label>
          <Textarea id={`meaning_green_${typeId}`} {...register("meaning_green")} />
          {errors.meaning_green && (
            <p className="text-sm text-destructive">{errors.meaning_green.message}</p>
          )}
        </div>
        <div className="flex items-end">
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? "Guardando…" : "Guardar diseño"}
          </Button>
        </div>
        {formError && (
          <p role="alert" className="text-sm text-destructive sm:col-span-2">
            {formError}
          </p>
        )}
      </form>
    </div>
  )
}
