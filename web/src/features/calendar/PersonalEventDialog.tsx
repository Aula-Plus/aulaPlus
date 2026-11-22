import { zodResolver } from "@hookform/resolvers/zod"
import { isAxiosError } from "axios"
import { useEffect, useMemo, useState } from "react"
import { useForm } from "react-hook-form"
import { Link } from "react-router-dom"
import { z } from "zod"
import { Button } from "@/components/ui/button"
import { ConfirmDialog } from "@/components/ui/confirm-dialog"
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
import { MultiSelect } from "@/components/ui/multi-select"
import { SingleSelect } from "@/components/ui/single-select"
import { Textarea } from "@/components/ui/textarea"
import { roleLabels, type CalendarItem, type PersonalEventOptions } from "@/types"
import { weekdayDayMonth } from "./calendarDates"
import { calendarErrorMessage } from "./calendarErrors"
import { calendarItemHour, calendarItemTypeLabel } from "./calendarItemText"
import {
  createPersonalEvent,
  deletePersonalEvent,
  fetchPersonalEventOptions,
  leaveCalendarEvent,
  updatePersonalEvent,
} from "./personalEventsApi"

// UX only: the server validates again (PersonalEventRequest).
const eventSchema = z.object({
  title: z.string().trim().min(1, "Escribí qué es.").max(200, "Hasta 200 caracteres."),
  date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, "Elegí la fecha."),
  time: z.string(),
  location: z.string().max(120, "Hasta 120 caracteres."),
  description: z.string().max(2000, "Hasta 2000 caracteres."),
})

type EventValues = z.infer<typeof eventSchema>

const NO_STUDENT = ""

/**
 * An event of the caller's own calendar (ClickUp 1dfd3mc56kp, P21-DEC-108):
 * what, a date, an optional time, place and detail; who else takes part (any
 * active colleague of the school — it reaches them directly, with a notice)
 * and, optionally, a student the caller sees. Create when `item` is absent,
 * edit (creator only) otherwise.
 */
