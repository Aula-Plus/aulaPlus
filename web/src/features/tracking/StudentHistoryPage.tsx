import { useCallback, useEffect, useMemo, useState } from "react"
import { useParams } from "react-router-dom"
import { FileClock } from "lucide-react"
import { Badge, type BadgeTone } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { EmptyState } from "@/components/ui/empty-state"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { PageHeader } from "@/components/ui/page-header"
import { SingleSelect } from "@/components/ui/single-select"
import {
  accommodationCategoryLabels,
  alertOutcomeLabels,
  alertSeverityLabels,
  alertTypeLabels,
  auditActionLabels,
  auditOriginLabels,
  roleLabels,
  studentFileSectionLabels,
  type AccommodationCategory,
  type AlertOutcome,
  type AlertSeverity,
  type AlertType,
  type AuditAction,
  type Role,
  type StudentFileSection,
  type StudentHistoryChange,
  type StudentHistoryEntry,
  type StudentHistoryFilters,
  type StudentHistoryPage as HistoryPage,
} from "@/types"
import * as trackingApi from "./trackingApi"

const ALL = ""

/** What each kind of element is called in the history. */
const elementLabels: Record<string, string> = {
  student: "Ficha del alumno",
  accommodation: "Ajuste",
  accommodation_instance_override: "Desactivación de un ajuste en una evaluación",
  barrier: "Barrera",
  barrier_accommodation: "Vínculo entre ajuste y barrera",
  comment: "Observación",
  technical_report: "Informe",
  scheduled_follow_up: "Seguimiento programado",
  alert: "Alerta",
}

/** Spanish names of the fields the server reports as changed. */
const fieldLabels: Record<string, string> = {
  full_name: "Nombre completo",
  photo_url: "Foto",
  birth_date: "Fecha de nacimiento",
  enrollment_year: "Año de ingreso",
  has_therapeutic_companion: "Acompañante terapéutico",
  group: "Clase",
  learning_profile: "Perfil de aprendizaje",
  individual_profile: "Perfil individual",
  tracking_notes: "Notas de seguimiento",
  related_documents: "Documentos relacionados",
  team_summary_strengths: "Fortalezas",
  team_summary_difficulties: "Qué le cuesta",
  team_summary_adjustments: "Ajustes sugeridos",
  team_summary_confirmed_at: "Confirmado el",
  type: "Tipo",
  category: "Categoría",
  description: "Descripción",
  focus_area: "Área",
  active: "Activo",
  requires_external_approval: "Requiere aprobación externa",
  approved: "Aprobado",
  llm_rule: "Regla para el asistente",
  assessment_id: "Evaluación",
  reason: "Motivo",
  coping_strategy: "Estrategia",
  accommodation: "Ajuste vinculado",
  validated: "Validado",
  content: "Texto",
  visible_to: "Visible para",
  author_only: "Solo para quien la escribió",
  summary: "Resumen",
  attachments: "Adjuntos",
  document_url: "Archivo del informe",
  due_date: "Fecha",
  resolved: "Resuelto",
  resolution_note: "Nota de cierre",
  severity: "Gravedad",
  outcome: "Salida elegida",
  due_on: "Fecha límite",
}

const actionTone: Record<AuditAction, BadgeTone> = {
  created: "success",
  updated: "info",
  deleted: "danger",
}

const actionFilterLabels: Record<AuditAction, string> = {
  created: "Altas",
  updated: "Modificaciones",
  deleted: "Bajas",
}

interface FilterState {
  userId: string
  section: string
  action: string
  from: string
  to: string
}

const EMPTY_FILTERS: FilterState = { userId: ALL, section: ALL, action: ALL, from: "", to: "" }

/**
 * Change history of a student's file ("historial de cambios", ClickUp
 * 86e3qkwmc): every addition, modification and removal, filterable by person,
 * date range, section and action. Server-side filtering and pagination
 * (`?page=N` → `{ data, meta, actors }`); the server also drops every entry
 * the viewer could not read in its original place, so nothing here needs to
 * re-check permissions. Only changes appear — opening the file writes nothing.
 */
