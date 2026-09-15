import { useEffect, useState } from "react"
import { Controller, useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { SingleSelect } from "@/components/ui/single-select"
import { Textarea } from "@/components/ui/textarea"
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import {
  accommodationCategoryLabels,
  type Accommodation,
  type AccommodationCategory,
} from "@/types"
import * as trackingApi from "./trackingApi"

const CATEGORY_VALUES: readonly AccommodationCategory[] = ["access", "content", "criteria"]

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
    },
  })

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
