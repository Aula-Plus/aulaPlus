import { useEffect, useMemo, useState } from "react"
import { Link, useLocation, useSearchParams } from "react-router-dom"
import { CalendarDays, ChevronLeft, ChevronRight, Plus } from "lucide-react"
import { Button } from "@/components/ui/button"
import { ConfirmDialog } from "@/components/ui/confirm-dialog"
import { EmptyState } from "@/components/ui/empty-state"
import { PageHeader } from "@/components/ui/page-header"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { useAuth } from "@/features/auth/AuthContext"
import { canManageInstitutionalEvents, hasTimetable, isDirector, isPsychopedagogue } from "@/lib/permissions"
import { cn } from "@/lib/utils"
import {
  calendarItemSourceLabels,
  calendarItemTypeLabels,
  calendarItemTypes,
  type CalendarItem,
  type CalendarItemType,
  type User,
} from "@/types"
import { deleteInstitutionalEvent, fetchCalendar } from "./calendarApi"
import {
  addDays,
  chunkRange,
  dayParam,
  daysBetween,
  initialListRange,
  LIST_STEP_DAYS,
  listTitle,
  rangeFor,
  shiftAnchor,
  toDay,
  viewTitle,
  WEEKDAY_SHORT,
  weekdayDayMonth,
  type CalendarView,
} from "./calendarDates"
import { calendarItemHour, calendarItemLink, calendarItemWhat } from "./calendarItemText"
import { ClassScheduleDialog } from "./ClassScheduleDialog"
import { InstitutionalEventDialog } from "./InstitutionalEventDialog"
import { TimetablePanel } from "./TimetablePanel"

const TIMETABLE_PATH = "/calendario/mis-horarios"

/** Chip colors per type (Tailwind palette, readable in light and dark). */
const typeTone: Record<CalendarItemType, string> = {
  assessment: "border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100",
  class: "border-sky-300 bg-sky-50 text-sky-900 dark:border-sky-700 dark:bg-sky-950 dark:text-sky-100",
  meeting: "border-violet-300 bg-violet-50 text-violet-900 dark:border-violet-700 dark:bg-violet-950 dark:text-violet-100",
  task: "border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-700 dark:bg-emerald-950 dark:text-emerald-100",
  period_closing: "border-rose-300 bg-rose-50 text-rose-900 dark:border-rose-700 dark:bg-rose-950 dark:text-rose-100",
  project_stage: "border-orange-300 bg-orange-50 text-orange-900 dark:border-orange-700 dark:bg-orange-950 dark:text-orange-100",
  institutional: "border-slate-300 bg-slate-100 text-slate-900 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100",
}

function roleDescription(user: User | null): string {
  if (isDirector(user)) return "Los eventos institucionales y la carga de evaluaciones de todo el colegio."
  if (isPsychopedagogue(user)) return "Tu agenda, cruzada con las evaluaciones del resto del colegio."
  return "Lo que cae en tu semana: tus clases, tus evaluaciones y tus tareas."
}

function roleNote(user: User | null): string {
  if (isDirector(user)) {
    return "Ves la carga de evaluaciones de todo el colegio, para anticipar semanas cargadas, nunca para mirar un caso. Un evento institucional tiene fecha, hora opcional y qué es; lo ven todos y se quita desde la vista Lista."
  }
  if (isPsychopedagogue(user)) {
    return "Ves todas las evaluaciones del colegio y tu agenda. Una reunión con equipo tratante aparece solo para quienes están citados."
  }
  return "Ves tus materias y tus grupos, tus clases y las reuniones a las que te citaron."
}

/**
 * Calendario (documento vivo screen 21; ClickUp 1dfd3mc4bgh). Orders in time
 * dates that already exist in other screens; the only one born here is
 * Direction's institutional event (P21-DEC-02/05). Month, week and list views
 * with a filter over the seven types (P21-DEC-06) — meetings, period closings
 * and project stages have no source yet and stay empty.
 *
 * What each person sees is decided server-side (`CalendarFeed`); role gates
 * here are UX only. Teachers get a second tab, «Mis horarios», private to them.
 */
