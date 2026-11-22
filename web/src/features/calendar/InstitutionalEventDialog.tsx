import { zodResolver } from "@hookform/resolvers/zod"
import { useEffect, useState } from "react"
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
import type { CalendarItem, EventAudienceOptions, EventAudienceTarget } from "@/types"
import {
  createInstitutionalEvent,
  fetchAudienceOptions,
  updateInstitutionalEvent,
  type AudienceTargetInput,
} from "./calendarApi"
import { calendarErrorMessage } from "./calendarErrors"
import { audienceToInput, groupsOfTarget, SECONDARY_LEVEL_KEYS, withGroupNames } from "./eventAudience"

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
 * detail — and who it concerns (ClickUp 1dfd3mc55zp): the whole school, or
 * levels, generations, programs within a generation and single groups, by
 * the name the school gave them. Create when `item` is absent, edit
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
  const [audience, setAudience] = useState<AudienceTargetInput[]>(() => audienceToInput(item?.audience))
  // From the stored targets, not the editable ones: a deleted group's target
  // has no input, and must not turn the event into «Todo el colegio».
  const [wholeSchool, setWholeSchool] = useState((item?.audience ?? []).length === 0)
  const [audienceError, setAudienceError] = useState<string | null>(null)
  const {
    register,
    handleSubmit,
    watch,
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
    if (!wholeSchool && audience.length === 0) {
      setAudienceError("Elegí a quién le corresponde, o marcá «Todo el colegio».")
      return
    }
    const input = {
      title: values.title.trim(),
      date: values.date,
      time: values.time || null,
      location: values.location.trim() || null,
      description: values.description.trim() || null,
      audience: wholeSchool ? [] : audience,
    }
    try {
      if (item) await updateInstitutionalEvent(item.id, input)
      else await createInstitutionalEvent(input)
      onSaved()
    } catch (err) {
      setError(calendarErrorMessage(err, "No pudimos guardar el evento."))
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{item ? "Editar evento institucional" : "Evento institucional"}</DialogTitle>
          <DialogDescription>
            Aparece en el calendario y en el Inicio de quienes tienen grupos a los que les corresponde. Todos pueden
            encontrarlo en el calendario completo del colegio.
          </DialogDescription>
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
          <AudiencePicker
            year={Number(watch("date").slice(0, 4))}
            wholeSchool={wholeSchool}
            targets={audience}
            stored={item?.audience}
            onWholeSchool={(value) => {
              setWholeSchool(value)
              setAudienceError(null)
            }}
            onChange={(targets) => {
              setAudience(targets)
              setAudienceError(null)
            }}
          />
          {audienceError && <p className="text-sm text-destructive">{audienceError}</p>}
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

function sameTarget(a: AudienceTargetInput, b: AudienceTargetInput): boolean {
  return (
    a.level_id === b.level_id &&
    a.grade_id === b.grade_id &&
    a.framework_id === b.framework_id &&
    a.group_id === b.group_id
  )
}

/**
 * «A quién le corresponde»: «Todo el colegio», or any combination of levels
 * («Secundaria» = Ciclo Básico + Bachillerato), generations, the groups of a
 * program within a generation and single groups. Groups are listed by the
 * name the school gave them, for the event's school year (`year`, the year
 * of its date): a generation or program shows the groups it reaches. UX
 * only: the server validates every target (InstitutionalEventRequest).
 */
export function AudiencePicker({
  year,
  wholeSchool,
  targets,
  stored,
  onWholeSchool,
  onChange,
}: {
  /** The event's school year (the calendar year of its date). */
  year: number
  wholeSchool: boolean
  targets: AudienceTargetInput[]
  /** The event's saved targets: their names label a program no longer offered. */
  stored?: readonly EventAudienceTarget[]
  onWholeSchool: (value: boolean) => void
  onChange: (targets: AudienceTargetInput[]) => void
}) {
  const [options, setOptions] = useState<EventAudienceOptions | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [gradeId, setGradeId] = useState("")
  const [frameworkId, setFrameworkId] = useState("")
  const [groupId, setGroupId] = useState("")

  useEffect(() => {
    let active = true
    fetchAudienceOptions()
      .then((result) => {
        if (active) setOptions(result)
      })
      .catch(() => {
        if (active) setLoadError(true)
      })
    return () => {
      active = false
    }
  }, [])

  const levels = options?.levels ?? []
  const grades = levels.flatMap((level) => level.grades.map((grade) => ({ ...grade, levelKey: level.key })))
  const levelIds = targets.flatMap((target) => (target.level_id !== undefined ? [target.level_id] : []))
  const secondaryIds = levels
    .filter((level) => (SECONDARY_LEVEL_KEYS as readonly string[]).includes(level.key))
    .map((level) => level.id)
  const secondaryOn = secondaryIds.length > 0 && secondaryIds.every((id) => levelIds.includes(id))
  const chosenGrade = grades.find((grade) => String(grade.id) === gradeId)
  const frameworksForGrade = (options?.frameworks ?? []).filter(
    (framework) =>
      chosenGrade && (framework.education_levels === null || framework.education_levels.includes(chosenGrade.levelKey)),
  )
  const generations = targets.filter((target) => target.grade_id !== undefined)
  const chosenGroups = targets.filter((target) => target.group_id !== undefined)
  const groupsOfYear = (options?.groups ?? []).filter((group) => group.school_year === year)
  const names = (target: AudienceTargetInput) => groupsOfTarget(target, groupsOfYear, year)
  // The picker's groups by generation, in the catalog's order; groups with
  // no generation last.
  const groupSections = [
    ...grades.map((grade) => ({
      key: `grade-${grade.id}`,
      label: grade.label,
      groups: groupsOfYear.filter((group) => group.grade_id === grade.id),
    })),
    {
      key: "none",
      label: "Sin generación",
      groups: groupsOfYear.filter((group) => group.grade_id === null || !grades.some((grade) => grade.id === group.grade_id)),
    },
  ].filter((section) => section.groups.length > 0)

  function setLevels(ids: number[], on: boolean) {
    const rest = targets.filter((target) => target.level_id === undefined || !ids.includes(target.level_id))
    onChange(on ? [...rest, ...ids.map((id) => ({ level_id: id }))] : rest)
  }

  function addGeneration() {
    if (!chosenGrade) return
    const target: AudienceTargetInput = frameworkId
      ? { grade_id: chosenGrade.id, framework_id: Number(frameworkId) }
      : { grade_id: chosenGrade.id }
    if (!targets.some((existing) => sameTarget(existing, target))) onChange([...targets, target])
    setGradeId("")
    setFrameworkId("")
  }

  function addGroup() {
    if (!groupId) return
    const target: AudienceTargetInput = { group_id: Number(groupId) }
    if (!targets.some((existing) => sameTarget(existing, target))) onChange([...targets, target])
    setGroupId("")
  }

  function groupLabel(target: AudienceTargetInput): string {
    return (
      options?.groups.find((group) => group.id === target.group_id)?.name ??
      stored?.find((item) => item.group?.id === target.group_id)?.group?.name ??
      "Grupo"
    )
  }

  function generationLabel(target: AudienceTargetInput): string {
    const saved = stored?.find((item) => item.grade?.id === target.grade_id && item.framework?.id === target.framework_id)
    const grade = grades.find((item) => item.id === target.grade_id)?.label ?? saved?.grade?.label ?? "Generación"
    if (target.framework_id === undefined) return withGroupNames(grade, names(target))
    const framework = options?.frameworks.find((item) => item.id === target.framework_id)?.name ?? saved?.framework?.name
    return withGroupNames(`${framework ?? "Un programa"} en ${grade}`, names(target))
  }

  /** «Todos sus grupos (3° A, 3° B)»: the option says which groups it reaches. */
  function optionWithGroups(label: string, target: AudienceTargetInput): string {
    const reached = names(target)
    return reached.length > 0 ? `${label} (${reached.map((group) => group.name).join(", ")})` : label
  }

  return (
    <fieldset className="grid gap-3 rounded-md border p-3">
      <legend className="px-1 text-sm font-medium">A quién le corresponde</legend>
      <label className="flex items-center gap-2 text-sm">
        <input
          type="checkbox"
          className="size-4"
          checked={wholeSchool}
          onChange={(event) => onWholeSchool(event.target.checked)}
        />
        Todo el colegio
      </label>
      {!wholeSchool &&
        (loadError ? (
          <p className="text-sm text-destructive">No pudimos cargar los niveles y las generaciones.</p>
        ) : options === null ? (
          <p className="text-sm text-muted-foreground">Cargando opciones…</p>
        ) : (
          <>
            <div role="group" aria-label="Niveles" className="grid gap-1">
              <p className="text-xs font-medium text-muted-foreground">Niveles</p>
              <div className="flex flex-wrap gap-x-4 gap-y-1">
                {levels.map((level) => (
                  <label key={level.id} className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      className="size-4"
                      checked={levelIds.includes(level.id)}
                      onChange={(event) => setLevels([level.id], event.target.checked)}
                    />
                    {level.label}
                  </label>
                ))}
                {secondaryIds.length > 0 && (
                  <label className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      className="size-4"
                      checked={secondaryOn}
                      onChange={(event) => setLevels(secondaryIds, event.target.checked)}
                    />
                    Secundaria (Ciclo Básico y Bachillerato)
                  </label>
                )}
              </div>
            </div>
            <div className="grid gap-2">
              <p className="text-xs font-medium text-muted-foreground">Generaciones</p>
              <div className="flex flex-wrap items-end gap-2">
                <select
                  aria-label="Generación"
                  className="h-9 rounded-md border bg-background px-2 text-sm"
                  value={gradeId}
                  onChange={(event) => {
                    setGradeId(event.target.value)
                    setFrameworkId("")
                  }}
                >
                  <option value="">Elegí una generación</option>
                  {levels.map((level) => (
                    <optgroup key={level.id} label={level.label}>
                      {level.grades.map((grade) => (
                        <option key={grade.id} value={grade.id}>
                          {grade.label}
                        </option>
                      ))}
                    </optgroup>
                  ))}
                </select>
                <select
                  aria-label="Grupos de la generación"
                  className="h-9 rounded-md border bg-background px-2 text-sm"
                  value={frameworkId}
                  disabled={!chosenGrade || frameworksForGrade.length === 0}
                  onChange={(event) => setFrameworkId(event.target.value)}
                >
                  <option value="">
                    {chosenGrade ? optionWithGroups("Todos sus grupos", { grade_id: chosenGrade.id }) : "Todos sus grupos"}
                  </option>
                  {frameworksForGrade.map((framework) => (
                    <option key={framework.id} value={framework.id}>
                      {chosenGrade
                        ? optionWithGroups(`Solo los grupos de ${framework.name}`, {
                            grade_id: chosenGrade.id,
                            framework_id: framework.id,
                          })
                        : `Solo los grupos de ${framework.name}`}
                    </option>
                  ))}
                </select>
                <Button type="button" variant="outline" size="sm" disabled={!chosenGrade} onClick={addGeneration}>
                  Agregar generación
                </Button>
              </div>
              {generations.length > 0 && (
                <ul className="flex flex-wrap gap-2" aria-label="Generaciones elegidas">
                  {generations.map((target) => {
                    const label = generationLabel(target)
                    return (
                      <li
                        key={`${target.grade_id}-${target.framework_id ?? 0}`}
                        className="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs"
                      >
                        {label}
                        <button
                          type="button"
                          className="text-muted-foreground hover:text-foreground"
                          aria-label={`Quitar ${label}`}
                          onClick={() => onChange(targets.filter((existing) => !sameTarget(existing, target)))}
                        >
                          ×
                        </button>
                      </li>
                    )
                  })}
                </ul>
              )}
            </div>
            <div className="grid gap-2">
              <p className="text-xs font-medium text-muted-foreground">Grupos</p>
              {groupSections.length === 0 ? (
                <p className="text-sm text-muted-foreground">No hay grupos cargados para {year}.</p>
              ) : (
                <div className="flex flex-wrap items-end gap-2">
                  <select
                    aria-label="Grupo"
                    className="h-9 rounded-md border bg-background px-2 text-sm"
                    value={groupId}
                    onChange={(event) => setGroupId(event.target.value)}
                  >
                    <option value="">Elegí un grupo</option>
                    {groupSections.map((section) => (
                      <optgroup key={section.key} label={section.label}>
                        {section.groups.map((group) => (
                          <option key={group.id} value={group.id}>
                            {group.name}
                          </option>
                        ))}
                      </optgroup>
                    ))}
                  </select>
                  <Button type="button" variant="outline" size="sm" disabled={!groupId} onClick={addGroup}>
                    Agregar grupo
                  </Button>
                </div>
              )}
              {chosenGroups.length > 0 && (
                <ul className="flex flex-wrap gap-2" aria-label="Grupos elegidos">
                  {chosenGroups.map((target) => {
                    const label = groupLabel(target)
                    return (
                      <li
                        key={target.group_id}
                        className="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs"
                      >
                        {label}
                        <button
                          type="button"
                          className="text-muted-foreground hover:text-foreground"
                          aria-label={`Quitar ${label}`}
                          onClick={() => onChange(targets.filter((existing) => !sameTarget(existing, target)))}
                        >
                          ×
                        </button>
                      </li>
                    )
                  })}
                </ul>
              )}
            </div>
          </>
        ))}
    </fieldset>
  )
}
