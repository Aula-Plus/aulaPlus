import { useCallback, useEffect, useRef, useState } from "react"
import { useSearchParams } from "react-router-dom"
import { useFieldArray, useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { ClipboardList } from "lucide-react"
import { useAuth } from "@/features/auth/AuthContext"
import {
  canEditScreeningTestDesign,
  canManageScreeningTests,
  canRevertScreeningTestDesign,
} from "@/lib/permissions"
import { formatShortDate } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import { ConfirmDialog } from "@/components/ui/confirm-dialog"
import { EmptyState } from "@/components/ui/empty-state"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { PageHeader } from "@/components/ui/page-header"
import { SectionCard } from "@/components/ui/section-card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { Textarea } from "@/components/ui/textarea"
import {
  schoolLevelLabels,
  schoolLevels,
  screeningColorLabels,
  screeningColors,
  screeningFrequencies,
  screeningFrequencyLabels,
} from "@/types"
import type {
  ScreeningBand,
  ScreeningImpact,
  ScreeningTestType,
} from "@/types"
import * as screeningApi from "./screeningTestsApi"
import { bandRanges, ScreeningBandList, screeningColorDot } from "./ScreeningBandList"

const MIN_BANDS = 2
const MAX_BANDS = 5

/**
 * Screens 07/08 «Diseño de cada prueba», route `/pruebas-de-sondeo/tipos`.
 * Lists the school's screenings and opens one to edit its general data
 * (name, area, population, frequency, teacher access) and its bands (2 to 5,
 * each with a name, a colour, the score it goes up to and a mandatory
 * meaning). A saved design rules right away and Dirección is notified; the
 * last change shows before/after counts and Dirección can revert it. The AI
 * takes no part in any of this.
 */
export function ScreeningTestDesignPage() {
  const { user } = useAuth()
  const canCreate = canManageScreeningTests(user)
  const canEdit = canEditScreeningTestDesign(user)
  const canRevert = canRevertScreeningTestDesign(user)
  const [searchParams, setSearchParams] = useSearchParams()
  const openId = Number(searchParams.get("abrir")) || null

  const [types, setTypes] = useState<ScreeningTestType[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  const loadTypes = useCallback(() => {
    return screeningApi
      .fetchScreeningTestTypes()
      .then(setTypes)
      .catch(() => setError("No pudimos cargar los screenings."))
  }, [])

  useEffect(() => {
    loadTypes()
  }, [loadTypes])

  function open(typeId: number | null) {
    setSearchParams(typeId ? { abrir: String(typeId) } : {})
  }

  if (error) {
    return <p className="text-sm text-destructive">{error}</p>
  }

  if (!types) {
    return <p className="text-muted-foreground">Cargando…</p>
  }

  const opened = types.find((type) => type.id === openId) ?? null

  return (
    <div className="grid gap-6">
      <PageHeader
        title="Screenings — Diseño de cada screening"
        description="Población, cada cuánto se toma, las franjas, qué significa cada una y quiénes ven los resultados. Lo escribe el colegio: la IA no participa."
      />

      {canCreate && <NewScreeningForm onCreated={(type) => loadTypes().then(() => open(type.id))} />}

      <SectionCard title="Screenings del colegio">
        {types.length === 0 ? (
          <EmptyState icon={ClipboardList} message="Todavía no hay screenings." />
        ) : (
          <div className="grid gap-3">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Screening</TableHead>
                  <TableHead>Área</TableHead>
                  <TableHead>Población</TableHead>
                  <TableHead>Estado</TableHead>
                  <TableHead>Última actualización</TableHead>
                  <TableHead>Quiénes ven los resultados</TableHead>
                  <TableHead />
                </TableRow>
              </TableHeader>
              <TableBody>
                {types.map((type) => (
                  <TableRow key={type.id}>
                    <TableCell>
                      <p className="font-medium">{type.name}</p>
                      {type.frequency && (
                        <p className="text-xs text-muted-foreground">{frequencyText(type)}</p>
                      )}
                    </TableCell>
                    <TableCell className="text-muted-foreground">{type.area ?? "—"}</TableCell>
                    <TableCell className="text-muted-foreground">{levelsText(type)}</TableCell>
                    <TableCell>{type.current_design ? "Vigente" : "Sin franjas"}</TableCell>
                    <TableCell className="text-sm">
                      {type.current_design ? lastUpdateText(type) : "—"}
                    </TableCell>
                    <TableCell className="text-sm">{viewersText(type)}</TableCell>
                    <TableCell>
                      <Button type="button" size="sm" variant="outline" onClick={() => open(type.id)}>
                        {canEdit ? "Abrir y editar" : "Abrir"}
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            <p className="text-xs text-muted-foreground">
              Los cambios rigen al guardar; se le avisa a Dirección, que puede volverlos atrás.
              Cambiar el nombre no borra la historia.
            </p>
          </div>
        )}
      </SectionCard>

      {opened && (
        <ScreeningEditor
          key={opened.id}
          type={opened}
          canEdit={canEdit}
          canRevert={canRevert}
          onClose={() => open(null)}
          onChanged={loadTypes}
        />
      )}
    </div>
  )
}

function frequencyText(type: ScreeningTestType): string {
  if (!type.frequency) return ""
  if (type.frequency === "every_few_years" && type.frequency_years) {
    return `Cada ${type.frequency_years} años`
  }
  return screeningFrequencyLabels[type.frequency]
}

function levelsText(type: ScreeningTestType): string {
  return type.levels.length > 0 ? type.levels.map((level) => schoolLevelLabels[level]).join(", ") : "—"
}

function viewersText(type: ScreeningTestType): string {
  return type.teacher_visible ? "Psicopedagogía, Dirección y docentes" : "Psicopedagogía y Dirección"
}

function lastUpdateText(type: ScreeningTestType): string {
  const design = type.current_design
  if (!design) return "—"
  const date = design.created_at ? formatShortDate(design.created_at) : ""
  return design.created_by_name ? `${date} · ${design.created_by_name}` : date
}

// ── Nuevo screening ─────────────────────────────────────────────────────────

const newSchema = z.object({
  name: z.string().trim().min(1, "Ingresá un nombre").max(255, "Máximo 255 caracteres"),
  area: z.string().max(100, "Máximo 100 caracteres"),
})

type NewValues = z.infer<typeof newSchema>

function NewScreeningForm({ onCreated }: { onCreated: (type: ScreeningTestType) => void }) {
  const [formError, setFormError] = useState<string | null>(null)
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<NewValues>({ resolver: zodResolver(newSchema), defaultValues: { name: "", area: "" } })

  async function onSubmit(values: NewValues) {
    setFormError(null)
    try {
      const type = await screeningApi.createScreeningTestType({
        name: values.name.trim(),
        area: values.area.trim() || null,
      })
      reset({ name: "", area: "" })
      onCreated(type)
    } catch {
      setFormError("No pudimos crear el screening.")
    }
  }

  return (
    <SectionCard title="Nuevo screening">
      <form onSubmit={handleSubmit(onSubmit)} className="flex flex-wrap items-end gap-3" noValidate>
        <div className="grid flex-1 gap-2">
          <Label htmlFor="name">Nombre del screening</Label>
          <Input id="name" {...register("name")} />
          {errors.name && <p className="text-sm text-destructive">{errors.name.message}</p>}
        </div>
        <div className="grid flex-1 gap-2">
          <Label htmlFor="area">Área (opcional)</Label>
          <Input id="area" placeholder="Ej: lectoescritura" {...register("area")} />
          {errors.area && <p className="text-sm text-destructive">{errors.area.message}</p>}
        </div>
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? "Creando…" : "Crear screening"}
        </Button>
        {formError && (
          <p role="alert" className="w-full text-sm text-destructive">
            {formError}
          </p>
        )}
      </form>
    </SectionCard>
  )
}

// ── Editor of one screening ─────────────────────────────────────────────────

function ScreeningEditor({
  type,
  canEdit,
  canRevert,
  onClose,
  onChanged,
}: {
  type: ScreeningTestType
  canEdit: boolean
  canRevert: boolean
  onClose: () => void
  onChanged: () => Promise<unknown>
}) {
  return (
    <section className="grid gap-4" aria-label={`Diseño de ${type.name}`}>
      <div className="flex items-center justify-between gap-3">
        <h2 className="text-lg font-semibold">{type.name}</h2>
        <Button type="button" size="sm" variant="ghost" onClick={onClose}>
          Cerrar
        </Button>
      </div>

      {type.current_design && type.previous_design && (
        <LastChangeCard type={type} canRevert={canRevert} onReverted={onChanged} />
      )}

      {canEdit ? (
        <>
          <GeneralDataForm type={type} onSaved={onChanged} />
          <BandsForm type={type} onSaved={onChanged} />
        </>
      ) : (
        type.current_design && (
          <SectionCard title="Franjas y qué significa cada una">
            <ScreeningBandList bands={type.current_design.bands} />
          </SectionCard>
        )
      )}
    </section>
  )
}

/**
 * The change that already rules: before/after range and students per band,
 * the reason and who changed it. Dirección can go back to the previous design.
 */
function LastChangeCard({
  type,
  canRevert,
  onReverted,
}: {
  type: ScreeningTestType
  canRevert: boolean
  onReverted: () => Promise<unknown>
}) {
  const current = type.current_design!
  const previous = type.previous_design!
  const [impact, setImpact] = useState<ScreeningImpact | null>(null)
  const [reverting, setReverting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    screeningApi
      .previewScreeningTestImpact(type.id, previous.bands)
      .then(setImpact)
      .catch(() => setImpact(null))
  }, [type.id, previous.bands])

  async function revert() {
    setReverting(true)
    setError(null)
    try {
      await screeningApi.revertScreeningTestDesign(current.id)
      await onReverted()
    } catch {
      setError("No pudimos volver atrás el cambio.")
    } finally {
      setReverting(false)
    }
  }

  // The preview computes "proposed" = previous bands and "current" = in force.
  const before = impact?.proposed ?? null
  const after = impact?.current ?? null
  const beforeRanges = bandRanges(previous.bands)
  const afterRanges = bandRanges(current.bands)
  const rows = Math.max(previous.bands.length, current.bands.length)

  return (
    <SectionCard
      title={`${type.name} · cambio que ya rige`}
      action={
        canRevert && (
          <ConfirmDialog
            trigger={
              <Button type="button" size="sm" variant="outline" disabled={reverting}>
                Volver atrás
              </Button>
            }
            title="Volver atrás este cambio"
            description="Vuelve a regir el diseño anterior. El cambio queda en la historia y se le avisa a quien lo hizo."
            confirmLabel="Volver atrás"
            isConfirming={reverting}
            onConfirm={revert}
          />
        )
      }
    >
      <div className="grid gap-3">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Antes · franja, rango y alumnos</TableHead>
              <TableHead>Ahora · franja, rango y alumnos</TableHead>
              <TableHead>Qué significa ahora</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {Array.from({ length: rows }, (_, index) => {
              const was = previous.bands[index]
              const is = current.bands[index]
              return (
                <TableRow key={index}>
                  <TableCell className="text-sm">
                    {was ? `${was.name} · ${beforeRanges[index]}${before ? ` · ${before[index]?.count ?? 0}` : ""}` : "—"}
                  </TableCell>
                  <TableCell className="text-sm">
                    {is ? `${is.name} · ${afterRanges[index]}${after ? ` · ${after[index]?.count ?? 0}` : ""}` : "—"}
                  </TableCell>
                  <TableCell className="text-sm text-muted-foreground">{is?.meaning ?? "—"}</TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
        <p className="text-xs text-muted-foreground">
          {impact && `Alumnos con resultado: ${impact.students}; cuenta la última toma de cada uno. `}
          {current.reason && `Motivo: «${current.reason}». `}
          {current.created_by_name && `Lo cambió ${current.created_by_name}`}
          {current.created_at && ` · ${formatShortDate(current.created_at)}`}
        </p>
        {error && <p className="text-sm text-destructive">{error}</p>}
      </div>
    </SectionCard>
  )
}

// ── Datos generales ─────────────────────────────────────────────────────────

const generalSchema = z
  .object({
    name: z.string().trim().min(1, "Ingresá un nombre").max(255, "Máximo 255 caracteres"),
    area: z.string().max(100, "Máximo 100 caracteres"),
    levels: z.array(z.enum(["primary", "lower_secondary", "upper_secondary"])),
    frequency: z.enum(["", "yearly", "twice_yearly", "every_few_years", "on_demand"]),
    frequency_years: z.string(),
    teacher_visible: z.boolean(),
  })
  // UX-only mirror of the backend rule; the server remains the boundary.
  .refine(
    (values) =>
      values.frequency !== "every_few_years" ||
      (Number.isInteger(Number(values.frequency_years)) &&
        Number(values.frequency_years) >= 2 &&
        Number(values.frequency_years) <= 10),
    { path: ["frequency_years"], message: "Entre 2 y 10 años" },
  )

type GeneralValues = z.infer<typeof generalSchema>

function GeneralDataForm({ type, onSaved }: { type: ScreeningTestType; onSaved: () => Promise<unknown> }) {
  const [formError, setFormError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)
  const {
    register,
    handleSubmit,
    watch,
    formState: { errors, isSubmitting },
  } = useForm<GeneralValues>({
    resolver: zodResolver(generalSchema),
    defaultValues: {
      name: type.name,
      area: type.area ?? "",
      levels: type.levels,
      frequency: type.frequency ?? "",
      frequency_years: type.frequency_years ? String(type.frequency_years) : "",
      teacher_visible: type.teacher_visible,
    },
  })
  const frequency = watch("frequency")

  async function onSubmit(values: GeneralValues) {
    setFormError(null)
    setSaved(false)
    try {
      await screeningApi.updateScreeningTestType(type.id, {
        name: values.name.trim(),
        area: values.area.trim() || null,
        levels: values.levels,
        frequency: values.frequency || null,
        frequency_years: values.frequency === "every_few_years" ? Number(values.frequency_years) : null,
        teacher_visible: values.teacher_visible,
      })
      setSaved(true)
      await onSaved()
    } catch {
      setFormError("No pudimos guardar los datos generales.")
    }
  }

  return (
    <SectionCard title="Datos generales">
      <form onSubmit={handleSubmit(onSubmit)} className="grid gap-4 sm:grid-cols-2" noValidate>
        <div className="grid gap-2">
          <Label htmlFor={`general-name-${type.id}`}>Nombre del screening</Label>
          <Input id={`general-name-${type.id}`} {...register("name")} />
          <p className="text-xs text-muted-foreground">Se puede cambiar sin perder la historia.</p>
          {errors.name && <p className="text-sm text-destructive">{errors.name.message}</p>}
        </div>
        <div className="grid gap-2">
          <Label htmlFor={`general-area-${type.id}`}>Área (texto libre)</Label>
          <Input id={`general-area-${type.id}`} {...register("area")} />
          {errors.area && <p className="text-sm text-destructive">{errors.area.message}</p>}
        </div>
        <fieldset className="grid gap-2">
          <legend className="text-sm font-medium">Población: a qué niveles se aplica</legend>
          {schoolLevels.map((level) => (
            <label key={level} className="flex items-center gap-2 text-sm">
              <input type="checkbox" value={level} {...register("levels")} />
              {schoolLevelLabels[level]}
            </label>
          ))}
        </fieldset>
        <div className="grid content-start gap-2">
          <Label htmlFor={`general-frequency-${type.id}`}>Cada cuánto se toma</Label>
          <select
            id={`general-frequency-${type.id}`}
            className="h-9 rounded-md border bg-background px-3 text-sm"
            {...register("frequency")}
          >
            <option value="">Sin definir</option>
            {screeningFrequencies.map((value) => (
              <option key={value} value={value}>
                {screeningFrequencyLabels[value]}
              </option>
            ))}
          </select>
          {frequency === "every_few_years" && (
            <div className="grid gap-2">
              <Label htmlFor={`general-years-${type.id}`}>Cada cuántos años</Label>
              <Input
                id={`general-years-${type.id}`}
                type="number"
                min={2}
                max={10}
                className="w-28"
                {...register("frequency_years")}
              />
              {errors.frequency_years && (
                <p className="text-sm text-destructive">{errors.frequency_years.message}</p>
              )}
            </div>
          )}
          <p className="text-xs text-muted-foreground">
            Se guardan todas las tomas con su fecha. Si no se tomó este año, se usa la última y se
            muestra de cuándo es.
          </p>
        </div>
        <div className="grid gap-1 sm:col-span-2">
          <label className="flex items-center gap-2 text-sm font-medium">
            <input type="checkbox" {...register("teacher_visible")} />
            Abrirlo al docente
          </label>
          <p className="text-xs text-muted-foreground">
            Psicopedagogía y Dirección lo ven siempre. Si lo abrís, el docente ve su grupo por franja y
            sin nombres, y cada alumno en su ficha; nunca el puntaje de otros alumnos.
          </p>
        </div>
        <div className="flex items-center gap-3 sm:col-span-2">
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? "Guardando…" : "Guardar datos generales"}
          </Button>
          {saved && <span className="text-sm text-muted-foreground">Guardado.</span>}
        </div>
        {formError && (
          <p role="alert" className="text-sm text-destructive sm:col-span-2">
            {formError}
          </p>
        )}
      </form>
    </SectionCard>
  )
}

// ── Franjas ─────────────────────────────────────────────────────────────────

const bandSchema = z.object({
  name: z.string().trim().min(1, "Ingresá un nombre").max(60, "Máximo 60 caracteres"),
  color: z.enum(["red", "orange", "yellow", "green", "blue"]),
  max: z
    .string()
    .refine((value) => value.trim() === "" || !Number.isNaN(Number(value)), "Número inválido"),
  meaning: z.string().trim().min(1, "Qué significa es obligatorio").max(1000, "Máximo 1000 caracteres"),
})

// UX-only mirror of the backend rules (2 to 5 bands, every band but the last
// needs its "hasta", strictly increasing); the server remains the boundary.
const bandsSchema = z
  .object({
    bands: z.array(bandSchema).min(MIN_BANDS).max(MAX_BANDS),
    reason: z.string().max(500, "Máximo 500 caracteres"),
  })
  .superRefine((values, ctx) => {
    let previous: number | null = null
    values.bands.forEach((band, index) => {
      const isLast = index === values.bands.length - 1
      if (band.max.trim() === "") {
        if (!isLast) {
          ctx.addIssue({ code: "custom", path: ["bands", index, "max"], message: "Indicá hasta qué puntaje llega" })
        }
        return
      }
      const max = Number(band.max)
      if (previous !== null && max <= previous) {
        ctx.addIssue({ code: "custom", path: ["bands", index, "max"], message: "Tiene que ser mayor que la franja anterior" })
      }
      previous = max
    })
  })

type BandsValues = z.infer<typeof bandsSchema>

function toFormBands(bands: ScreeningBand[] | undefined): BandsValues["bands"] {
  if (!bands || bands.length === 0) {
    return [
      { name: "", color: "red", max: "", meaning: "" },
      { name: "", color: "yellow", max: "", meaning: "" },
      { name: "", color: "green", max: "", meaning: "" },
    ]
  }
  return bands.map((band) => ({
    name: band.name,
    color: band.color,
    max: band.max === null ? "" : String(band.max),
    meaning: band.meaning ?? "",
  }))
}

function toApiBands(bands: BandsValues["bands"]): ScreeningBand[] {
  return bands.map((band) => ({
    name: band.name.trim(),
    color: band.color,
    max: band.max.trim() === "" ? null : Number(band.max),
    meaning: band.meaning.trim(),
  }))
}

function BandsForm({ type, onSaved }: { type: ScreeningTestType; onSaved: () => Promise<unknown> }) {
  const [formError, setFormError] = useState<string | null>(null)
  const [impact, setImpact] = useState<ScreeningImpact | null>(null)
  const [previewing, setPreviewing] = useState(false)
  const [saved, setSaved] = useState(false)
  const {
    register,
    control,
    handleSubmit,
    trigger,
    getValues,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<BandsValues>({
    resolver: zodResolver(bandsSchema),
    defaultValues: { bands: toFormBands(type.current_design?.bands), reason: "" },
  })
  const { fields, append, remove } = useFieldArray({ control, name: "bands" })

  // The editor stays mounted while the screening is open, so when the design
  // in force changes under it (Dirección reverted it, or it was saved again)
  // the form must start from the bands that rule now — otherwise it would keep
  // showing, and could re-save, the reverted bands.
  const currentDesignId = type.current_design?.id ?? null
  const currentBands = type.current_design?.bands
  const syncedDesignId = useRef(currentDesignId)
  useEffect(() => {
    if (syncedDesignId.current === currentDesignId) return
    syncedDesignId.current = currentDesignId
    reset({ bands: toFormBands(currentBands), reason: "" })
    setImpact(null)
  }, [currentDesignId, currentBands, reset])

  async function preview() {
    setFormError(null)
    if (!(await trigger())) return
    setPreviewing(true)
    try {
      setImpact(await screeningApi.previewScreeningTestImpact(type.id, toApiBands(getValues("bands"))))
    } catch {
      setFormError("No pudimos calcular cómo queda el grupo.")
    } finally {
      setPreviewing(false)
    }
  }

  async function onSubmit(values: BandsValues) {
    setFormError(null)
    setSaved(false)
    try {
      const design = await screeningApi.createScreeningTestDesign(type.id, {
        bands: toApiBands(values.bands),
        reason: values.reason.trim() || null,
      })
      reset({ bands: toFormBands(design.bands), reason: "" })
      setImpact(null)
      setSaved(true)
      await onSaved()
    } catch {
      setFormError("No pudimos guardar las franjas.")
    }
  }

  return (
    <SectionCard title="Franjas y qué significa cada una">
      <form onSubmit={handleSubmit(onSubmit)} className="grid gap-4" noValidate>
        <p className="text-sm text-muted-foreground">
          De 2 a 5 franjas, de la más baja a la más alta. Cada una tiene nombre, color, hasta qué
          puntaje llega y qué significa. «Qué significa» es obligatorio: sin ese texto no se puede
          guardar. La última franja puede quedar sin tope.
        </p>

        {fields.map((field, index) => {
          const bandErrors = errors.bands?.[index]
          const id = `band-${type.id}-${index}`
          return (
            <fieldset key={field.id} className="grid gap-3 rounded-md border p-3 sm:grid-cols-[1fr_10rem_8rem]">
              <legend className="px-1 text-sm font-medium">Franja {index + 1}</legend>
              <div className="grid gap-1">
                <Label htmlFor={`${id}-name`}>Nombre</Label>
                <Input id={`${id}-name`} placeholder="Ej: En proceso" {...register(`bands.${index}.name`)} />
                {bandErrors?.name && <p className="text-sm text-destructive">{bandErrors.name.message}</p>}
              </div>
              <div className="grid gap-1">
                <Label htmlFor={`${id}-color`}>Color</Label>
                <select
                  id={`${id}-color`}
                  className="h-9 rounded-md border bg-background px-3 text-sm"
                  {...register(`bands.${index}.color`)}
                >
                  {screeningColors.map((color) => (
                    <option key={color} value={color}>
                      {screeningColorLabels[color]}
                    </option>
                  ))}
                </select>
              </div>
              <div className="grid gap-1">
                <Label htmlFor={`${id}-max`}>Hasta</Label>
                <Input id={`${id}-max`} type="number" step="any" {...register(`bands.${index}.max`)} />
                {bandErrors?.max && <p className="text-sm text-destructive">{bandErrors.max.message}</p>}
              </div>
              <div className="grid gap-1 sm:col-span-3">
                <Label htmlFor={`${id}-meaning`}>Qué significa</Label>
                <Textarea id={`${id}-meaning`} {...register(`bands.${index}.meaning`)} />
                {bandErrors?.meaning && (
                  <p className="text-sm text-destructive">{bandErrors.meaning.message}</p>
                )}
              </div>
              {fields.length > MIN_BANDS && (
                <div className="sm:col-span-3">
                  <Button type="button" size="sm" variant="ghost" onClick={() => remove(index)}>
                    Quitar franja
                  </Button>
                </div>
              )}
            </fieldset>
          )
        })}

        {fields.length < MAX_BANDS && (
          <div>
            <Button
              type="button"
              size="sm"
              variant="outline"
              onClick={() => append({ name: "", color: "blue", max: "", meaning: "" })}
            >
              + Agregar franja
            </Button>
          </div>
        )}

        <div className="grid gap-2">
          <Label htmlFor={`reason-${type.id}`}>Motivo del cambio (opcional)</Label>
          <Textarea id={`reason-${type.id}`} {...register("reason")} />
          {errors.reason && <p className="text-sm text-destructive">{errors.reason.message}</p>}
        </div>

        <div>
          <Button type="button" variant="outline" onClick={preview} disabled={previewing}>
            {previewing ? "Calculando…" : "Ver cómo queda el grupo con este corte"}
          </Button>
        </div>
        {impact && <ImpactTable impact={impact} currentSince={type.current_design?.created_at ?? null} />}

        <div className="flex flex-wrap items-center gap-3">
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? "Guardando…" : "Guardar"}
          </Button>
          <span className="text-xs text-muted-foreground">
            Al guardar, el cambio rige y se le avisa a Dirección, que puede volverlo atrás.
          </span>
          {saved && <span className="text-sm text-muted-foreground">Guardado: ya rige.</span>}
        </div>
        {formError && (
          <p role="alert" className="text-sm text-destructive">
            {formError}
          </p>
        )}
      </form>
    </SectionCard>
  )
}

/**
 * Students per band with the design in force and with the proposed bands. The
 * "actual" column names the version it is computed with (decided 15/10:
 * comparisons and impact say which design version they use).
 */
function ImpactTable({ impact, currentSince }: { impact: ScreeningImpact; currentSince: string | null }) {
  const currentTitle = currentSince
    ? `Con el corte actual (vigente desde ${formatShortDate(currentSince)})`
    : "Con el corte actual"
  return (
    <div className="grid gap-2">
      <div className="grid gap-4 sm:grid-cols-2">
        <ImpactColumn title={currentTitle} bands={impact.current} />
        <ImpactColumn title="Con el corte nuevo" bands={impact.proposed} />
      </div>
      <p className="text-xs text-muted-foreground">
        Alumnos con resultado: {impact.students}; cuenta la última toma de cada uno. El corte no lo
        calcula Aula+: lo fija el colegio según cuántos casos puede seguir de cerca.
      </p>
    </div>
  )
}

function ImpactColumn({ title, bands }: { title: string; bands: ScreeningImpact["proposed"] | null }) {
  return (
    <div className="rounded-md border p-3 text-sm">
      <p className="mb-2 font-medium">{title}</p>
      {bands === null ? (
        <p className="text-muted-foreground">Todavía no hay franjas vigentes.</p>
      ) : (
        <ul className="grid gap-1">
          {bands.map((band, index) => (
            <li key={`${band.name}-${index}`} className="flex items-center justify-between gap-2">
              <span className="flex items-center gap-2">
                <span className={`inline-block h-3 w-3 rounded-full ${screeningColorDot[band.color]}`} aria-hidden />
                {band.name}
              </span>
              <span className="font-mono">{band.count}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