export function CalendarPage() {
  const { user } = useAuth()
  const { pathname } = useLocation()
  const onTimetable = pathname === TIMETABLE_PATH && hasTimetable(user)

  return (
    <div className="grid gap-6">
      <PageHeader
        title="Calendario"
        description="Ordena en el tiempo fechas que ya existen en otras pantallas. No reemplaza el calendario del colegio: feriados y horarios quedan en su sistema."
      />
      {hasTimetable(user) && (
        <nav aria-label="Secciones del calendario" className="flex gap-1 border-b">
          {[
            { to: "/calendario", label: "Calendario", active: !onTimetable },
            { to: TIMETABLE_PATH, label: "Mis horarios", active: onTimetable },
          ].map((tab) => (
            <Link
              key={tab.to}
              to={tab.to}
              aria-current={tab.active ? "page" : undefined}
              className={cn(
                "-mb-px border-b-2 px-3 py-2 text-sm font-medium",
                tab.active
                  ? "border-primary text-foreground"
                  : "border-transparent text-muted-foreground hover:text-foreground",
              )}
            >
              {tab.label}
            </Link>
          ))}
        </nav>
      )}
      {onTimetable ? <TimetablePanel /> : <CalendarBoard user={user} />}
    </div>
  )
}

type Dialogs =
  | { kind: "class"; item: CalendarItem }
  | { kind: "event"; item?: CalendarItem }
  | null

/**
 * The feed for a window of any length (the list grows with «Ver más»): split
 * into the chunks the server accepts (at most 62 days each), in order.
 */
async function fetchCalendarRange(from: string, to: string): Promise<CalendarItem[]> {
  const results = await Promise.all(chunkRange(from, to).map((chunk) => fetchCalendar(chunk.from, chunk.to)))
  return results.flat()
}

const VIEW_PARAMS: Record<string, CalendarView> = { mes: "month", semana: "week", lista: "list" }

/**
 * The opening state can come from the URL, so the Inicio can open «today's
 * assessments» directly: `?vista=lista&desde=2026-12-15&hasta=2026-12-15&tipos=assessment`.
 */
function initialState(params: URLSearchParams, today: string) {
  const view = VIEW_PARAMS[params.get("vista") ?? ""] ?? "month"
  const from = dayParam(params.get("desde"))
  const to = dayParam(params.get("hasta"))
  const shown = (params.get("tipos") ?? "")
    .split(",")
    .filter((type): type is CalendarItemType => (calendarItemTypes as readonly string[]).includes(type))
  const anchor = from ?? today
  const list = from && to && to >= from ? { from, to } : initialListRange(anchor)
  return {
    view,
    anchor,
    list,
    hidden: shown.length > 0 ? calendarItemTypes.filter((type) => !shown.includes(type)) : [],
  }
}

