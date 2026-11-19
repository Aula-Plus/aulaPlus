import { useCallback, useEffect, useRef, useState, type ReactNode } from "react"
import { Plus } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { EmptyState } from "@/components/ui/empty-state"
import { SectionCard } from "@/components/ui/section-card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { formatShortDate } from "@/lib/utils"
import type { MeetingSheet, MeetingSheetRow } from "@/types"
import { confirmSheet, fetchSheet, fetchSheets } from "./psychopedagogySpaceApi"
import { SheetEditorDialog } from "./SheetEditorDialog"
import { useSheetFormOptions } from "./useSheetFormOptions"
import { observationScopeLabels } from "./spaceText"

/**
 * «Planillas» (P10-PLA-02…06). Psicopedagogía creates them, reviews the split
 * and confirms it («Confirmar el reparto») or corrects it first («Editar
 * antes»). Dirección only lists that each meeting existed: date, type and
 * group or «Individual» — the server sends nothing else to it.
 */
export function SheetsTab({ works }: { works: boolean }) {
  const [sheets, setSheets] = useState<MeetingSheetRow[] | null>(null)
  const [page, setPage] = useState({ current: 1, last: 1 })
  const [loadingMore, setLoadingMore] = useState(false)
  const [error, setError] = useState<string | null>(null)
  // Ignores a page that arrives after a newer reload started.
  const generation = useRef(0)

  const load = useCallback(() => {
    const mine = ++generation.current
    fetchSheets().then(
      (result) => {
        if (mine !== generation.current) return
        setError(null)
        setSheets(result.rows)
        setPage({ current: result.meta.current_page, last: result.meta.last_page })
      },
      () => {
        if (mine === generation.current) setError("No pudimos cargar las planillas.")
      },
    )
  }, [])

  useEffect(() => {
    load()
    return () => {
      generation.current++
    }
  }, [load])

  async function loadMore() {
    const mine = generation.current
    setLoadingMore(true)
    try {
      const result = await fetchSheets(page.current + 1)
      if (mine !== generation.current) return
      setSheets((previous) => {
        const seen = new Set((previous ?? []).map((sheet) => sheet.id))
        return [...(previous ?? []), ...result.rows.filter((sheet) => !seen.has(sheet.id))]
      })
      setPage({ current: result.meta.current_page, last: result.meta.last_page })
    } catch {
      if (mine === generation.current) setError("No pudimos cargar más planillas.")
    } finally {
      setLoadingMore(false)
    }
  }

  const more =
    page.current < page.last ? (
      <div className="mt-3 flex justify-center">
        <Button size="sm" variant="outline" onClick={() => void loadMore()} disabled={loadingMore}>
          {loadingMore ? "Cargando…" : "Ver más"}
        </Button>
      </div>
    ) : null

  if (!works) {
    return (
      <SectionCard title="Planillas registradas">
        <p className="mb-4 text-sm text-muted-foreground">
          Ves que la reunión existió y de qué tipo fue. El contenido — de qué se habló, qué se decidió — es del
          equipo de Psicopedagogía.
        </p>
        {error && <p className="mb-3 text-sm text-destructive">{error}</p>}
        <SheetsTable sheets={sheets} />
        {more}
      </SectionCard>
    )
  }

  return <TeamSheets sheets={sheets} error={error} reload={load} more={more} />
}

function scopeOf(sheet: { group: { name: string } | null }): string {
  return sheet.group?.name ?? "Individual"
}

function SheetsTable({
  sheets,
  onOpen,
}: {
  sheets: MeetingSheetRow[] | null
  onOpen?: (sheet: MeetingSheetRow) => void
}) {
  if (sheets === null) return <p className="text-sm text-muted-foreground">Cargando…</p>
  if (sheets.length === 0) return <EmptyState message="Todavía no hay planillas." />

  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Fecha</TableHead>
          <TableHead>Tipo</TableHead>
          <TableHead>Alcance</TableHead>
          {onOpen && <TableHead>Alumnos</TableHead>}
          {onOpen && <TableHead>Estado</TableHead>}
          {onOpen && <TableHead className="sr-only">Abrir</TableHead>}
        </TableRow>
      </TableHeader>
      <TableBody>
        {sheets.map((sheet) => (
          <TableRow key={sheet.id}>
            <TableCell>{formatShortDate(sheet.held_on)}</TableCell>
            <TableCell>{sheet.sheet_type.name}</TableCell>
            <TableCell>{scopeOf(sheet)}</TableCell>
            {onOpen && <TableCell>{sheet.students?.map((student) => student.name).join(", ")}</TableCell>}
            {onOpen && (
              <TableCell>
                {sheet.status === "confirmed" ? (
                  <Badge tone="success">Repartida</Badge>
                ) : (
                  <Badge tone="warning">Sin confirmar</Badge>
                )}
              </TableCell>
            )}
            {onOpen && (
              <TableCell>
                <Button size="sm" variant="ghost" onClick={() => onOpen(sheet)}>
                  Abrir
                </Button>
              </TableCell>
            )}
          </TableRow>
        ))}
      </TableBody>
    </Table>
  )
}

