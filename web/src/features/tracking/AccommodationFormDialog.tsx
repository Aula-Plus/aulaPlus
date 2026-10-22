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
  type StaffMember,
  type Subject,
} from "@/types"
import { fetchFollowUpCandidates } from "./scheduledFollowUpsApi"
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
  type: z.string().min(1, "Ingresá el nombre del ajuste"),
  description: z.string().min(1, "Ingresá una descripción"),
  focus_area: z.string().min(1, "Ingresá un área de enfoque"),
  // Free string in the form so the empty "elegí…" option is a valid default;
  // the refine rejects it, which is what forces a category to be chosen (§6).
  category: z
    .string()
    .refine(
      (value) => (CATEGORY_VALUES as readonly string[]).includes(value),
      "Elegí el tipo de ajuste",
    ),
  active: z.boolean(),
  starts_on: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, "Elegí desde cuándo rige"),
  // Empty = a general accommodation (every subject).
  subject_ids: z.array(z.number()),
  // Empty = every assessment type.
  assessment_types: z.array(z.enum(ASSESSMENT_TYPE_VALUES)),
  // «Origen»: psychopedagogy only; never sent by direction.
  origin: z.string().max(255, "Usá hasta 255 caracteres"),
  // Review routing of a new direction proposal (ClickUp 86e3qkwkk): only
  // validated by `proposalSchema`, ignored otherwise.
  review_due_date: z.string(),
  review_recipients: z.enum(["role", "people"]),
  reviewer_ids: z.array(z.number()),
})

/** A new direction proposal also needs its review deadline and reviewers. */
const proposalSchema = accommodationSchema.superRefine((values, ctx) => {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(values.review_due_date)) {
    ctx.addIssue({ code: "custom", path: ["review_due_date"], message: "Elegí hasta cuándo tiene para revisarla" })
  } else if (values.review_due_date < todayIso()) {
    ctx.addIssue({ code: "custom", path: ["review_due_date"], message: "El plazo no puede ser anterior a hoy" })
  }
  if (values.review_recipients === "people" && values.reviewer_ids.length === 0) {
    ctx.addIssue({ code: "custom", path: ["reviewer_ids"], message: "Elegí al menos una persona" })
  }
})

type AccommodationValues = z.infer<typeof accommodationSchema>

/** Prefill of «Origen» for what psychopedagogy registers itself. */
const DIRECT_ORIGIN = "Registrado por Psicopedagogía"

interface AccommodationFormDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  studentId: number
  /** When set, the dialog edits this accommodation; otherwise it creates one. */
  accommodation?: Accommodation | null
  /** Called after a successful save so the parent can refetch the tracking. */
  onSaved: () => void
  /**
   * Direction proposes instead of creating (ClickUp 86e3qkwkk): the form says
   * it goes to psychopedagogy's review, and never offers switching it on/off.
   * UX only — the server decides from the caller's role.
   */
  proposal?: boolean
}