function CalendarBoard({ user }: { user: User | null }) {
  const [params] = useSearchParams()
  const [today] = useState(() => toDay(new Date()))
  const [initial] = useState(() => initialState(params, today))
  const [view, setView] = useState<CalendarView>(initial.view)
  const [anchor, setAnchor] = useState(initial.anchor)
  // The list view's own window: starts on the current week and grows with
  // «Ver más» / «Semana anterior», keeping the type filter (PO 12/11).
  const [listRange, setListRange] = useState(initial.list)
  const [hidden, setHidden] = useState<CalendarItemType[]>(initial.hidden)
  const [loaded, setLoaded] = useState<{ key: string; items: CalendarItem[] } | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [dialog, setDialog] = useState<Dialogs>(null)
  const [reloadKey, setReloadKey] = useState(0)
  const { from, to } = view === "list" ? listRange : rangeFor(view, anchor)
  const rangeKey = `${from}|${to}|${reloadKey}`
  // Only what was loaded for the range on screen counts: while a new range
  // loads, «Cargando…» shows instead of the previous range's dates.
  const items = loaded?.key === rangeKey ? loaded.items : null

  // An older response never overwrites a newer range: each load is dropped
  // once the range changes (or the component goes away).
  useEffect(() => {
    let active = true
    fetchCalendarRange(from, to)
      .then((result) => {
        if (!active) return
        setLoaded({ key: `${from}|${to}|${reloadKey}`, items: result })
        setError(null)
      })
      .catch(() => {
        if (active) setError("No pudimos cargar el calendario.")
      })
    return () => {
      active = false
    }
  }, [from, to, reloadKey])

  const reload = () => setReloadKey((key) => key + 1)

  const visible = useMemo(() => (items ?? []).filter((item) => !hidden.includes(item.type)), [items, hidden])
  const byDay = useMemo(() => {
    const map = new Map<string, CalendarItem[]>()
    for (const item of visible) map.set(item.date, [...(map.get(item.date) ?? []), item])
    return map
  }, [visible])

  function toggle(type: CalendarItemType) {
    setHidden((current) => (current.includes(type) ? current.filter((t) => t !== type) : [...current, type]))
  }

  function open(item: CalendarItem) {
    if (item.type === "class" && item.can_edit) setDialog({ kind: "class", item })
    else if (item.type === "institutional" && item.can_edit) setDialog({ kind: "event", item })
  }

  async function remove(item: CalendarItem) {
    try {
      await deleteInstitutionalEvent(item.id)
      reload()
    } catch {
      setError("No pudimos quitar el evento.")
    }
  }

  function saved() {
    setDialog(null)
    reload()
  }

  function previous() {
    if (view === "list") setListRange((range) => ({ from: addDays(range.from, -7), to: range.to }))
    else setAnchor(shiftAnchor(view, anchor, -1))
  }

  function next() {
    if (view === "list") setListRange((range) => ({ from: range.from, to: addDays(range.to, LIST_STEP_DAYS) }))
    else setAnchor(shiftAnchor(view, anchor, 1))
  }

  function goToday() {
    setAnchor(today)
    setListRange(initialListRange(today))
  }

  return (
    <div className="grid gap-4">
      <p className="text-sm text-muted-foreground">{roleDescription(user)}</p>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-2">
          <div role="group" aria-label="Vista" className="inline-flex rounded-md border p-0.5">
            {(
              [
                ["month", "Mes"],
                ["week", "Semana"],
                ["list", "Lista"],
              ] as const
            ).map(([value, label]) => (
              <Button
                key={value}
                type="button"
                size="sm"
                variant={view === value ? "secondary" : "ghost"}
                aria-pressed={view === value}
                onClick={() => setView(value)}
              >
                {label}
              </Button>
            ))}
          </div>
          <div className="inline-flex items-center gap-1">
            <Button
              type="button"
              variant="outline"
              size="icon-sm"
              aria-label={view === "list" ? "Semana anterior" : "Anterior"}
              onClick={previous}
            >
              <ChevronLeft />
            </Button>
            <Button type="button" variant="outline" size="sm" onClick={goToday}>
              Hoy
            </Button>
            <Button
              type="button"
              variant="outline"
              size="icon-sm"
              aria-label={view === "list" ? "Ver más adelante" : "Siguiente"}
              onClick={next}
            >
              <ChevronRight />
            </Button>
          </div>
          <h2 className="text-lg font-semibold">
            {view === "list" ? listTitle(listRange.from, listRange.to) : viewTitle(view, anchor)}
          </h2>
        </div>
        {canManageInstitutionalEvents(user) && (
          <Button type="button" size="sm" onClick={() => setDialog({ kind: "event" })}>
            <Plus /> Evento institucional
          </Button>
        )}
      </div>

      <div role="group" aria-label="Filtrar por tipo" className="flex flex-wrap gap-2">
        {calendarItemTypes.map((type) => {
          const on = !hidden.includes(type)
          return (
            <button
              key={type}
              type="button"
              aria-pressed={on}
              onClick={() => toggle(type)}
              className={cn(
                "rounded-full border px-3 py-1 text-xs font-medium transition-opacity",
                typeTone[type],
                !on && "opacity-40 line-through",
              )}
            >
              {calendarItemTypeLabels[type]}
            </button>
          )
        })}
      </div>

      {error && <p className="text-sm text-destructive">{error}</p>}

      {items === null && !error ? (
        <p className="text-muted-foreground">Cargando…</p>
      ) : view === "month" ? (
        <MonthGrid
          from={from}
          to={to}
          anchor={anchor}
          today={today}
          byDay={byDay}
          onOpen={open}
          onMore={(day) => {
            setView("week")
            setAnchor(day)
          }}
        />
      ) : view === "week" ? (
        <WeekColumns from={from} to={to} today={today} byDay={byDay} onOpen={open} />
      ) : (
        <ListView
          items={visible}
          onOpen={open}
          onRemove={remove}
          onEarlier={previous}
          onMore={next}
        />
      )}

      <p className="text-sm text-muted-foreground">{roleNote(user)}</p>

      {dialog?.kind === "class" && (
        <ClassScheduleDialog item={dialog.item} onClose={() => setDialog(null)} onSaved={saved} />
      )}
      {dialog?.kind === "event" && (
        <InstitutionalEventDialog
          item={dialog.item}
          defaultDate={anchor}
          onClose={() => setDialog(null)}
          onSaved={saved}
        />
      )}
    </div>
  )
}

