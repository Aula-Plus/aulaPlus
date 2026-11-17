import { useEffect, useState } from "react"
import { isAxiosError } from "axios"
import { useAuth } from "@/features/auth/AuthContext"
import { canManageSchoolYear } from "@/lib/permissions"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { PageHeader } from "@/components/ui/page-header"
import { SectionCard } from "@/components/ui/section-card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { NoAccessPage } from "@/pages/NoAccessPage"
import { calendarBodyLabels, type CalendarBody } from "@/types"
import * as schoolYearApi from "./schoolYearApi"
import type { CarryAction, PeriodCalendar, PeriodState, PeriodTarget, SchoolYearState } from "./schoolYearApi"
import { formatDay, sameDateNextYear } from "./schoolYearDates"

const PERIOD_STATE_LABELS: Record<PeriodState, string> = {
  past: "Terminado",
  current: "En curso",
  upcoming: "Próximo",
}

/** A row's name: its body («Cambridge») or, split by program, its program. */
function calendarLabel(calendar: PeriodCalendar): string {
  if (calendar.name) return calendar.name
  return calendar.body ? calendarBodyLabels[calendar.body] : "Programa"
}

/** The first validation message the server sent, mapped to Spanish where known. */
function errorText(error: unknown, fallback: string): string {
  if (!isAxiosError(error)) return fallback
  const errors = error.response?.data?.errors as Record<string, string[]> | undefined
  const message = errors ? Object.values(errors)[0]?.[0] : undefined
  const known: Record<string, string> = {
    "Closing is not enabled yet.": "Todavía no se puede cerrar: el cierre se habilita desde la fecha elegida.",
    "Choose from which day closing is enabled first.": "Elegí primero desde qué fecha se habilita el cierre.",
    "The school year is already closed.": "El año ya está cerrado.",
    "Choose what happens with every period of the closed year.":
      "Elegí qué pasa con cada período antes de abrir el año.",
    "The next school year is already open.": "El año siguiente ya está abierto.",
    "Loading can only be enabled in a group of a closed year.":
      "Solo se puede habilitar la carga en un grupo de un año cerrado.",
    "The next school year is already open: closing cannot be undone.":
      "El año siguiente ya está abierto: el cierre ya no se puede deshacer.",
    "The school year is not closed.": "El año no está cerrado.",
    "The programs have different periods: make them the same before joining.":
      "Los programas tienen períodos distintos: para volver a una sola fila, tienen que tener los mismos.",
    "The school has only one program of this body.": "El colegio tiene un solo programa de este organismo.",
    "The periods of a closed school year cannot change.": "Los períodos de un año cerrado no cambian.",
  }
  return (message && known[message]) || fallback
}

/**
 * Screen 26 «Año y calendario», route `/administracion/ano-y-calendario`
 * (ClickUp 1dfd3mc4bh7). Direction only (UX gate; SchoolYearPolicy decides).
 *
 * - The year's state, its periods per body of the offer (Cambridge and IB can
 *   be split by program) — each row its own scheme,
 *   name and start date (P26-ANO-02, P26-DEC-04) — and the closing card that
 *   says what happens before the button (P26-ANO-03, P26-DEC-02).
 * - Closing deletes and moves nothing: the groups stay as history, read-only,
 *   and the accommodations stay in force on the student. Direction chooses
 *   from which day closing is enabled.
 * - Once closed: «Abrir el año N+1», choosing per period same date / other
 *   date / not carried; and loading re-enabled in one group until a date.
 */