function TeamSheets({
  sheets,
  error,
  reload,
  more,
}: {
  sheets: MeetingSheetRow[] | null
  error: string | null
  reload: () => void
  more: ReactNode
}) {
  const options = useSheetFormOptions()
  const [creating, setCreating] = useState(false)
  const [open, setOpen] = useState<MeetingSheet | null>(null)
  const [openError, setOpenError] = useState<string | null>(null)

  async function openSheet(row: MeetingSheetRow) {
    setOpenError(null)
    try {
      setOpen(await fetchSheet(row.id))
    } catch {
      setOpenError("No pudimos abrir la planilla.")
    }
  }

  return (
    <>
      <SectionCard title="Tipos de planilla">
        <p className="mb-3 text-sm text-muted-foreground">
          Los definió el colegio en la configuración institucional. Todas las planillas usan estos nombres, así se
          pueden cruzar después.
        </p>
        <div className="flex flex-wrap gap-2">
          {options?.types.map((type) => (
            <Badge key={type.id}>{type.name}</Badge>
          ))}
        </div>
      </SectionCard>
      <SectionCard
        title="Planillas"
        action={
          <Button size="sm" onClick={() => setCreating(true)} disabled={!options}>
            <Plus className="size-4" /> Nueva planilla
          </Button>
        }
      >
        {(error || openError) && <p className="mb-3 text-sm text-destructive">{error ?? openError}</p>}
        <SheetsTable sheets={sheets} onOpen={(row) => void openSheet(row)} />
        {more}
      </SectionCard>
      {open && (
        <SheetDetail
          sheet={open}
          options={options}
          onChanged={(sheet) => {
            setOpen(sheet)
            reload()
          }}
        />
      )}
      {creating && options && (
        <SheetEditorDialog
          options={options}
          onClose={() => setCreating(false)}
          onSaved={(sheet) => {
            setCreating(false)
            setOpen(sheet)
            reload()
          }}
        />
      )}
    </>
  )
}

/**
 * One sheet in full (psychopedagogy only), with how it would be split. While
 * a draft, nothing has reached any file: «Confirmar el reparto» sends each
 * fragment to its student's file with its scope and notifies who can read it.
 */
export function SheetDetail({
  sheet,
  options,
  onChanged,
}: {
  sheet: MeetingSheet
  options: ReturnType<typeof useSheetFormOptions>
  onChanged: (sheet: MeetingSheet) => void
}) {
  const [editing, setEditing] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const nameOf = (id: number) => sheet.students.find((student) => student.id === id)?.name ?? `Alumno ${id}`
  const confirmed = sheet.status === "confirmed"

  async function confirm() {
    setConfirming(true)
    setError(null)
    try {
      onChanged(await confirmSheet(sheet.id))
    } catch {
      setError("No pudimos confirmar el reparto. Revisá que haya al menos un fragmento.")
    } finally {
      setConfirming(false)
    }
  }

  return (
    <SectionCard title={`${sheet.sheet_type.name} ${formatShortDate(sheet.held_on)}`}>
      <div className="grid gap-4 text-sm">
        <div>
          <p className="font-medium">Presentes</p>
          <p>
            {sheet.attendees.map((person) => person.name).join(", ") || "—"}
            {sheet.external_attendees ? ` + ${sheet.external_attendees}` : ""}
          </p>
        </div>
        <div>
          <p className="font-medium">Alumnos de los que se habló</p>
          <p>{sheet.students.map((student) => student.name).join(", ")}</p>
        </div>
        <p className="whitespace-pre-line">{sheet.content}</p>
        {sheet.actions.length > 0 && (
          <ul className="grid gap-2">
            {sheet.actions.map((action) => (
              <li key={action.id} className="rounded-md border p-2">
                <p>{action.description}</p>
                <p className="text-muted-foreground">
                  Responsable: {action.owner?.name ?? "—"} · Plazo: {formatShortDate(action.due_on)}
                  {action.done ? " · Hecha" : ""}
                </p>
              </li>
            ))}
          </ul>
        )}
        <div className="grid gap-2 rounded-md border p-3" data-testid="sheet-split">
          <p className="font-medium">{confirmed ? "Cómo quedó repartida" : "Cómo se repartiría en las fichas"}</p>
          {sheet.fragments.length === 0 && (
            <p className="text-muted-foreground">Todavía no hay fragmentos. Usá «Editar antes» para escribirlos.</p>
          )}
          {sheet.fragments.map((fragment) => (
            <div key={fragment.student_id} className="grid gap-1">
              <p className="font-medium">
                Ficha de {nameOf(fragment.student_id)} · {observationScopeLabels[fragment.scope]}
              </p>
              <p className="whitespace-pre-line">{fragment.content}</p>
            </div>
          ))}
          {confirmed ? (
            <p className="text-muted-foreground">
              {sheet.confirmed_by_name ?? "Psicopedagogía"} confirmó el reparto
              {sheet.confirmed_at ? ` el ${formatShortDate(sheet.confirmed_at)}` : ""}. Aula+ avisó a quienes pueden
              ver lo que quedó en cada ficha.
            </p>
          ) : (
            <>
              <p className="text-muted-foreground">
                Nada baja a las fichas sin tu confirmación. Al confirmar, se avisa a los presentes y a los docentes de
                los alumnos que pueden ver cada fragmento; con «Solo Psicopedagogía» no se les avisa a los docentes.
              </p>
              <div className="flex flex-wrap gap-2">
                <Button size="sm" onClick={() => void confirm()} disabled={confirming || sheet.fragments.length === 0}>
                  Confirmar el reparto
                </Button>
                <Button size="sm" variant="outline" onClick={() => setEditing(true)} disabled={!options}>
                  Editar antes
                </Button>
              </div>
            </>
          )}
          {error && <p className="text-destructive">{error}</p>}
        </div>
      </div>
      {editing && options && (
        <SheetEditorDialog
          options={options}
          sheet={sheet}
          onClose={() => setEditing(false)}
          onSaved={(saved) => {
            setEditing(false)
            onChanged(saved)
          }}
        />
      )}
    </SectionCard>
  )
}