function ItemChip({ item, onOpen, full = false }: { item: CalendarItem; onOpen: (item: CalendarItem) => void; full?: boolean }) {
  const hour = calendarItemHour(item)
  const what = calendarItemWhat(item)
  const group = item.group ? ` · ${item.group.name}` : ""
  const label = `${calendarItemTypeLabels[item.type]} · ${what}${group}${hour ? ` · ${hour}` : ""}`
  const className = cn(
    "block w-full truncate rounded border px-1.5 py-0.5 text-left text-xs",
    typeTone[item.type],
    full && "whitespace-normal",
  )
  const content = (
    <>
      {item.time && <span className="mr-1 font-semibold tabular-nums">{item.time}</span>}
      {what}
      {full && group}
      {full && item.place && ` · ${item.place}`}
    </>
  )
  const link = calendarItemLink(item)

  if (item.can_edit) {
    return (
      <button type="button" className={className} title={label} aria-label={label} onClick={() => onOpen(item)}>
        {content}
      </button>
    )
  }
  if (link) {
    return (
      <Link to={link} className={className} title={label} aria-label={label}>
        {content}
      </Link>
    )
  }
  return (
    <span className={className} title={label}>
      {content}
    </span>
  )
}

function MonthGrid({
  from,
  to,
  anchor,
  today,
  byDay,
  onOpen,
  onMore,
}: {
  from: string
  to: string
  anchor: string
  today: string
  byDay: Map<string, CalendarItem[]>
  onOpen: (item: CalendarItem) => void
  onMore: (day: string) => void
}) {
  const month = anchor.slice(0, 7)
  return (
    <div className="overflow-x-auto">
      <div className="grid min-w-[42rem] grid-cols-7 border-t border-l">
        {WEEKDAY_SHORT.map((name) => (
          <div key={name} className="border-r border-b bg-muted/50 px-2 py-1 text-xs font-medium text-muted-foreground">
            {name}
          </div>
        ))}
        {daysBetween(from, to).map((day) => {
          const dayItems = byDay.get(day) ?? []
          return (
            <div
              key={day}
              data-testid={`day-${day}`}
              className={cn(
                "grid min-h-24 content-start gap-1 border-r border-b p-1",
                day.slice(0, 7) !== month && "bg-muted/30 text-muted-foreground",
              )}
            >
              <span
                className={cn(
                  "text-xs",
                  day === today && "inline-flex size-5 items-center justify-center rounded-full bg-primary text-primary-foreground",
                )}
              >
                {Number(day.slice(8))}
              </span>
              {dayItems.slice(0, 3).map((item) => (
                <ItemChip key={item.key} item={item} onOpen={onOpen} />
              ))}
              {dayItems.length > 3 && (
                <button
                  type="button"
                  className="text-left text-xs text-primary hover:underline"
                  onClick={() => onMore(day)}
                >
                  +{dayItems.length - 3} más
                </button>
              )}
            </div>
          )
        })}
      </div>
    </div>
  )
}