export function SchoolYearPage() {
  const { user } = useAuth()
  const canView = canManageSchoolYear(user)
  const [state, setState] = useState<SchoolYearState | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  useEffect(() => {
    if (!canView) return
    schoolYearApi
      .fetchSchoolYear()
      .then(setState)
      .catch(() => setError("No pudimos cargar el año lectivo."))
  }, [canView])

  if (!canView) {
    return <NoAccessPage />
  }

  /** Runs a change and shows the screen state the server returns. */
  async function run(change: () => Promise<SchoolYearState | void>, fallback: string, done?: string) {
    setError(null)
    setNotice(null)
    try {
      const next = await change()
      setState(next ?? (await schoolYearApi.fetchSchoolYear()))
      if (done) setNotice(done)
      return true
    } catch (caught) {
      setError(errorText(caught, fallback))
      return false
    }
  }

  if (!state) {
    return (
      <div className="grid gap-6">
        <PageHeader title="Año y calendario" />
        {error ? (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        ) : (
          <p className="text-muted-foreground">Cargando…</p>
        )}
      </div>
    )
  }

  const { year } = state
  const isOpen = year.status === "open"
  const currentPeriods = state.calendars.flatMap((calendar) =>
    calendar.periods.filter((period) => period.state === "current").map((period) => ({ calendar, period })),
  )

  return (
    <div className="grid gap-6">
      <PageHeader
        title={`Año ${year.year}`}
        description="Año y calendario: el estado del año, los períodos de cada programa y el cierre."
      >
        <Badge tone={isOpen ? "success" : "neutral"}>
          {isOpen ? `Año ${year.year} abierto` : `Año ${year.year} cerrado`}
        </Badge>
      </PageHeader>

      {currentPeriods.length > 0 && (
        <p className="text-sm text-muted-foreground">
          {currentPeriods.map(({ calendar, period }) => `${calendarLabel(calendar)}: ${period.name} en curso`).join(" · ")}
        </p>
      )}

      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
      {notice && (
        <p role="status" className="text-sm text-emerald-700">
          {notice}
        </p>
      )}

      <SectionCard title="Estado del año">
        <dl className="grid gap-3 text-sm sm:grid-cols-4">
          <div>
            <dt className="text-muted-foreground">Año lectivo</dt>
            <dd className="font-medium">{year.year}</dd>
          </div>
          <div>
            <dt className="text-muted-foreground">Estado</dt>
            <dd className="font-medium">{isOpen ? "Abierto" : "Cerrado"}</dd>
          </div>
          <div>
            <dt className="text-muted-foreground">Grupos del año</dt>
            <dd className="font-medium">{state.active_groups}</dd>
          </div>
          <div>
            <dt className="text-muted-foreground">Año anterior</dt>
            <dd className="font-medium">
              {state.previous
                ? state.previous.status === "closed"
                  ? `${state.previous.year} · cerrado el ${formatDay(state.previous.closed_at)}`
                  : `${state.previous.year} · abierto`
                : "—"}
            </dd>
          </div>
        </dl>
      </SectionCard>

      <PeriodsCard
        state={state}
        onCreate={(input) =>
          run(() => schoolYearApi.createPeriod(year.id, input), "No pudimos agregar el período. Revisá el nombre y que la fecha sea de este año.")
        }
        onUpdate={(periodId, input) =>
          run(() => schoolYearApi.updatePeriod(periodId, input), "No pudimos guardar el período. Revisá que la fecha sea de este año.")
        }
        onDelete={(periodId) => run(() => schoolYearApi.deletePeriod(periodId), "No pudimos quitar el período.")}
        onSplit={(body) =>
          run(
            () => schoolYearApi.splitBody(year.id, body),
            "No pudimos separar por programa.",
            `${calendarBodyLabels[body]} quedó separado por programa.`,
          )
        }
        onJoin={(body) =>
          run(
            () => schoolYearApi.joinBody(year.id, body),
            "No pudimos volver a una sola fila.",
            `${calendarBodyLabels[body]} volvió a una sola fila.`,
          )
        }
      />

      <SectionCard title="Abrir y cerrar el año">
        {isOpen ? (
          <CloseYearPanel
            state={state}
            onSaveDate={(date) =>
              run(
                () => schoolYearApi.updateClosingDate(year.id, date),
                `Elegí una fecha de ${year.year} o ${year.year + 1}.`,
                "Fecha de cierre guardada.",
              )
            }
            onClose={() =>
              run(() => schoolYearApi.closeYear(year.id), "No pudimos cerrar el año.", `El año ${year.year} quedó cerrado.`)
            }
          />
        ) : (
          <OpenYearPanel
            state={state}
            onReopen={() =>
              run(
                () => schoolYearApi.reopenYear(year.id),
                "No pudimos deshacer el cierre.",
                `Se deshizo el cierre: el año ${year.year} está abierto otra vez.`,
              )
            }
            onOpen={(decisions) =>
              run(
                () => schoolYearApi.openNextYear(year.id, decisions),
                "No pudimos abrir el año. Revisá que cada fecha nueva sea del año que se abre.",
                `El año ${state.next_year} quedó abierto.`,
              )
            }
          />
        )}
      </SectionCard>

      {(!isOpen || state.closed_year_groups.length > 0 || state.write_windows.length > 0) && (
        <WriteWindowsCard
          state={state}
          onCreate={(input) =>
            run(
              () => schoolYearApi.createWriteWindow(year.id, input),
              "No pudimos habilitar la carga. Elegí el grupo, escribí el motivo y una fecha desde hoy.",
              "Carga habilitada.",
            )
          }
          onEnd={(windowId) => run(() => schoolYearApi.endWriteWindow(windowId), "No pudimos terminar la habilitación.")}
        />
      )}
    </div>
  )
}