export function PersonalEventDialog({
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
  const [options, setOptions] = useState<PersonalEventOptions | null>(null)
  const [invitees, setInvitees] = useState<number[]>(
    () => item?.shared?.participants.filter((p) => p.id !== item.shared?.owner?.id).map((p) => p.id) ?? [],
  )
  const [studentId, setStudentId] = useState<string>(() =>
    item?.shared?.student ? String(item.shared.student.id) : NO_STUDENT,
  )
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

  useEffect(() => {
    let active = true
    fetchPersonalEventOptions()
      .then((result) => {
        if (active) setOptions(result)
      })
      .catch(() => {
        if (active) setError("No pudimos cargar a quiénes podés invitar.")
      })
    return () => {
      active = false
    }
  }, [])

  const userOptions = useMemo(
    () =>
      (options?.users ?? []).map((user) => ({
        id: user.id,
        label: user.role ? `${user.name} · ${roleLabels[user.role]}` : user.name,
      })),
    [options],
  )

  const studentOptions = useMemo(() => {
    const list = (options?.students ?? []).map((student) => ({ value: String(student.id), label: student.name }))
    // Keep the event's current student selectable even if the creator no
    // longer sees it (the server keeps it unless it changes).
    const current = item?.shared?.student
    if (current && !list.some((option) => option.value === String(current.id))) {
      list.unshift({ value: String(current.id), label: current.name })
    }
    return [{ value: NO_STUDENT, label: "Sin alumno" }, ...list]
  }, [options, item])

  async function onSubmit(values: EventValues) {
    setError(null)
    const input = {
      title: values.title.trim(),
      date: values.date,
      time: values.time || null,
      location: values.location.trim() || null,
      description: values.description.trim() || null,
      invitee_ids: invitees,
      student_id: studentId === NO_STUDENT ? null : Number(studentId),
    }
    try {
      if (item) await updatePersonalEvent(item.id, input)
      else await createPersonalEvent(input)
      onSaved()
    } catch (err) {
      setError(personalEventErrorMessage(err))
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{item ? "Editar evento personal" : "Evento personal"}</DialogTitle>
          <DialogDescription>
            Se guarda en tu calendario. Si invitás a alguien, le aparece directo en el suyo y le llega un aviso.
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={handleSubmit(onSubmit)} className="grid gap-4" noValidate>
          <div className="grid gap-2">
            <Label htmlFor="personal-title">Qué</Label>
            <Input id="personal-title" {...register("title")} />
            {errors.title && <p className="text-sm text-destructive">{errors.title.message}</p>}
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="personal-date">Fecha</Label>
              <Input id="personal-date" type="date" {...register("date")} />
              {errors.date && <p className="text-sm text-destructive">{errors.date.message}</p>}
            </div>
            <div className="grid gap-2">
              <Label htmlFor="personal-time">Hora (opcional)</Label>
              <Input id="personal-time" type="time" {...register("time")} />
            </div>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="personal-location">Dónde (opcional)</Label>
            <Input id="personal-location" {...register("location")} />
            {errors.location && <p className="text-sm text-destructive">{errors.location.message}</p>}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="personal-description">Detalle (opcional)</Label>
            <Textarea id="personal-description" rows={3} {...register("description")} />
            {errors.description && <p className="text-sm text-destructive">{errors.description.message}</p>}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="personal-invitees">Invitar (opcional)</Label>
            <MultiSelect
              id="personal-invitees"
              options={userOptions}
              selected={invitees}
              onChange={setInvitees}
              placeholder="Nadie más"
            />
            <p className="text-xs text-muted-foreground">
              Los invitados ven todo el evento y quiénes más están. Solo vos lo editás o lo borrás.
            </p>
          </div>
          <div className="grid gap-2">
            <Label htmlFor="personal-student">Alumno (opcional)</Label>
            <SingleSelect
              id="personal-student"
              options={studentOptions}
              value={studentId}
              onChange={setStudentId}
              placeholder="Sin alumno"
              searchPlaceholder="Buscar alumno…"
            />
            <p className="text-xs text-muted-foreground">
              Los invitados ven el nombre; la ficha la abre solo quien ya podía verla.
            </p>
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

function personalEventErrorMessage(error: unknown): string {
  const fields = validationFields(error)
  if (fields.some((field) => field.startsWith("invitee_ids"))) {
    return "Alguna de las personas invitadas ya no está activa en el colegio."
  }
  if (fields.includes("student_id")) return "Ese alumno no está entre los que ves."
  return calendarErrorMessage(error, "No pudimos guardar el evento.")
}

function validationFields(error: unknown): string[] {
  if (!isAxiosError(error) || error.response?.status !== 422) return []
  return Object.keys((error.response.data as { errors?: Record<string, unknown> })?.errors ?? {})
}

/**
 * The whole of a shared event, as every participant sees it: what, when,
 * where, the detail, who takes part and the student it names — with the link
 * to the file only when the server says the viewer can open it. The creator
 * edits or deletes it; an invitee removes it from their own calendar.
 */
export function SharedEventDialog({
  item,
  onClose,
  onEdit,
  onChanged,
}: {
  item: CalendarItem
  onClose: () => void
  onEdit: () => void
  onChanged: () => void
}) {
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const shared = item.shared
  const hour = calendarItemHour(item)

  async function run(action: () => Promise<void>, failure: string) {
    setBusy(true)
    setError(null)
    try {
      await action()
      onChanged()
    } catch {
      setError(failure)
      setBusy(false)
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{item.title}</DialogTitle>
          <DialogDescription>
            {calendarItemTypeLabel(item)} · {weekdayDayMonth(item.date)}
            {hour && ` · ${hour}`}
          </DialogDescription>
        </DialogHeader>
        <dl className="grid gap-3 text-sm">
          {item.place && (
            <div>
              <dt className="font-medium">Dónde</dt>
              <dd>{item.place}</dd>
            </div>
          )}
          {item.detail && (
            <div>
              <dt className="font-medium">Detalle</dt>
              <dd className="whitespace-pre-line">{item.detail}</dd>
            </div>
          )}
          {shared && (
            <div>
              <dt className="font-medium">Quiénes están</dt>
              <dd>
                <ul className="list-inside list-disc">
                  {shared.participants.map((participant) => (
                    <li key={participant.id}>
                      {participant.name}
                      {participant.id === shared.owner?.id && " (lo creó)"}
                    </li>
                  ))}
                </ul>
              </dd>
            </div>
          )}
          {shared?.student && (
            <div>
              <dt className="font-medium">Alumno</dt>
              <dd>
                {shared.student.name}
                {shared.student.can_view && (
                  <>
                    {" · "}
                    <Link
                      className="text-primary underline-offset-4 hover:underline"
                      to={`/alumnos/${shared.student.id}/seguimiento`}
                    >
                      Ver ficha
                    </Link>
                  </>
                )}
              </dd>
            </div>
          )}
        </dl>
        {error && <p className="text-sm text-destructive">{error}</p>}
        <DialogFooter>
          {item.can_edit && shared?.kind === "personal" && (
            <>
              <ConfirmDialog
                trigger={
                  <Button type="button" variant="outline" disabled={busy}>
                    Eliminar
                  </Button>
                }
                title="Eliminar el evento"
                description={`«${item.title ?? ""}» deja de aparecer en el calendario de todos los que están.`}
                confirmLabel="Eliminar"
                onConfirm={() => void run(() => deletePersonalEvent(item.id), "No pudimos eliminar el evento.")}
              />
              <Button type="button" onClick={onEdit} disabled={busy}>
                Editar
              </Button>
            </>
          )}
          {shared?.can_leave && (
            <ConfirmDialog
              trigger={
                <Button type="button" variant="outline" disabled={busy}>
                  Quitar de mi calendario
                </Button>
              }
              title="Quitar de tu calendario"
              description={`«${item.title ?? ""}» deja de aparecer en tu calendario. Sigue en el de los demás.`}
              confirmLabel="Quitar"
              onConfirm={() => void run(() => leaveCalendarEvent(item.id), "No pudimos quitar el evento.")}
            />
          )}
          <Button type="button" variant="ghost" onClick={onClose}>
            Cerrar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