export function StudentHistoryPage() {
  const { id } = useParams<{ id: string }>()
  const studentId = Number(id)

  const [page, setPage] = useState(1)
  const [filters, setFilters] = useState<FilterState>(EMPTY_FILTERS)
  const [history, setHistory] = useState<HistoryPage | null>(null)
  const [error, setError] = useState<string | null>(null)
  /**
   * Set from event handlers only (never synchronously inside the effect, per
   * react/set-state-in-effect) while a page/filter request is in flight.
   */
  const [pending, setPending] = useState(false)

  const query = useMemo(() => toQuery(filters), [filters])

  const load = useCallback(
    (nextPage: number, nextQuery: StudentHistoryFilters) => {
      return trackingApi
        .fetchStudentHistory(studentId, nextPage, nextQuery)
        .then((data) => {
          setHistory(data)
          setError(null)
        })
        .catch(() => setError("No pudimos cargar el historial de cambios."))
        .finally(() => setPending(false))
    },
    [studentId],
  )

  useEffect(() => {
    load(page, query)
  }, [load, page, query])

  function updateFilter(patch: Partial<FilterState>) {
    setPending(true)
    setFilters((current) => ({ ...current, ...patch }))
    setPage(1)
  }

  function clearFilters() {
    setPending(true)
    setFilters(EMPTY_FILTERS)
    setPage(1)
  }

  function goToPage(next: number) {
    if (next === page) return
    setPending(true)
    setPage(next)
  }

  const actorOptions = useMemo(
    () => [
      { value: ALL, label: "Todas las personas" },
      ...(history?.actors ?? []).map((actor) => ({
        value: String(actor.id),
        label: actor.role ? `${actor.name} (${roleLabels[actor.role]})` : actor.name,
      })),
    ],
    [history?.actors],
  )

  const sectionOptions = [
    { value: ALL, label: "Todas las secciones" },
    ...(Object.keys(studentFileSectionLabels) as StudentFileSection[]).map((section) => ({
      value: section,
      label: studentFileSectionLabels[section],
    })),
  ]

  const actionOptions = [
    { value: ALL, label: "Todas las acciones" },
    ...(Object.keys(actionFilterLabels) as AuditAction[]).map((action) => ({
      value: action,
      label: actionFilterLabels[action],
    })),
  ]

  const hasFilters =
    filters.userId !== ALL ||
    filters.section !== ALL ||
    filters.action !== ALL ||
    filters.from !== "" ||
    filters.to !== ""

  return (
    <div className="grid gap-6">
      <PageHeader
        backTo={`/alumnos/${studentId}/seguimiento`}
        backLabel="Volver al perfil del alumno"
        title="Historial de cambios"
        description="Altas, modificaciones y bajas en la ficha del alumno. Abrir la ficha o consultar un documento no queda registrado."
      />

      <Card>
        <CardContent className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
          <div className="grid gap-1.5">
            <Label htmlFor="history-person">Persona</Label>
            <SingleSelect
              id="history-person"
              options={actorOptions}
              value={filters.userId}
              onChange={(value) => updateFilter({ userId: value })}
              placeholder="Todas las personas"
            />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="history-from">Desde</Label>
            <Input
              id="history-from"
              type="date"
              value={filters.from}
              max={filters.to || undefined}
              onChange={(event) => updateFilter({ from: event.target.value })}
            />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="history-to">Hasta</Label>
            <Input
              id="history-to"
              type="date"
              value={filters.to}
              min={filters.from || undefined}
              onChange={(event) => updateFilter({ to: event.target.value })}
            />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="history-section">Sección</Label>
            <SingleSelect
              id="history-section"
              options={sectionOptions}
              value={filters.section}
              onChange={(value) => updateFilter({ section: value })}
              placeholder="Todas las secciones"
            />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="history-action">Acción</Label>
            <SingleSelect
              id="history-action"
              options={actionOptions}
              value={filters.action}
              onChange={(value) => updateFilter({ action: value })}
              placeholder="Todas las acciones"
            />
          </div>
          {hasFilters && (
            <div className="sm:col-span-2 lg:col-span-5">
              <Button type="button" variant="outline" size="sm" onClick={clearFilters}>
                Limpiar filtros
              </Button>
            </div>
          )}
        </CardContent>
      </Card>

      {error ? (
        <p className="text-sm text-destructive">{error}</p>
      ) : !history ? (
        <p className="text-muted-foreground">Cargando…</p>
      ) : history.data.length === 0 ? (
        <EmptyState
          icon={FileClock}
          message={
            hasFilters
              ? "No hay cambios que coincidan con los filtros."
              : "Todavía no hay cambios registrados en esta ficha."
          }
        />
      ) : (
        <>
          <ul className="grid gap-3" aria-label="Cambios registrados">
            {history.data.map((entry) => (
              <HistoryEntry key={entry.id} entry={entry} />
            ))}
          </ul>

          <div className="flex flex-wrap items-center justify-between gap-2 text-sm text-muted-foreground">
            <span>
              Página {history.meta.current_page} de {history.meta.last_page} ({history.meta.total}{" "}
              {history.meta.total === 1 ? "cambio" : "cambios"})
            </span>
            <div className="flex gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => goToPage(Math.max(1, history.meta.current_page - 1))}
                disabled={pending || history.meta.current_page <= 1}
              >
                Anterior
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => goToPage(Math.min(history.meta.last_page, history.meta.current_page + 1))}
                disabled={pending || history.meta.current_page >= history.meta.last_page}
              >
                Siguiente
              </Button>
            </div>
          </div>
        </>
      )}
    </div>
  )
}

/**
 * The filter form keeps plain date inputs (the viewer's local day); the API
 * gets the matching instants so "Desde/Hasta" mean the viewer's whole day,
 * not the server's UTC day.
 */