// ── Periods ─────────────────────────────────────────────────────────────────

interface PeriodsCardProps {
  state: SchoolYearState
  onCreate: (input: PeriodTarget & { name: string; starts_on: string }) => Promise<boolean>
  onUpdate: (periodId: number, input: { name: string; starts_on: string }) => Promise<boolean>
  onDelete: (periodId: number) => Promise<boolean>
  onSplit: (body: CalendarBody) => Promise<boolean>
  onJoin: (body: CalendarBody) => Promise<boolean>
}

function targetFor(calendar: PeriodCalendar): PeriodTarget | null {
  if (calendar.curricular_framework_id !== null) return { curricular_framework_id: calendar.curricular_framework_id }
  return calendar.body ? { calendar_body: calendar.body } : null
}

/**
 * One row per body of the school's offer — ANEP Bachillerato, ANEP Ciclo
 * Básico y Primaria, Cambridge, IB (Eitán 17/11) —; Cambridge and IB can be
 * «Separar por programa», each program then with its own periods.
 */
function PeriodsCard({ state, onCreate, onUpdate, onDelete, onSplit, onJoin }: PeriodsCardProps) {
  const editable = state.year.status === "open"
  const [calendarKey, setCalendarKey] = useState("")
  const [name, setName] = useState("")
  const [startsOn, setStartsOn] = useState("")
  const [editing, setEditing] = useState<{ id: number; name: string; starts_on: string } | null>(null)
  const [busy, setBusy] = useState(false)
  const selected = state.calendars.find((calendar) => calendar.key === calendarKey)

  async function add() {
    const target = selected ? targetFor(selected) : null
    if (!target || !name.trim() || !startsOn) return
    const ok = await onCreate({ ...target, name: name.trim(), starts_on: startsOn })
    if (ok) {
      setName("")
      setStartsOn("")
    }
  }

  async function guarded(action: () => Promise<boolean>) {
    setBusy(true)
    try {
      await action()
    } finally {
      setBusy(false)
    }
  }

  function labelCell(calendar: PeriodCalendar, first: boolean) {
    if (!first) return <TableCell />
    const isBodyRow = calendar.curricular_framework_id === null
    return (
      <TableCell className="font-medium">
        {calendarLabel(calendar)}
        {isBodyRow && calendar.programs.length > 1 && (
          <span className="block text-xs font-normal text-muted-foreground">{calendar.programs.join(", ")}</span>
        )}
        {!isBodyRow && calendar.body && (
          <span className="block text-xs font-normal text-muted-foreground">
            {calendarBodyLabels[calendar.body]}, separado por programa
          </span>
        )}
        {editable && calendar.can_split && calendar.body && (
          <Button
            size="sm"
            variant="outline"
            className="mt-1"
            disabled={busy}
            onClick={() => guarded(() => onSplit(calendar.body as CalendarBody))}
          >
            Separar por programa
          </Button>
        )}
      </TableCell>
    )
  }

  return (
    <SectionCard title="Períodos">
      <p className="mb-3 text-sm text-muted-foreground">
        Una fila por organismo de la oferta curricular del colegio; cada uno tiene su esquema y no se
        fuerza uno único. En Cambridge e IB, «Separar por programa» sirve cuando un programa tiene otro
        calendario: cada programa empieza con los períodos que tenía la fila. Un período dura hasta que
        empieza el siguiente de la misma fila. Los períodos no cambian el corte de «Visibilidad de
        calificaciones».
      </p>
      {state.calendars.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          El colegio todavía no tiene programas activos (se activan en Colegio y estructura).
        </p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Organismo o programa</TableHead>
              <TableHead>Período</TableHead>
              <TableHead>Desde</TableHead>
              <TableHead>Estado</TableHead>
              {editable && <TableHead className="sr-only">Acciones</TableHead>}
            </TableRow>
          </TableHeader>
          <TableBody>
            {state.calendars.map((calendar) =>
              calendar.periods.length === 0 ? (
                <TableRow key={calendar.key}>
                  {labelCell(calendar, true)}
                  <TableCell colSpan={editable ? 4 : 3} className="text-muted-foreground">
                    Sin períodos cargados.
                  </TableCell>
                </TableRow>
              ) : (
                calendar.periods.map((period, index) =>
                  editing?.id === period.id ? (
                    <TableRow key={period.id}>
                      {labelCell(calendar, index === 0)}
                      <TableCell>
                        <Input
                          aria-label="Nombre del período"
                          value={editing.name}
                          maxLength={80}
                          onChange={(event) => setEditing({ ...editing, name: event.target.value })}
                        />
                      </TableCell>
                      <TableCell>
                        <Input
                          type="date"
                          aria-label="Fecha de inicio del período"
                          value={editing.starts_on}
                          onChange={(event) => setEditing({ ...editing, starts_on: event.target.value })}
                        />
                      </TableCell>
                      <TableCell />
                      <TableCell className="flex gap-2">
                        <Button
                          size="sm"
                          onClick={async () => {
                            if (await onUpdate(editing.id, { name: editing.name.trim(), starts_on: editing.starts_on })) {
                              setEditing(null)
                            }
                          }}
                        >
                          Guardar
                        </Button>
                        <Button size="sm" variant="outline" onClick={() => setEditing(null)}>
                          Cancelar
                        </Button>
                      </TableCell>
                    </TableRow>
                  ) : (
                    <TableRow key={period.id}>
                      {labelCell(calendar, index === 0)}
                      <TableCell>{period.name}</TableCell>
                      <TableCell>{formatDay(period.starts_on)}</TableCell>
                      <TableCell>
                        {period.state === "current" ? (
                          <Badge tone="info">{PERIOD_STATE_LABELS.current}</Badge>
                        ) : (
                          PERIOD_STATE_LABELS[period.state]
                        )}
                      </TableCell>
                      {editable && (
                        <TableCell className="flex gap-2">
                          <Button
                            size="sm"
                            variant="outline"
                            aria-label={`Editar ${period.name} de ${calendarLabel(calendar)}`}
                            onClick={() => setEditing({ id: period.id, name: period.name, starts_on: period.starts_on })}
                          >
                            Editar
                          </Button>
                          <Button
                            size="sm"
                            variant="outline"
                            aria-label={`Quitar ${period.name} de ${calendarLabel(calendar)}`}
                            onClick={() => onDelete(period.id)}
                          >
                            Quitar
                          </Button>
                        </TableCell>
                      )}
                    </TableRow>
                  ),
                )
              ),
            )}
          </TableBody>
        </Table>
      )}

      {editable && state.split_bodies.length > 0 && (
        <div className="mt-3 flex flex-wrap gap-2">
          {state.split_bodies.map((body) => (
            <Button key={body} size="sm" variant="outline" disabled={busy} onClick={() => guarded(() => onJoin(body))}>
              Volver a una fila: {calendarBodyLabels[body]}
            </Button>
          ))}
        </div>
      )}

      {editable && state.calendars.length > 0 && (
        <form
          className="mt-4 grid gap-3 sm:grid-cols-4 sm:items-end"
          onSubmit={(event) => {
            event.preventDefault()
            void add()
          }}
        >
          <div className="grid gap-1">
            <Label htmlFor="period-calendar">Organismo o programa</Label>
            <select
              id="period-calendar"
              className="h-9 rounded-md border px-2"
              value={calendarKey}
              onChange={(event) => setCalendarKey(event.target.value)}
            >
              <option value="">Elegí uno</option>
              {state.calendars
                .filter((calendar) => targetFor(calendar) !== null)
                .map((calendar) => (
                  <option key={calendar.key} value={calendar.key}>
                    {calendarLabel(calendar)}
                  </option>
                ))}
            </select>
          </div>
          <div className="grid gap-1">
            <Label htmlFor="period-name">Nombre</Label>
            <Input
              id="period-name"
              placeholder="Trimestre 1"
              maxLength={80}
              value={name}
              onChange={(event) => setName(event.target.value)}
            />
          </div>
          <div className="grid gap-1">
            <Label htmlFor="period-start">Desde</Label>
            <Input id="period-start" type="date" value={startsOn} onChange={(event) => setStartsOn(event.target.value)} />
          </div>
          <Button type="submit" disabled={!selected || !name.trim() || !startsOn}>
            Agregar período
          </Button>
        </form>
      )}
    </SectionCard>
  )
}

