import { useEffect, useState, type FormEvent } from "react"
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
import type { CalendarItem } from "@/types"
import { fetchSuggestedSlot, updateClassSchedule } from "./calendarApi"
import { weekdayDayMonth } from "./calendarDates"

/**
 * Time and room of a class (ClickUp 1dfd3mc4bgh): data of the class, both
 * optional and editable at any moment. When the class has no time yet, they
 * come pre-filled from the teacher's own «Mis horarios» for that weekday —
 * a proposal the teacher can change right here, never a generated date.
 */
export function ClassScheduleDialog({
  item,
  onClose,
  onSaved,
}: {
  item: CalendarItem
  onClose: () => void
  onSaved: () => void
}) {
  const [time, setTime] = useState(item.time ?? "")
  const [room, setRoom] = useState(item.place ?? "")
  const [fromTimetable, setFromTimetable] = useState(false)
  const [noSlot, setNoSlot] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function suggest(onlyIfEmpty: boolean) {
    if (!item.group || !item.subject) return
    try {
      const slot = await fetchSuggestedSlot({
        group_id: item.group.id,
        subject_id: item.subject.id,
        date: item.date,
        class_session_id: item.id,
      })
      if (!slot) {
        if (!onlyIfEmpty) setNoSlot(true)
        return
      }
      setTime(slot.start_time)
      setRoom((current) => (onlyIfEmpty && current ? current : (slot.room ?? "")))
      setFromTimetable(true)
      setNoSlot(false)
    } catch {
      if (!onlyIfEmpty) setError("No pudimos leer tus horarios.")
    }
  }

  useEffect(() => {
    // Pre-fill only a class without a time; never overwrite what is set.
    if (!item.time) void suggest(true)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [item.key])

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setSaving(true)
    setError(null)
    try {
      await updateClassSchedule(item.id, { start_time: time || null, room: room.trim() || null })
      onSaved()
    } catch {
      setError("No pudimos guardar la hora y el salón.")
    } finally {
      setSaving(false)
    }
  }

  const subjectAndGroup = [item.subject?.name, item.group?.name].filter(Boolean).join(" · ")

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Hora y salón de la clase</DialogTitle>
          <DialogDescription>
            {weekdayDayMonth(item.date)}
            {subjectAndGroup && ` · ${subjectAndGroup}`}
            {item.detail && ` — ${item.detail}`}
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={onSubmit} className="grid gap-4" noValidate>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label htmlFor="class-time">Hora (opcional)</Label>
              <Input
                id="class-time"
                type="time"
                value={time}
                onChange={(event) => {
                  setTime(event.target.value)
                  setFromTimetable(false)
                }}
              />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="class-room">Salón (opcional)</Label>
              <Input
                id="class-room"
                value={room}
                maxLength={60}
                onChange={(event) => {
                  setRoom(event.target.value)
                  setFromTimetable(false)
                }}
              />
            </div>
          </div>
          {fromTimetable && (
            <p className="text-sm text-muted-foreground">Lo completamos desde tus horarios. Podés cambiarlo.</p>
          )}
          {noSlot && (
            <p className="text-sm text-muted-foreground">
              En tus horarios no tenés este grupo y materia ese día.
            </p>
          )}
          {item.subject && (
            <div>
              <Button type="button" variant="outline" size="sm" onClick={() => void suggest(false)}>
                Usar mis horarios
              </Button>
            </div>
          )}
          {error && <p className="text-sm text-destructive">{error}</p>}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              Cancelar
            </Button>
            <Button type="submit" disabled={saving}>
              {saving ? "Guardando…" : "Guardar"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
