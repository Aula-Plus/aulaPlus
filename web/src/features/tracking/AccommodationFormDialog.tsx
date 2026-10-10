import { useEffect, useMemo, useState } from "react"
import { Controller, useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { MultiSelect } from "@/components/ui/multi-select"
import { SingleSelect } from "@/components/ui/single-select"
import { Textarea } from "@/components/ui/textarea"
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { fetchSubjects } from "@/features/subjects/subjectsApi"
import {
  accommodationCategoryLabels,
  assessmentTypeLabels,
  type Accommodation,
  type AccommodationCategory,
  type AssessmentType,
  type Subject,
} from "@/types"
import * as trackingApi from "./trackingApi"

const CATEGORY_VALUES: readonly AccommodationCategory[] = ["access", "content", "criteria"]
const ASSESSMENT_TYPE_VALUES = [
  "written",
  "assignment",
  "project",
  "oral",
  "submission",
] as const satisfies readonly AssessmentType[]

/** Today as a local `YYYY-MM-DD` (not UTC: late evening in UTC-3 is already tomorrow in UTC). */
function todayIso(): string {
  const now = new Date()
  const pad = (value: number) => String(value).padStart(2, "0")
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}

const accommodationSchema = z.object({
  type: z.string().min(1, "Ingresá un tipo"),
  description: z.string().min(1, "Ingresá una descripción"),
  focus_area: z.string().min(1, "Ingresá un área de enfoque"),
  // Free string in the form so the empty "elegí…" option is a valid default;
  // the refine rejects it, which is what forces a category to be chosen (§6).
  category: z
    .string()
    .refine(
      (value) => (CATEGORY_VALUES as readonly string[]).includes(value),
      "Elegí una categoría",
    ),
  requires_external_approval: z.boolean(),
  active: z.boolean(),
  starts_on: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, "Elegí desde cuándo rige"),
  // Empty = a general accommodation (every subject).
  subject_ids: z.array(z.number()),
  // Empty = every assessment type.
  assessment_types: z.array(z.enum(ASSESSMENT_TYPE_VALUES)),
})

type AccommodationValues = z.infer<typeof accommodationSchema>

interface AccommodationFormDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  studentId: number
  /** When set, the dialog edits this accommodation; otherwise it creates one. */
  accommodation?: Accommodation | null
  /** Called after a successful save so the parent can refetch the tracking. */
  onSaved: () => void
}

/**
 * Create/edit dialog for an Accommodation (docs/prompts/24 §4) — the first
 * accommodation form in the SPA, filling the gap the Sesión 8 backend opened.
 * Same modal pattern as StudentFormPage/GroupFormPage (Dialog + react-hook-form
 * + Zod). `category` is required; `active` is only offered when editing (a brand
 * new accommodation is never created already-inactive).
 *
 * Scope (ClickUp 86e3dpbwy follow-up): «Vigente desde» (defaults to today),
 * the subjects it is restricted to (none = every subject — the server then
 * shows it to every teacher of the student; otherwise only to the teachers of
 * those subjects) and the assessment types (none = every type).
 *
 * On save it calls `onSaved()` and closes — the parent refetches the whole
 * tracking aggregate, which is enough here: unlike the approve/reject of
 * Sesión 9, this writes a field that no other path changes, so it does not race
 * the ~60s server cache.
 */