// ── Closing ─────────────────────────────────────────────────────────────────

interface CloseYearPanelProps {
  state: SchoolYearState
  onSaveDate: (date: string) => Promise<boolean>
  onClose: () => Promise<boolean>
}

function CloseYearPanel({ state, onSaveDate, onClose }: CloseYearPanelProps) {
  const { year } = state
  const [date, setDate] = useState(year.closing_enabled_from ?? "")
  const [confirming, setConfirming] = useState(false)
  const [busy, setBusy] = useState(false)

  return (
    <div className="grid gap-4 text-sm">
      {year.reopened_at && (
        <p className="text-muted-foreground">
          Se deshizo el cierre el {formatDay(year.reopened_at)}
          {year.reopened_by_name ? ` (lo hizo ${year.reopened_by_name})` : ""}.
        </p>
      )}
      {/* P26-DEC-02: the consequence is written above the button. */}
      <p>
        Antes de cerrar, esto es lo que pasa: no se borra ni se mueve nada. Los grupos {year.year} quedan
        como historia y pasan a ser solo para leer, también su estructura (docentes, oferta curricular y
        nivel); cada alumno queda sin grupo hasta que se arman los
        grupos de {state.next_year} en Colegio y estructura. Los ajustes pasan solos al año nuevo,
        porque son del alumno: pasan {state.carried_accommodations}{" "}
        {state.carried_accommodations === 1 ? "ajuste vigente" : "ajustes vigentes"}.
      </p>

      <form
        className="flex flex-wrap items-end gap-3"
        onSubmit={(event) => {
          event.preventDefault()
          if (date) void onSaveDate(date)
        }}
      >
        <div className="grid gap-1">
          <Label htmlFor="closing-date">Cierre habilitado desde</Label>
          <Input id="closing-date" type="date" value={date} onChange={(event) => setDate(event.target.value)} />
        </div>
        <Button type="submit" variant="outline" disabled={!date || date === year.closing_enabled_from}>
          Guardar fecha
        </Button>
      </form>

      {confirming ? (
        <div className="grid gap-3 rounded-md border border-destructive/40 p-3" role="group" aria-label="Confirmar el cierre">
          <p className="font-medium">
            ¿Cerrar el año {year.year}? Los grupos {year.year} quedan para leer: sus datos y su estructura.
            Mientras no se abra el año {state.next_year}, Dirección puede deshacer el cierre.
          </p>
          <div className="flex gap-2">
            <Button
              variant="destructive"
              disabled={busy}
              onClick={async () => {
                setBusy(true)
                await onClose()
                setBusy(false)
                setConfirming(false)
              }}
            >
              Sí, cerrar el año {year.year}
            </Button>
            <Button variant="outline" onClick={() => setConfirming(false)}>
              Cancelar
            </Button>
          </div>
        </div>
      ) : (
        <div className="grid gap-1">
          <div>
            <Button disabled={!year.can_close} onClick={() => setConfirming(true)}>
              Cerrar el año {year.year}
            </Button>
          </div>
          <p className="text-muted-foreground">
            {year.closing_enabled_from
              ? `El cierre se habilita desde el ${formatDay(year.closing_enabled_from)} y pide confirmación.`
              : "Elegí desde qué fecha se habilita el cierre. Pide confirmación."}
          </p>
        </div>
      )}
    </div>
  )
}