/**
 * Create/edit dialog for an Accommodation (docs/prompts/24 §4) — the first
 * accommodation form in the SPA, filling the gap the Sesión 8 backend opened.
 * Same modal pattern as StudentFormPage/GroupFormPage (Dialog + react-hook-form
 * + Zod). `category` is required; `active` is only offered when editing (a brand
 * new accommodation is never created already-inactive).
 *
 * Scope (ClickUp 86e3dpbwy follow-up): «Vigente desde» (defaults to today,
 * editable; there is no end-date field — the server records the day it is
 * switched off, PO ClickUp 86e3d1bwa),
 * the subjects it is restricted to (none = every subject — the server then
 * shows it to every teacher of the student; otherwise only to the teachers of
 * those subjects) and the assessment types (none = every type).
 *
 * Field names (ClickUp 86e3qkwkk): «Nombre del ajuste» (`type`), «Tipo de
 * ajuste» (`category`: Acceso/Contenido/Criterio), «Materias donde aplica».
 * «Requiere aprobación externa» was removed from the form and the API.
 * «Origen» is psychopedagogy's (prefilled «Registrado por Psicopedagogía» on a
 * new one); a new direction proposal asks for the review deadline and whether
 * it goes to the whole psychopedagogy role or to specific people.
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
  proposal = false,
}: AccommodationFormDialogProps) {
  const isEdit = Boolean(accommodation)
  // A proposal under review is always active; only a confirmed (or directly
  // created) accommodation can be switched off, and only by psychopedagogy.
  const isOpenProposal =
    accommodation?.review_status === "pending" || accommodation?.review_status === "returned"
  const offersActive = isEdit && !proposal && !isOpenProposal
  const asksReview = proposal && !isEdit
  const [formError, setFormError] = useState<string | null>(null)
  const [subjects, setSubjects] = useState<Subject[]>([])
  const [reviewers, setReviewers] = useState<StaffMember[]>([])

  const {
    register,
    control,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
    watch,
  } = useForm<AccommodationValues>({
    resolver: zodResolver(asksReview ? proposalSchema : accommodationSchema),
    defaultValues: {
      type: "",
      description: "",
      focus_area: "",
      category: "",
      active: true,
      starts_on: todayIso(),
      subject_ids: [],
      assessment_types: [],
      origin: "",
      review_due_date: "",
      review_recipients: "role",
      reviewer_ids: [],
    },
  })
  const reviewRecipients = watch("review_recipients")

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

  useEffect(() => {
    if (!open || !asksReview) return
    fetchFollowUpCandidates(studentId, "psychopedagogue")
      .then(setReviewers)
      .catch(() => setReviewers([]))
  }, [open, asksReview, studentId])

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
      active: accommodation?.active ?? true,
      starts_on: accommodation?.starts_on ?? todayIso(),
      subject_ids: accommodation?.subjects.map((subject) => subject.id) ?? [],
      assessment_types: accommodation?.assessment_types ?? [],
      origin: accommodation ? (accommodation.origin ?? "") : DIRECT_ORIGIN,
      review_due_date: "",
      review_recipients: "role",
      reviewer_ids: [],
    })
  }, [open, accommodation, reset])

  async function onSubmit(values: AccommodationValues) {
    setFormError(null)
    const input = {
      type: values.type,
      description: values.description,
      focus_area: values.focus_area,
      category: values.category as AccommodationCategory,
      // On create the checkbox isn't shown; a new accommodation is always
      // active. Direction never sends it (the server rejects it on edit).
      ...(offersActive ? { active: values.active } : isEdit ? {} : { active: true }),
      ...(proposal ? {} : { starts_on: values.starts_on }),
      subject_ids: values.subject_ids,
      assessment_types: values.assessment_types,
      ...(proposal ? {} : { origin: values.origin.trim() || null }),
      ...(asksReview
        ? {
            review_due_date: values.review_due_date,
            review_recipients: values.review_recipients,
            ...(values.review_recipients === "people" ? { reviewer_ids: values.reviewer_ids } : {}),
          }
        : {}),
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
      setFormError(proposal ? "No pudimos enviar la propuesta." : "No pudimos guardar el ajuste.")
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>
            {proposal
              ? isEdit
                ? "Editar propuesta de ajuste"
                : "Proponer ajuste"
              : isEdit
                ? "Editar ajuste"
                : "Nuevo ajuste"}
          </DialogTitle>
        </DialogHeader>
        {proposal && (
          <p className="text-sm text-muted-foreground">
            La propuesta se envía a revisión de Psicopedagogía. No llega al docente ni cuenta como
            ajuste vigente hasta que Psicopedagogía la confirme.
          </p>
        )}
        <form onSubmit={handleSubmit(onSubmit)} className="grid gap-4" noValidate>
          <div className="grid gap-2">
            <Label htmlFor="accommodation-type">Nombre del ajuste</Label>
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
            <Label htmlFor="accommodation-category">Tipo de ajuste</Label>
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
                  placeholder="Elegí el tipo de ajuste…"
                />
              )}
            />
            {errors.category && (
              <p className="text-sm text-destructive">{errors.category.message}</p>
            )}
          </div>
          {/* «Vigente desde» is set by psychopedagogy when it confirms a proposal. */}
          {!proposal && (
          <div className="grid gap-2">
            <Label htmlFor="accommodation-starts-on">Vigente desde</Label>
            <Input id="accommodation-starts-on" type="date" {...register("starts_on")} />
            {errors.starts_on && (
              <p className="text-sm text-destructive">{errors.starts_on.message}</p>
            )}
            <p className="text-xs text-muted-foreground">
              Se completa con el día en que se activa y podés cambiarla. No lleva fecha de
              vencimiento: rige hasta que alguien lo desactive.
            </p>
          </div>
          )}
          <div className="grid gap-2">
            <Label htmlFor="accommodation-subjects">Materias donde aplica</Label>
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
          {!proposal && (
            <div className="grid gap-2">
              <Label htmlFor="accommodation-origin">Origen</Label>
              <Input id="accommodation-origin" {...register("origin")} />
              {errors.origin && (
                <p className="text-sm text-destructive">{errors.origin.message}</p>
              )}
              <p className="text-xs text-muted-foreground">
                De dónde salió el ajuste. Lo ven Psicopedagogía y Dirección, nunca el docente.
              </p>
            </div>
          )}
          {asksReview && (
            <>
              <div className="grid gap-2">
                <Label htmlFor="accommodation-review-due">Plazo para la revisión</Label>
                <Input
                  id="accommodation-review-due"
                  type="date"
                  min={todayIso()}
                  {...register("review_due_date")}
                />
                {errors.review_due_date && (
                  <p className="text-sm text-destructive">{errors.review_due_date.message}</p>
                )}
              </div>
              <fieldset className="grid gap-2">
                <legend className="text-sm font-medium">Enviar a</legend>
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="radio"
                    className="size-4"
                    value="role"
                    {...register("review_recipients")}
                  />
                  Psicopedagogía (todo el rol)
                </label>
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="radio"
                    className="size-4"
                    value="people"
                    {...register("review_recipients")}
                  />
                  Personas de Psicopedagogía
                </label>
                {reviewRecipients === "people" && (
                  <Controller
                    name="reviewer_ids"
                    control={control}
                    render={({ field }) => (
                      <div className="grid gap-1 pl-6">
                        {reviewers.length === 0 ? (
                          <p className="text-xs text-muted-foreground">
                            No hay personas de Psicopedagogía para elegir.
                          </p>
                        ) : (
                          reviewers.map((reviewer) => (
                            <label key={reviewer.id} className="flex items-center gap-2 text-sm">
                              <input
                                type="checkbox"
                                className="size-4"
                                checked={field.value.includes(reviewer.id)}
                                onChange={(event) =>
                                  field.onChange(
                                    event.target.checked
                                      ? [...field.value, reviewer.id]
                                      : field.value.filter((id) => id !== reviewer.id),
                                  )
                                }
                              />
                              {reviewer.name}
                            </label>
                          ))
                        )}
                      </div>
                    )}
                  />
                )}
                {errors.reviewer_ids && (
                  <p className="text-sm text-destructive">{errors.reviewer_ids.message}</p>
                )}
              </fieldset>
            </>
          )}
          {offersActive && (
            <div className="grid gap-1">
              <div className="flex items-center gap-2">
                <input
                  id="accommodation-active"
                  type="checkbox"
                  className="size-4"
                  {...register("active")}
                />
                <Label htmlFor="accommodation-active">Activa</Label>
              </div>
              <p className="text-xs text-muted-foreground">
                Al desactivarla, el sistema registra el día de hoy como fecha de baja.
              </p>
            </div>
          )}
          {formError && (
            <p role="alert" className="text-sm text-destructive">
              {formError}
            </p>
          )}
          <div className="flex gap-2">
            <Button type="submit" disabled={isSubmitting}>
              {proposal
                ? isSubmitting
                  ? "Enviando…"
                  : isEdit
                    ? "Guardar cambios de la propuesta"
                    : "Enviar a revisión de Psicopedagogía"
                : isSubmitting
                  ? "Guardando…"
                  : "Guardar"}
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