function toQuery(filters: FilterState): StudentHistoryFilters {
  const query: StudentHistoryFilters = {}
  if (filters.userId !== ALL) query.user_id = Number(filters.userId)
  if (filters.section !== ALL) query.section = filters.section as StudentFileSection
  if (filters.action !== ALL) query.action = filters.action as AuditAction
  const from = localDayBoundary(filters.from, false)
  const to = localDayBoundary(filters.to, true)
  if (from) query.from = from
  if (to) query.to = to
  return query
}

function localDayBoundary(value: string, endOfDay: boolean): string | undefined {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (!match) return undefined
  const date = endOfDay
    ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]), 23, 59, 59, 999)
    : new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
  return date.toISOString()
}

function HistoryEntry({ entry }: { entry: StudentHistoryEntry }) {
  const who = entry.actor
    ? entry.actor.role
      ? `${entry.actor.name} · ${roleLabels[entry.actor.role]}`
      : entry.actor.name
    : auditOriginLabels.system

  return (
    <li>
      <Card className="py-0">
        <CardContent className="grid gap-3 p-4 text-sm">
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={actionTone[entry.action]}>{auditActionLabels[entry.action]}</Badge>
            {entry.section && <Badge tone="neutral">{studentFileSectionLabels[entry.section]}</Badge>}
            <span className="font-medium">{elementText(entry)}</span>
          </div>
          <p className="text-muted-foreground">
            <span className="text-foreground">{who}</span> · {formatDateTime(entry.created_at)}
          </p>
          <ChangeList entry={entry} />
        </CardContent>
      </Card>
    </li>
  )
}

function ChangeList({ entry }: { entry: StudentHistoryEntry }) {
  if (entry.changes.length === 0) return null

  const heading =
    entry.action === "created" ? "Se agregó" : entry.action === "deleted" ? "Se quitó" : "Cambios"

  return (
    <div className="grid gap-1">
      <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">{heading}</p>
      <dl className="grid gap-1.5">
        {entry.changes.map((change) => (
          <div key={change.field} className="grid gap-0.5 sm:grid-cols-[12rem_1fr] sm:gap-3">
            <dt className="text-muted-foreground">{fieldLabels[change.field] ?? change.field}</dt>
            <dd className="break-words whitespace-pre-wrap">
              <ChangeValue entry={entry} change={change} />
            </dd>
          </div>
        ))}
      </dl>
    </div>
  )
}

function ChangeValue({ entry, change }: { entry: StudentHistoryEntry; change: StudentHistoryChange }) {
  if (change.redacted) {
    return <span className="text-muted-foreground italic">Modificado (contenido no disponible)</span>
  }

  if (entry.action === "created") {
    return <>{formatValue(entry, change.field, change.after)}</>
  }

  if (entry.action === "deleted") {
    return <>{formatValue(entry, change.field, change.before)}</>
  }

  return (
    <span>
      <span className="sr-only">Valor anterior: </span>
      <span className="text-muted-foreground line-through decoration-muted-foreground/50">
        {formatValue(entry, change.field, change.before)}
      </span>
      <span aria-hidden="true"> → </span>
      <span className="sr-only">. Valor nuevo: </span>
      <span>{formatValue(entry, change.field, change.after)}</span>
    </span>
  )
}

function elementText(entry: StudentHistoryEntry): string {
  const kind = elementLabels[entry.element.type] ?? entry.element.type
  if (entry.element.type === "student") return kind
  return entry.element.label ? `${kind}: ${entry.element.label}` : `${kind} #${entry.element.id}`
}

function formatValue(entry: StudentHistoryEntry, field: string, value: unknown): string {
  if (value === null || value === undefined || value === "") return "—"
  if (typeof value === "boolean") return value ? "Sí" : "No"

  if (typeof value === "string") {
    if (field === "category" && value in accommodationCategoryLabels) {
      return accommodationCategoryLabels[value as AccommodationCategory]
    }
    if (entry.element.type === "alert") {
      if (field === "type" && value in alertTypeLabels) return alertTypeLabels[value as AlertType]
      if (field === "severity" && value in alertSeverityLabels) {
        return alertSeverityLabels[value as AlertSeverity]
      }
      if (field === "outcome" && value in alertOutcomeLabels) {
        return alertOutcomeLabels[value as AlertOutcome]
      }
    }
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return formatDate(value)
    if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(value)) return formatDateTime(value)
    return value
  }

  if (Array.isArray(value)) {
    if (field === "visible_to") {
      return value.map((role) => roleLabels[role as Role] ?? String(role)).join(", ")
    }
    return value.map((item) => (typeof item === "string" ? item : JSON.stringify(item))).join(", ")
  }

  if (typeof value === "object") return JSON.stringify(value)
  return String(value)
}

function formatDate(value: string): string {
  const [year, month, day] = value.split("-").map(Number)
  return new Date(year, month - 1, day).toLocaleDateString("es", {
    day: "2-digit",
    month: "short",
    year: "numeric",
  })
}

function formatDateTime(iso: string | null): string {
  if (!iso) return "—"
  const date = new Date(iso)
  return Number.isNaN(date.getTime())
    ? "—"
    : date.toLocaleString("es", {
        day: "2-digit",
        month: "short",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      })
}