// ── Opening the next year ───────────────────────────────────────────────────

interface Choice {
  action: CarryAction
  startsOn: string
}

function OpenYearPanel({
  state,
  onOpen,
  onReopen,
}: {
  state: SchoolYearState
  onOpen: (decisions: schoolYearApi.CarryDecision[]) => Promise<boolean>
  onReopen: () => Promise<boolean>
}) {
  const { year, next_year: nextYear } = state
  const [choosing, setChoosing] = useState(false)
  const [choices, setChoices] = useState<Record<number, Choice>>({})
  const [opening, setOpening] = useState(false)
  const [confirmingUndo, setConfirmingUndo] = useState(false)
  const [undoing, setUndoing] = useState(false)
  const programs: PeriodCalendar[] = state.calendars.filter((calendar) => calendar.periods.length > 0)

  function choiceFor(periodId: number, startsOn: string): Choice {
    return choices[periodId] ?? { action: "same_date", startsOn: sameDateNextYear(startsOn) }
  }

  function setChoice(periodId: number, choice: Choice) {
    setChoices((current) => ({ ...current, [periodId]: choice }))
  }

  const decisions = programs.flatMap((program) =>
    program.periods.map((period) => {
      const choice = choiceFor(period.id, period.starts_on)
      return choice.action === "other_date"
        ? { id: period.id, action: choice.action, starts_on: choice.startsOn }
        : { id: period.id, action: choice.action }
    }),
  )
  const incomplete = decisions.some((decision) => decision.action === "other_date" && !decision.starts_on)

  return (
    <div className="grid gap-4 text-sm">
      <p>
        El año {year.year} está cerrado
        {year.closed_at ? ` desde el ${formatDay(year.closed_at)}` : ""}
        {year.closed_by_name ? ` (lo cerró ${year.closed_by_name})` : ""}. Sus grupos quedan para leer y
        los ajustes ya pasaron: siguen vigentes en cada alumno. Los grupos de {nextYear} se arman en
        Colegio y estructura.
      </p>

      {confirmingUndo ? (
        <div className="grid gap-3 rounded-md border border-destructive/40 p-3" role="group" aria-label="Confirmar deshacer el cierre">
          <p className="font-medium">
            ¿Deshacer el cierre del año {year.year}? El año vuelve a estar abierto y sus grupos se pueden
            cargar y corregir otra vez. No se borra nada, y queda registrado quién lo hizo y cuándo.
          </p>
          <div className="flex gap-2">
            <Button
              variant="destructive"
              disabled={undoing}
              onClick={async () => {
                setUndoing(true)
                try {
                  await onReopen()
                } finally {
                  setUndoing(false)
                  setConfirmingUndo(false)
                }
              }}
            >
              Sí, deshacer el cierre
            </Button>
            <Button variant="outline" onClick={() => setConfirmingUndo(false)}>
              Cancelar
            </Button>
          </div>
        </div>
      ) : !choosing ? (
        <div className="flex flex-wrap gap-2">
          <Button onClick={() => setChoosing(true)}>Abrir el año {nextYear}</Button>
          {year.can_reopen && (
            <Button variant="outline" onClick={() => setConfirmingUndo(true)}>
              Deshacer el cierre
            </Button>
          )}
        </div>
      ) : (
        <div className="grid gap-4">
          <p className="text-muted-foreground">
            Para cada período, elegí si pasa a la misma fecha de {nextYear}, a otra fecha o si no pasa.
          </p>
          {programs.length === 0 && (
            <p className="text-muted-foreground">El año {year.year} no tenía períodos cargados.</p>
          )}
          {programs.map((program) => (
            <fieldset key={program.key} className="grid gap-2 rounded-md border p-3">
              <legend className="px-1 font-medium">{calendarLabel(program)}</legend>
              {program.periods.map((period) => {
                const choice = choiceFor(period.id, period.starts_on)
                const same = sameDateNextYear(period.starts_on)
                const group = `carry-${period.id}`
                return (
                  <div key={period.id} className="grid gap-1" role="radiogroup" aria-label={`${period.name} de ${calendarLabel(program)}`}>
                    <span>
                      {period.name} · desde el {formatDay(period.starts_on)}
                    </span>
                    <div className="flex flex-wrap items-center gap-4">
                      <label className="flex items-center gap-1">
                        <input
                          type="radio"
                          name={group}
                          checked={choice.action === "same_date"}
                          onChange={() => setChoice(period.id, { action: "same_date", startsOn: same })}
                        />
                        Misma fecha ({formatDay(same)})
                      </label>
                      <label className="flex items-center gap-1">
                        <input
                          type="radio"
                          name={group}
                          checked={choice.action === "other_date"}
                          onChange={() => setChoice(period.id, { action: "other_date", startsOn: same })}
                        />
                        Otra fecha
                      </label>
                      {choice.action === "other_date" && (
                        <Input
                          type="date"
                          className="w-auto"
                          aria-label={`Nueva fecha de ${period.name} de ${calendarLabel(program)}`}
                          min={`${nextYear}-01-01`}
                          max={`${nextYear}-12-31`}
                          value={choice.startsOn}
                          onChange={(event) => setChoice(period.id, { action: "other_date", startsOn: event.target.value })}
                        />
                      )}
                      <label className="flex items-center gap-1">
                        <input
                          type="radio"
                          name={group}
                          checked={choice.action === "skip"}
                          onChange={() => setChoice(period.id, { action: "skip", startsOn: same })}
                        />
                        No pasa
                      </label>
                    </div>
                  </div>
                )
              })}
            </fieldset>
          ))}
          <div className="flex gap-2">
            <Button
              disabled={incomplete || opening}
              onClick={async () => {
                setOpening(true)
                try {
                  await onOpen(decisions)
                } finally {
                  setOpening(false)
                }
              }}
            >
              Abrir el año {nextYear}
            </Button>
            <Button variant="outline" onClick={() => setChoosing(false)}>
              Cancelar
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}

// ── Loading re-enabled in one group ─────────────────────────────────────────

interface WriteWindowsCardProps {
  state: SchoolYearState
  onCreate: (input: { group_id: number; reason: string; until_on: string }) => Promise<boolean>
  onEnd: (windowId: number) => Promise<boolean>
}

function WriteWindowsCard({ state, onCreate, onEnd }: WriteWindowsCardProps) {
  const [groupId, setGroupId] = useState("")
  const [reason, setReason] = useState("")
  const [until, setUntil] = useState("")
  const [busy, setBusy] = useState(false)
  // Loading can be enabled in a group of a closed year even after the next
  // year was opened (e.g. «Exámenes de febrero»), so the form follows the
  // groups the server lists, not the current year's status.
  const canEnable = state.closed_year_groups.length > 0

  return (
    <SectionCard title="Carga en grupos del año cerrado">
      <p className="mb-3 text-sm text-muted-foreground">
        Con el año cerrado, sus grupos son solo para leer, también su estructura. Dirección puede
        habilitar la carga en un grupo puntual, con un motivo y una fecha hasta la que vale: mientras
        dure, también se puede corregir su estructura (el grupo, sus docentes, su oferta curricular y su
        nivel). Queda registrado quién, cuándo y por qué, y al pasar la fecha el grupo vuelve solo a ser
        de lectura.
      </p>

      {canEnable && (
        <form
          className="mb-4 grid gap-3 sm:grid-cols-4 sm:items-end"
          onSubmit={async (event) => {
            event.preventDefault()
            if (!groupId || !reason.trim() || !until || busy) return
            setBusy(true)
            try {
              if (await onCreate({ group_id: Number(groupId), reason: reason.trim(), until_on: until })) {
                setGroupId("")
                setReason("")
                setUntil("")
              }
            } finally {
              setBusy(false)
            }
          }}
        >
          <div className="grid gap-1">
            <Label htmlFor="window-group">Grupo</Label>
            <select
              id="window-group"
              className="h-9 rounded-md border px-2"
              value={groupId}
              onChange={(event) => setGroupId(event.target.value)}
            >
              <option value="">Elegí un grupo</option>
              {state.closed_year_groups.map((group) => (
                <option key={group.id} value={group.id}>
                  {group.name} ({group.school_year})
                </option>
              ))}
            </select>
          </div>
          <div className="grid gap-1">
            <Label htmlFor="window-reason">Motivo</Label>
            <Input
              id="window-reason"
              placeholder="Exámenes de febrero"
              maxLength={200}
              value={reason}
              onChange={(event) => setReason(event.target.value)}
            />
          </div>
          <div className="grid gap-1">
            <Label htmlFor="window-until">Hasta</Label>
            <Input id="window-until" type="date" value={until} onChange={(event) => setUntil(event.target.value)} />
          </div>
          <Button type="submit" disabled={!groupId || !reason.trim() || !until || busy}>
            Habilitar la carga
          </Button>
        </form>
      )}

      {state.write_windows.length === 0 ? (
        <p className="text-sm text-muted-foreground">No se habilitó la carga en ningún grupo.</p>
      ) : (
        <ul className="grid gap-2 text-sm" aria-label="Habilitaciones de carga">
          {state.write_windows.map((window) => (
            <li key={window.id} className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-3">
              <div>
                <p className="font-medium">
                  {window.group?.name ?? "Grupo"} · {window.reason}
                </p>
                <p className="text-muted-foreground">
                  Hasta el {formatDay(window.until_on)} · habilitó {window.enabled_by_name ?? "—"} el{" "}
                  {formatDay(window.enabled_at)}
                  {window.ended_at
                    ? ` · terminada el ${formatDay(window.ended_at)}${window.ended_by_name ? ` por ${window.ended_by_name}` : ""}`
                    : ""}
                </p>
              </div>
              <div className="flex items-center gap-2">
                <Badge tone={window.active ? "success" : "neutral"}>{window.active ? "Vigente" : "Solo lectura"}</Badge>
                {window.active && (
                  <Button
                    size="sm"
                    variant="outline"
                    disabled={busy}
                    onClick={async () => {
                      setBusy(true)
                      try {
                        await onEnd(window.id)
                      } finally {
                        setBusy(false)
                      }
                    }}
                  >
                    Terminar ahora
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
    </SectionCard>
  )
}
