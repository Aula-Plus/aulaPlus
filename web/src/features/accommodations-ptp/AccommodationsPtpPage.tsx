import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import { Link, useSearchParams } from "react-router-dom"
import { Plus } from "lucide-react"
import { Badge, type BadgeTone } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { ConfirmDialog } from "@/components/ui/confirm-dialog"
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { EmptyState } from "@/components/ui/empty-state"
import { Label } from "@/components/ui/label"
import { PageHeader } from "@/components/ui/page-header"
import { SectionCard } from "@/components/ui/section-card"
import { SingleSelect } from "@/components/ui/single-select"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { useAuth } from "@/features/auth/AuthContext"
import { fetchGroups } from "@/features/groups/groupsApi"
import { fetchStudents } from "@/features/students/studentsApi"
import { AccommodationFormDialog } from "@/features/tracking/AccommodationFormDialog"
import { ProposalItem } from "@/features/tracking/AccommodationProposalsSection"
import { describeAccommodationScope } from "@/features/tracking/accommodationScope"
import * as trackingApi from "@/features/tracking/trackingApi"
import { isDirector, isPsychopedagogue } from "@/lib/permissions"
import { getCurrentSchoolYear } from "@/lib/schoolYear"
import { formatShortDate } from "@/lib/utils"
import {
  accommodationCategoryLabels,
  accommodationInboxStatusLabels,
  type Accommodation,
  type AccommodationCategory,
  type AccommodationInboxRow,
  type AccommodationInboxStatus,
  type AnnualReviewRow,
  type Group,
  type InboxReportProposals,
  type Student,
} from "@/types"
import {
  fetchAccommodationInbox,
  fetchAnnualReviews,
  markAnnualReview,
  type AccommodationInbox,
  type InboxFilters,
} from "./accommodationsPtpApi"

type Tab = "adjustments" | "annual-review"

const tabLabels: Record<Tab, string> = {
  adjustments: "Ajustes",
  "annual-review": "Revisión anual",
}

/** `?solapa=` value of each tab: the Inicio task opens «Revisión anual» directly. */
const tabParams: Record<Tab, string> = {
  adjustments: "ajustes",
  "annual-review": "revision-anual",
}

const statusTone: Record<AccommodationInboxStatus, BadgeTone> = {
  in_force: "success",
  pending: "warning",
  returned: "info",
  rejected: "danger",
  inactive: "neutral",
}

const STATUS_ORDER: AccommodationInboxStatus[] = ["in_force", "pending", "returned", "rejected", "inactive"]
const CATEGORY_ORDER: AccommodationCategory[] = ["access", "content", "criteria"]

/**
 * Screen 06 «Ajustes y PTP» (ClickUp 1dfd3mc4bgc, first delivery): the place
 * where psychopedagogy registers, confirms and reviews every accommodation of
 * the school, instead of a sum of student files.
 *
 * Only psychopedagogy enters (P06-DEC-07). Teachers and direction get the
 * screen's own note pointing to where they see accommodations (P06-AJU-03/04);
 * the server answers 403 to them anyway (AccommodationPolicy::viewInbox).
 *
 * This delivery has the «Ajustes» and «Revisión anual» tabs. «PTP» comes in a
 * second delivery and «Lo que pasó al año nuevo» after closing the year
 * (screen 26), as agreed in the task.
 */
export function AccommodationsPtpPage() {
  const { user } = useAuth()
  if (isPsychopedagogue(user)) return <AccommodationsPtpScreen />
  return <RoleNote director={isDirector(user)} />
}

/**
 * P06-AJU-03 / P06-AJU-04, without what does not exist yet (the PTP, and the
 * teacher proposing from the file): it tells each role where it does see the
 * accommodations, not what it can't do here.
 */
function RoleNote({ director }: { director: boolean }) {
  return (
    <div className="grid gap-6">
      <PageHeader title="Ajustes y PTP" />
      <SectionCard title="Esta bandeja es la mesa de trabajo de Psicopedagogía">
        <p className="text-sm text-muted-foreground">
          {director
            ? "Los ajustes de cada alumno los ves completos en su ficha, con quién los confirmó y de dónde salieron. Para proponer un ajuste, usá «Proponer ajuste» en la ficha: tu propuesta le llega a Psicopedagogía acá y como seguimiento vinculado."
            : "Los ajustes de tus alumnos los ves en la ficha de cada uno (los de tu materia y los generales) y, en el perfil del grupo, agregados por tipo."}
        </p>
        <div className="mt-4">
          <Button asChild>
            <Link to="/">Volver al inicio</Link>
          </Button>
        </div>
      </SectionCard>
    </div>
  )
}