export function AccommodationFormDialog({
  open,
  onOpenChange,
  studentId,
  accommodation,
  onSaved,
}: AccommodationFormDialogProps) {
  const isEdit = Boolean(accommodation)
  const [formError, setFormError] = useState<string | null>(null)
  const [subjects, setSubjects] = useState<Subject[]>([])

  const {
    register,
    control,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<AccommodationValues>({
    resolver: zodResolver(accommodationSchema),
    defaultValues: {
      type: "",
      description: "",
      focus_area: "",
      category: "",
      requires_external_approval: false,
      active: true,
      starts_on: todayIso(),
      subject_ids: [],
      assessment_types: [],
    },
  })

  // The live catalog, plus the subjects already attached to the edited
  // accommodation that it no longer lists — a soft-deleted one shows as
  // «(eliminada)». Without them MultiSelect would hide those ids and read
  // «Todas las materias» while the accommodation is actually scoped, with no
  // way to remove them.
  const subjectOptions = useMemo(() => {
    const attached = accommodation?.subjects ?? []
    const isDeletedAttachment = (id: number) =>
      attached.some((subject) => subject.id === id && subject.deleted)
    return [
      ...subjects
        .filter((subject) => !isDeletedAttachment(subject.id))
        .map((subject) => ({ id: subject.id, label: subject.name })),
      ...attached
        .filter(
          (subject) => subject.deleted || !subjects.some((live) => live.id === subject.id),
        )
        .map((subject) => ({
          id: subject.id,
          label: subject.deleted ? `${subject.name} (eliminada)` : subject.name,
        })),
    ]
  }, [subjects, accommodation])

  useEffect(() => {
    if (!open) return
    fetchSubjects()
      .then(setSubjects)
      .catch(() => setSubjects([]))
  }, [open])

  // Reset the fields whenever the dialog (re)opens: to the edited row's values,
  // or back to blanks for a new accommodation.
  useEffect(() => {
    if (!open) return
    setFormError(null)
    reset({
      type: accommodation?.type ?? "",
      description: accommodation?.description ?? "",
      focus_area: accommodation?.focus_area ?? "",
      category: accommodation?.category ?? "",
      requires_external_approval: accommodation?.requires_external_approval ?? false,
      active: accommodation?.active ?? true,
      starts_on: accommodation?.starts_on ?? todayIso(),
      subject_ids: accommodation?.subjects.map((subject) => subject.id) ?? [],
      assessment_types: accommodation?.assessment_types ?? [],
    })
  }, [open, accommodation, reset])

  async function onSubmit(values: AccommodationValues) {
    setFormError(null)
    const input = {
      type: values.type,
      description: values.description,
      focus_area: values.focus_area,
      category: values.category as AccommodationCategory,
      requires_external_approval: values.requires_external_approval,
      // On create the checkbox isn't shown; a new accommodation is always active.
      active: isEdit ? values.active : true,
      starts_on: values.starts_on,
      subject_ids: values.subject_ids,
      assessment_types: values.assessment_types,
    }
    try {
      if (accommodation) {
        await trackingApi.updateAccommodation(accommodation.id, input)
      } else {
        await trackingApi.createAccommodation(studentId, input)
      }
      onSaved()
      onOpenChange(false)
    } catch {
      setFormError("No pudimos guardar la adaptación.")
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>{isEdit ? "Editar adaptación" : "Nueva adaptación"}</DialogTitle>
        </DialogHeader>
        <form onSubmit={handleSubmit(onSubmit)} className="grid gap-4" noValidate>
          <div className="grid gap-2">
            <Label htmlFor="accommodation-type">Tipo</Label>
            <Input id="accommodation-type" {...register("type")} />
            {errors.type && <p className="text-sm text-destructive">{errors.type.message}</p>}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="accommodation-description">Descripción</Label>
            <Textarea id="accommodation-description" {...register("description")} />
            {errors.description && (
              <p className="text-sm text-destructive">{errors.description.message}</p>
            )}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="accommodation-focus-area">Área de enfoque</Label>
            <Input id="accommodation-focus-area" {...register("focus_area")} />
            {errors.focus_area && (
              <p className="text-sm text-destructive">{errors.focus_area.message}</p>
            )}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="accommodation-category">Categoría</Label>
            <Controller
              name="category"
              control={control}
              render={({ field }) => (
                <SingleSelect
                  id="accommodation-category"
                  options={CATEGORY_VALUES.map((category) => ({
                    value: category,
                    label: accommodationCategoryLabels[category],
                  }))}
                  value={field.value ?? ""}
                  onChange={field.onChange}
                  placeholder="Elegí una categoría…"
                />
              )}
            />
            {errors.category && (
              <p className="text-sm text-destructive">{errors.category.message}</p>
            )}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="accommodation-starts-on">Vigente desde</Label>
            <Input id="accommodation-starts-on" type="date" {...register("starts_on")} />
            {errors.starts_on && (
              <p className="text-sm text-destructive">{errors.starts_on.message}</p>
            )}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="accommodation-subjects">Materias</Label>
            <Controller
              name="subject_ids"
              control={control}
              render={({ field }) => (
                <MultiSelect
                  id="accommodation-subjects"
                  options={subjectOptions}
                  selected={field.value}
                  onChange={field.onChange}
                  placeholder="Todas las materias"
                />
              )}
            />
            <p className="text-xs text-muted-foreground">
              Sin materias elegidas, aplica en todas. Si elegís materias, solo lo ven los docentes
              que le dan esas materias al alumno.
            </p>
          </div>
          <fieldset className="grid gap-2">
            <legend className="text-sm font-medium">Evaluaciones</legend>
            <Controller
              name="assessment_types"
              control={control}
              render={({ field }) => (
                <div className="flex flex-wrap gap-x-4 gap-y-2">
                  {ASSESSMENT_TYPE_VALUES.map((type) => (
                    <label key={type} className="flex items-center gap-2 text-sm">
                      <input
                        type="checkbox"
                        className="size-4"
                        checked={field.value.includes(type)}
                        onChange={(event) =>
                          field.onChange(
                            event.target.checked
                              ? [...field.value, type]
                              : field.value.filter((value) => value !== type),
                          )
                        }
                      />
                      {assessmentTypeLabels[type]}
                    </label>
                  ))}
                </div>
              )}
            />
            <p className="text-xs text-muted-foreground">
              Sin tipos marcados, aplica en todas las evaluaciones.
            </p>
          </fieldset>
          <div className="flex items-center gap-2">
            <input
              id="accommodation-requires-approval"
              type="checkbox"
              className="size-4"
              {...register("requires_external_approval")}
            />
            <Label htmlFor="accommodation-requires-approval">Requiere aprobación externa</Label>
          </div>
          {isEdit && (
            <div className="flex items-center gap-2">
              <input
                id="accommodation-active"
                type="checkbox"
                className="size-4"
                {...register("active")}
              />
              <Label htmlFor="accommodation-active">Activa</Label>
            </div>
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
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancelar
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