function WeekColumns({
  from,
  to,
  today,
  byDay,
  onOpen,
}: {
  from: string
  to: string
  today: string
  byDay: Map<string, CalendarItem[]>
  onOpen: (item: CalendarItem) => void
}) {
  return (
    <div className="grid gap-2 md:grid-cols-7">
      {daysBetween(from, to).map((day) => {
        const dayItems = byDay.get(day) ?? []
        return (
          <div
            key={day}
            data-testid={`day-${day}`}
            className={cn("grid content-start gap-1 rounded-md border p-2", day === today && "border-primary")}
          >
            <p className="text-xs font-medium text-muted-foreground">{weekdayDayMonth(day)}</p>
            {dayItems.length === 0 ? (
              <span className="text-xs text-muted-foreground">—</span>
            ) : (
              dayItems.map((item) => <ItemChip key={item.key} item={item} onOpen={onOpen} full />)
            )}
          </div>
        )
      })}
    </div>
  )
}

function ListView({
  items,
  onOpen,
  onRemove,
  onEarlier,
  onMore,
}: {
  items: CalendarItem[]
  onOpen: (item: CalendarItem) => void
  onRemove: (item: CalendarItem) => void
  onEarlier: () => void
  onMore: () => void
}) {
  return (
    <div className="grid gap-3">
      <div>
        <Button type="button" variant="outline" size="sm" onClick={onEarlier}>
          Ver la semana anterior
        </Button>
      </div>
      {items.length === 0 ? (
        <EmptyState icon={CalendarDays} message="No hay fechas en este período." />
      ) : (
        <ListTable items={items} onOpen={onOpen} onRemove={onRemove} />
      )}
      <div>
        <Button type="button" variant="outline" size="sm" onClick={onMore}>
          Ver más
        </Button>
      </div>
    </div>
  )
}

function ListTable({
  items,
  onOpen,
  onRemove,
}: {
  items: CalendarItem[]
  onOpen: (item: CalendarItem) => void
  onRemove: (item: CalendarItem) => void
}) {
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Fecha</TableHead>
          <TableHead>Qué</TableHead>
          <TableHead>Detalle</TableHead>
          <TableHead>Viene de</TableHead>
          <TableHead>
            <span className="sr-only">Acciones</span>
          </TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {items.map((item) => {
          const hour = calendarItemHour(item)
          const link = calendarItemLink(item)
          const detail = [calendarItemWhat(item), item.group?.name, item.place].filter(Boolean).join(" · ")
          return (
            <TableRow key={item.key}>
              <TableCell className="whitespace-nowrap">
                {weekdayDayMonth(item.date)}
                {hour && ` · ${hour}`}
              </TableCell>
              <TableCell>
                <span className={cn("rounded-full border px-2 py-0.5 text-xs", typeTone[item.type])}>
                  {calendarItemTypeLabels[item.type]}
                </span>
              </TableCell>
              <TableCell>{detail}</TableCell>
              <TableCell>{calendarItemSourceLabels[item.type]}</TableCell>
              <TableCell className="text-right whitespace-nowrap">
                {item.type === "class" && item.can_edit && (
                  <Button variant="ghost" size="sm" onClick={() => onOpen(item)}>
                    Hora y salón
                  </Button>
                )}
                {item.type === "institutional" && item.can_edit && (
                  <>
                    <Button variant="ghost" size="sm" onClick={() => onOpen(item)}>
                      Editar
                    </Button>
                    <ConfirmDialog
                      trigger={
                        <Button variant="ghost" size="sm">
                          Quitar
                        </Button>
                      }
                      title="Quitar el evento"
                      description={`«${item.title ?? ""}» deja de aparecer en el calendario de todos.`}
                      confirmLabel="Quitar"
                      onConfirm={() => onRemove(item)}
                    />
                  </>
                )}
                {link && (
                  <Link to={link} className="text-sm text-primary underline-offset-4 hover:underline">
                    Abrir
                  </Link>
                )}
                {item.group && item.type !== "task" && (
                  <Link
                    to={`/clases/${item.group.id}/seguimiento`}
                    className="ml-3 text-sm text-primary underline-offset-4 hover:underline"
                  >
                    Grupo
                  </Link>
                )}
              </TableCell>
            </TableRow>
          )
        })}
      </TableBody>
    </Table>
  )
}