function AccommodationsPtpScreen() {
  const [searchParams, setSearchParams] = useSearchParams()
  const tab: Tab = searchParams.get("solapa") === tabParams["annual-review"] ? "annual-review" : "adjustments"
  // Bumped after any write from this screen, so both tabs refetch.
  const [refreshKey, setRefreshKey] = useState(0)
  const [pickingStudent, setPickingStudent] = useState(false)
  const [newForStudent, setNewForStudent] = useState<number | null>(null)
  const refresh = useCallback(() => setRefreshKey((current) => current + 1), [])

  return (
    <div className="grid gap-6">
      <PageHeader
        title="Ajustes y PTP"
        description="Todos los ajustes razonables del colegio, en un solo lugar."
      >
        <Button type="button" onClick={() => setPickingStudent(true)}>
          <Plus aria-hidden="true" />
          Nuevo ajuste
        </Button>
      </PageHeader>

      <div role="tablist" aria-label="Solapas de Ajustes y PTP" className="flex flex-wrap gap-2">
        {(Object.keys(tabLabels) as Tab[]).map((key) => (
          <button
            key={key}
            type="button"
            role="tab"
            aria-selected={tab === key}
            onClick={() => setSearchParams({ solapa: tabParams[key] })}
            className={
              "rounded-md px-3 py-1.5 text-sm font-medium transition-colors " +
              (tab === key ? "bg-primary text-primary-foreground" : "bg-muted text-muted-foreground")
            }
          >
            {tabLabels[key]}
          </button>
        ))}
      </div>

      {tab === "adjustments" ? (
        <AdjustmentsTab refreshKey={refreshKey} onChanged={refresh} />
      ) : (
        <AnnualReviewTab refreshKey={refreshKey} />
      )}

      {pickingStudent && (
        <StudentPickerDialog
          onOpenChange={setPickingStudent}
          onPick={(studentId) => {
            setPickingStudent(false)
            setNewForStudent(studentId)
          }}
        />
      )}
      {newForStudent !== null && (
        <AccommodationFormDialog
          open
          onOpenChange={(open) => {
            if (!open) setNewForStudent(null)
          }}
          studentId={newForStudent}
          onSaved={refresh}
          withBarrier
        />
      )}
    </div>
  )
}

