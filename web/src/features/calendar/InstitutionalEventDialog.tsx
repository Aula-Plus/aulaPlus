import { zodResolver } from "@hookform/resolvers/zod"
import { useState } from "react"
import { useForm } from "react-hook-form"
import { z } from "zod"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import type { CalendarItem } from "@/types"
import { createInstitutionalEvent, updateInstitutionalEvent } from "./calendarApi"

// UX only: the server validates again (InstitutionalEventRequest).
const eventSchema = z.object({
  title: z.string().trim().min(1, "Escribí qué es.").max(200, "Hasta 200 caracteres."),
  date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, "Elegí la fecha."),
  time: z.string(),
  location: z.string().max(120, "Hasta 120 caracteres."),
  description: z.string().max(2000, "Hasta 2000 caracteres."),
})

type EventValues = z.infer<typeof eventSchema>

/**
 * Direction's institutional event (P21-DEC-05): what it is, a date and an
 * optional time — plus, optionally, where (the «Dónde» of the Inicio) and a
 * detail. Everyone in the school sees it. Create when `item` is absent, edit
 * otherwise.
 */
export function InstitutionalEventDialog({
  item,
  defaultDate,
  onClose,
  onSaved,
}: {
  item?: CalendarItem
  defaultDate: string
  onClose: () => void
  onSaved: () => void
}) {
  const [error, setError] = useState<string | null>(null)
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<EventValues>({
    resolver: zodResolver(eventSchema),
    defaultValues: {
      title: item?.title ?? "",
      date: item?.date ?? defaultDate,
      time: item?.time ?? "",
      location: item?.place ?? "",
      description: item?.detail ?? "",
    },
  })

  async function onSubmit(values: EventValues) {
    setError(null)
    const input = {
      title: values.title.trim(),
      date: values.date,
      time: values.time || null,
      location: values.location.trim() || null,
      description: values.description.trim() || null,
    }
    try {
      if (item) await updateInstitutionalEvent(item.id, input)
      else await createInstitutionalEvent(input)
      onSaved()
    } catch {
      setError("No pudimos guardar el evento.")
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{item ? "Editar evento institucional" : "Evento institucional"}</DialogTitle>
          <DialogDescription>Lo ven todos en el colegio, en su calendario y en su Inicio ese día.</DialogDescription>
        </DialogHeader>
        <form onSubmit={handleSubmit(onSubmit)} className="grid gap-4" noValidate>
          <div className="grid gap-2">
            <Label htmlFor="event-title">Qué</Label>
            <Input id="event-title" {...register("title")} />
            {errors.title && <p className="text-sm text-destructive">{errors.title.message}</p>}
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="event-date">Fecha</Label>
              <Input id="event-date" type="date" {...register("date")} />
              {errors.date && <p className="text-sm text-destructive">{errors.date.message}</p>}
            </div>
            <div className="grid gap-2">
              <Label htmlFor="event-time">Hora (opcional)</Label>
              <Input id="event-time" type="time" {...register("time")} />
            </div>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="event-location">Dónde (opcional)</Label>
            <Input id="event-location" {...register("location")} />
            {errors.location && <p className="text-sm text-destructive">{errors.location.message}</p>}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="event-description">Detalle (opcional)</Label>
            <Textarea id="event-description" rows={3} {...register("description")} />
            {errors.description && <p className="text-sm text-destructive">{errors.description.message}</p>}
          </div>
          {error && <p className="text-sm text-destructive">{error}</p>}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              Cancelar
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? "Guardando…" : "Guardar"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