/** «Nuevo ajuste» starts by choosing the student it is for. */
function StudentPickerDialog({
  onOpenChange,
  onPick,
}: {
  onOpenChange: (open: boolean) => void
  onPick: (studentId: number) => void
}) {
  const [students, setStudents] = useState<Student[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [selected, setSelected] = useState("")
  const currentYear = getCurrentSchoolYear()

  useEffect(() => {
    fetchStudents().then(
      (list) => {
        setStudents(list)
        setError(null)
      },
      () => setError("No pudimos cargar los alumnos."),
    )
  }, [])

  const options = useMemo(
    () =>
      (students ?? []).map((student) => {
        const group = student.groups.find((candidate) => candidate.school_year === currentYear)
        return {
          value: String(student.id),
          label: group ? `${student.full_name} — ${group.name}` : student.full_name,
        }
      }),
    [students, currentYear],
  )

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>Nuevo ajuste</DialogTitle>
        </DialogHeader>
        <div className="grid gap-2">
          <Label htmlFor="new-accommodation-student">¿Para qué alumno?</Label>
          {error ? (
            <p className="text-sm text-destructive">{error}</p>
          ) : (
            <SingleSelect
              id="new-accommodation-student"
              options={options}
              value={selected}
              onChange={setSelected}
              placeholder={students ? "Elegí un alumno" : "Cargando…"}
              searchPlaceholder="Buscar alumno…"
              disabled={!students}
            />
          )}
        </div>
        <div className="flex gap-2">
          <Button type="button" disabled={!selected} onClick={() => onPick(Number(selected))}>
            Seguir
          </Button>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            Cancelar
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

function AdjustmentsTab({ refreshKey, onChanged }: { refreshKey: number; onChanged: () => void }) {
  const [status, setStatus] = useState("")
  const [groupId, setGroupId] = useState("")
  const [category, setCategory] = useState("")
  const [groups, setGroups] = useState<Group[]>([])
  const [rows, setRows] = useState<AccommodationInboxRow[] | null>(null)
  const [reports, setReports] = useState<InboxReportProposals[]>([])
  const [error, setError] = useState<string | null>(null)
  const [version, setVersion] = useState(0)
  const [editing, setEditing] = useState<AccommodationInboxRow | null>(null)
  const [page, setPage] = useState(1)
  const [meta, setMeta] = useState<AccommodationInbox["meta"] | null>(null)
  // Only the latest request may update the list: a slower answer to an older
  // filter combination is dropped instead of overwriting the current one.
  const requestId = useRef(0)

  useEffect(() => {
    fetchGroups()
      .then(setGroups)
      .catch(() => setGroups([]))
  }, [])

  const load = useCallback(() => {
    const filters: InboxFilters = {
      ...(status ? { status: status as AccommodationInboxStatus } : {}),
      ...(groupId ? { group_id: Number(groupId) } : {}),
      ...(category ? { category: category as InboxFilters["category"] } : {}),
      ...(page > 1 ? { page } : {}),
    }
    const id = ++requestId.current
    fetchAccommodationInbox(filters).then(
      (inbox) => {
        if (id !== requestId.current) return
        setRows(inbox.data)
        setMeta(inbox.meta)
        setReports(inbox.report_proposals)
        setVersion((current) => current + 1)
        setError(null)
      },
      () => {
        if (id !== requestId.current) return
        setError("No pudimos cargar los ajustes del colegio.")
      },
    )
  }, [status, groupId, category, page])

  useEffect(() => {
    return () => {
      // Drop any answer that arrives after the tab is gone.
      requestId.current += 1
    }
  }, [])

  function changeFilter(set: (value: string) => void) {
    return (value: string) => {
      set(value)
      setPage(1)
    }
  }

  useEffect(() => {
    load()
  }, [load, refreshKey])

  function changed() {
    onChanged()
  }

  return (
    <div className="grid gap-6">
      <SectionCard title="Filtros">
        <div className="grid gap-4 sm:grid-cols-3">
          <div className="grid gap-2">
            <Label htmlFor="inbox-status">Estado</Label>
            <SingleSelect
              id="inbox-status"
              options={[
                { value: "", label: "Todos" },
                ...STATUS_ORDER.map((value) => ({ value, label: accommodationInboxStatusLabels[value] })),
              ]}
              value={status}
              onChange={changeFilter(setStatus)}
              placeholder="Todos"
            />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="inbox-group">Grupo</Label>
            <SingleSelect
              id="inbox-group"
              options={[
                { value: "", label: "Todos" },
                ...groups.map((group) => ({ value: String(group.id), label: group.name })),
              ]}
              value={groupId}
              onChange={changeFilter(setGroupId)}
              placeholder="Todos"
            />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="inbox-category">Tipo de ajuste</Label>
            <SingleSelect
              id="inbox-category"
              options={[
                { value: "", label: "Todos" },
                ...CATEGORY_ORDER.map((value) => ({ value, label: accommodationCategoryLabels[value] })),
                { value: "none", label: "Sin tipo" },
              ]}
              value={category}
              onChange={changeFilter(setCategory)}
              placeholder="Todos"
            />
          </div>
        </div>
      </SectionCard>

      <SectionCard title="Ajustes del colegio">
        {error ? (
          <p className="text-sm text-destructive">{error}</p>
        ) : !rows ? (
          <p className="text-muted-foreground" role="status">
            Cargando…
          </p>
        ) : rows.length === 0 && reports.length === 0 ? (
          <EmptyState message="No hay ajustes con estos filtros." />
        ) : (
          <ul className="grid gap-3">
            {reports.map((report) => (
              <ReportRow key={`report-${report.report_id}`} report={report} />
            ))}
            {rows.map((row) => (
              <InboxRow
                key={`${row.id}:${version}`}
                row={row}
                onEdit={() => setEditing(row)}
                onChanged={changed}
                onStale={load}
              />
            ))}
          </ul>
        )}
        {meta && meta.last_page > 1 && (
          <div className="mt-4 flex flex-wrap items-center justify-between gap-2 text-sm text-muted-foreground">
            <span>
              Página {meta.current_page} de {meta.last_page} ({meta.total}{" "}
              {meta.total === 1 ? "ajuste" : "ajustes"})
            </span>
            <div className="flex gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => setPage(Math.max(1, meta.current_page - 1))}
                disabled={meta.current_page <= 1}
              >
                Anterior
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => setPage(Math.min(meta.last_page, meta.current_page + 1))}
                disabled={meta.current_page >= meta.last_page}
              >
                Siguiente
              </Button>
            </div>
          </div>
        )}
        <p className="mt-4 text-xs text-muted-foreground">
          Cada ajuste dice su nombre, su tipo, a qué materias se aplica, de dónde salió, quién lo
          confirmó y, si tiene, a qué barrera responde. Ninguno tiene fecha de fin: tiene «vigente
          desde» y se revisa una vez por año lectivo.
        </p>
      </SectionCard>

      {editing && (
        <AccommodationFormDialog
          open
          onOpenChange={(open) => {
            if (!open) setEditing(null)
          }}
          studentId={editing.student.id}
          accommodation={editing}
          onSaved={changed}
        />
      )}
    </div>
  )
}

function studentLine(student: { name: string }, group: { name: string } | null): string {
  return group ? `${student.name} — ${group.name}` : student.name
}

function ReportRow({ report }: { report: InboxReportProposals }) {
  return (
    <li className="grid gap-2 rounded-md border p-3 text-sm">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <p className="font-medium">
          {studentLine(report.student, report.group)} · Informe cargado el {formatShortDate(report.uploaded_on)}
        </p>
        <Badge tone="warning">Pendiente de confirmar</Badge>
      </div>
      <p className="text-muted-foreground">
        Aula+ interpretó{" "}
        {report.pending_count === 1 ? "1 ajuste posible" : `${report.pending_count} ajustes posibles`};
        ninguno quedó guardado todavía.
      </p>
      <div>
        <Button asChild size="sm" variant="outline">
          <Link to={`/alumnos/${report.student.id}/seguimiento`}>Revisar en la ficha</Link>
        </Button>
      </div>
    </li>
  )
}

/** The same body the form sends, switched off: «Dar de baja» from the inbox. */
function switchedOffInput(row: Accommodation, category: AccommodationCategory) {
  return {
    type: row.type,
    description: row.description ?? "",
    focus_area: row.focus_area ?? "",
    category,
    active: false,
    subject_ids: row.subjects.map((subject) => subject.id),
    assessment_types: row.assessment_types ?? [],
  }
}

function InboxRow({
  row,
  onEdit,
  onChanged,
  onStale,
}: {
  row: AccommodationInboxRow
  onEdit: () => void
  onChanged: () => void
  onStale: () => void
}) {
  const [switchingOff, setSwitchingOff] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const isOpenProposal = row.inbox_status === "pending" || row.inbox_status === "returned"

  async function switchOff(category: AccommodationCategory) {
    setSwitchingOff(true)
    setError(null)
    try {
      await trackingApi.updateAccommodation(row.id, switchedOffInput(row, category))
      onChanged()
    } catch {
      setError("No pudimos dar de baja el ajuste.")
    } finally {
      setSwitchingOff(false)
    }
  }

  const meta = [
    describeAccommodationScope(row),
    row.barriers.length > 0
      ? `Barrera: ${row.barriers.map((barrier) => barrier.description).join(", ")}`
      : "Sin barrera indicada",
    row.origin ? `Origen: ${row.origin}` : null,
    row.confirmed_by_name ? `Confirmó ${row.confirmed_by_name}` : null,
    row.inbox_status === "inactive" && row.deactivated_on
      ? `De baja desde el ${formatShortDate(row.deactivated_on)}`
      : null,
  ].filter(Boolean)

  if (isOpenProposal) {
    return (
      <li className="grid gap-2">
        <p className="text-sm font-medium">
          {studentLine(row.student, row.group)}
          {row.proposed_by_name && (
            <span className="font-normal text-muted-foreground">
              {" "}
              · Propuesta de Dirección por {row.proposed_by_name}
            </span>
          )}
        </p>
        <ul>
          <ProposalItem proposal={row} onEdit={onEdit} onStepDone={onChanged} onStale={onStale} />
        </ul>
      </li>
    )
  }

  return (
    <li className="grid gap-2 rounded-md border p-3 text-sm">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <p className="font-medium">
            {studentLine(row.student, row.group)} · {row.type}
          </p>
          <Badge tone="neutral" className="mt-1">
            {row.category ? accommodationCategoryLabels[row.category] : "Sin tipo"}
          </Badge>
        </div>
        <Badge tone={statusTone[row.inbox_status]}>{accommodationInboxStatusLabels[row.inbox_status]}</Badge>
      </div>
      {row.description && <p>{row.description}</p>}
      <p className="text-muted-foreground">{meta.join(" · ")}</p>
      {row.inbox_status === "rejected" && row.review_note && (
        <p className="rounded-md bg-muted/50 p-2 whitespace-pre-wrap">
          <span className="font-medium">Motivo del rechazo:</span> {row.review_note}
        </p>
      )}
      {row.inbox_status !== "rejected" && (
        <div className="flex flex-wrap gap-2">
          <Button type="button" size="sm" variant="outline" onClick={onEdit}>
            Editar
          </Button>
          {row.inbox_status === "in_force" && row.category && (
            <ConfirmDialog
              trigger={
                <Button type="button" size="sm" variant="outline" disabled={switchingOff}>
                  Dar de baja
                </Button>
              }
              title="Dar de baja el ajuste"
              description={`«${row.type}» de ${row.student.name} deja de aplicarse desde hoy. Queda registrado quién y cuándo.`}
              confirmLabel="Dar de baja"
              isConfirming={switchingOff}
              onConfirm={() => void switchOff(row.category as AccommodationCategory)}
            />
          )}
          <Button asChild size="sm" variant="ghost">
            <Link to={`/alumnos/${row.student.id}/seguimiento`}>Abrir la ficha</Link>
          </Button>
        </div>
      )}
      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
    </li>
  )
}

function AnnualReviewTab({ refreshKey }: { refreshKey: number }) {
  const [rows, setRows] = useState<AnnualReviewRow[] | null>(null)
  const [schoolYear, setSchoolYear] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [marking, setMarking] = useState<number | null>(null)
  const [markError, setMarkError] = useState<string | null>(null)

  useEffect(() => {
    fetchAnnualReviews().then(
      (result) => {
        setRows(result.rows)
        setSchoolYear(result.schoolYear)
        setError(null)
      },
      () => setError("No pudimos cargar la revisión anual."),
    )
  }, [refreshKey])

  async function mark(studentId: number) {
    setMarking(studentId)
    setMarkError(null)
    try {
      const review = await markAnnualReview(studentId)
      setRows(
        (current) =>
          current?.map((row) =>
            row.student.id === studentId
              ? { ...row, last_review: review, reviewed_this_year: true }
              : row,
          ) ?? null,
      )
    } catch {
      setMarkError("No pudimos marcar la revisión.")
    } finally {
      setMarking(null)
    }
  }

  return (
    <SectionCard title={schoolYear ? `Revisión anual ${schoolYear}` : "Revisión anual"}>
      {error ? (
        <p className="text-sm text-destructive">{error}</p>
      ) : !rows ? (
        <p className="text-muted-foreground" role="status">
          Cargando…
        </p>
      ) : rows.length === 0 ? (
        <EmptyState message="No hay alumnos con ajustes vigentes." />
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Alumno</TableHead>
              <TableHead>Grupo</TableHead>
              <TableHead>Tiene</TableHead>
              <TableHead>Última revisión</TableHead>
              <TableHead>
                <span className="sr-only">Acción</span>
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((row) => (
              <TableRow key={row.student.id}>
                <TableCell>
                  <Link className="underline-offset-2 hover:underline" to={`/alumnos/${row.student.id}/seguimiento`}>
                    {row.student.name}
                  </Link>
                </TableCell>
                <TableCell>{row.group?.name ?? "Sin grupo"}</TableCell>
                <TableCell>
                  {row.accommodations_in_force_count === 1
                    ? "1 ajuste"
                    : `${row.accommodations_in_force_count} ajustes`}
                </TableCell>
                <TableCell>
                  {row.last_review
                    ? row.reviewed_this_year
                      ? `${formatShortDate(row.last_review.reviewed_on)}${row.last_review.reviewed_by_name ? ` · ${row.last_review.reviewed_by_name}` : ""}`
                      : `Última: ${formatShortDate(row.last_review.reviewed_on)}`
                    : "Nunca"}
                </TableCell>
                <TableCell>
                  {!row.reviewed_this_year && (
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      disabled={marking === row.student.id}
                      onClick={() => void mark(row.student.id)}
                    >
                      Marcar revisado hoy
                    </Button>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
      {markError && (
        <p role="alert" className="mt-2 text-sm text-destructive">
          {markError}
        </p>
      )}
      <p className="mt-4 text-xs text-muted-foreground">
        Nada se da de baja solo. Cuando un alumno con ajustes vigentes no tiene la revisión marcada en
        el año lectivo, aparece como tarea en tu Inicio, y desaparece cuando se marca.
      </p>
    </SectionCard>
  )
}
