import { useCallback, useEffect, useState } from "react"
import { Link, useParams } from "react-router-dom"
import { Button } from "@/components/ui/button"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { formatShortDate } from "@/lib/utils"
import {
  auditActionLabels,
  auditOriginLabels,
  type AuditLogEntry,
  type Paginated,
} from "@/types"
import * as trackingApi from "./trackingApi"

/**
 * Read-only audit timeline for a student. Uses server-side page pagination
 * (Laravel default, `?page=N` → `{ data, meta }`) — the meta drives the
 * prev/next controls directly, no client-side page bookkeeping (docs/prompts/
 * 09 §2, §5).
 *
 * `changes` is not shown in the main table (it varies by entity and action,
 * and can be dense); a disclosure per row prints it as pretty JSON. That is
 * the least-effort useful display for a first pass — a proper per-field diff
 * UI is out of scope for this session.
 */
export function StudentHistoryPage() {
  const { id } = useParams<{ id: string }>()
  const studentId = Number(id)

  const [page, setPage] = useState(1)
  const [history, setHistory] = useState<Paginated<AuditLogEntry> | null>(null)
  const [error, setError] = useState<string | null>(null)
  /**
   * `pendingPage` tracks a click on prev/next that is still in flight. It only
   * flips inside event handlers or promise callbacks — never synchronously
   * from inside the effect (which is flagged by
   * react/set-state-in-effect) — so cascading renders are avoided.
   */
  const [pendingPage, setPendingPage] = useState<number | null>(null)

  const load = useCallback(
    (nextPage: number) => {
      return trackingApi
        .fetchStudentHistory(studentId, nextPage)
        .then((data) => {
          setHistory(data)
          setError(null)
        })
        .catch(() => setError("No pudimos cargar el historial."))
        .finally(() => setPendingPage(null))
    },
    [studentId],
  )

  useEffect(() => {
    // Effect stays minimal — no synchronous setState before the fetch
    // resolves, per the react/set-state-in-effect rule.
    load(page)
  }, [load, page])

  function goToPage(next: number) {
    if (next === page) return
    setPendingPage(next)
    setPage(next)
  }

  const loading = pendingPage !== null

  if (error) {
    return <p className="text-sm text-destructive">{error}</p>
  }

  return (
    <div className="grid gap-4">
      <div>
        <Link
          className="text-sm text-primary underline-offset-4 hover:underline"
          to={`/alumnos/${studentId}/seguimiento`}
        >
          ← Volver al seguimiento
        </Link>
        <h1 className="mt-1 text-2xl font-semibold">Historial de auditoría</h1>
      </div>

      {!history ? (
        <p className="text-muted-foreground">Cargando…</p>
      ) : history.data.length === 0 ? (
        <p className="text-muted-foreground">No hay registros en el historial.</p>
      ) : (
        <>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Fecha</TableHead>
                <TableHead>Entidad</TableHead>
                <TableHead>Acción</TableHead>
                <TableHead>Origen</TableHead>
                <TableHead>Detalle</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {history.data.map((entry) => (
                <HistoryRow key={entry.id} entry={entry} />
              ))}
            </TableBody>
          </Table>

          <div className="flex items-center justify-between text-sm text-muted-foreground">
            <span>
              Página {history.meta.current_page} de {history.meta.last_page} (
              {history.meta.total} registros)
            </span>
            <div className="flex gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => goToPage(Math.max(1, history.meta.current_page - 1))}
                disabled={loading || history.meta.current_page <= 1}
              >
                Anterior
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() =>
                  goToPage(Math.min(history.meta.last_page, history.meta.current_page + 1))
                }
                disabled={loading || history.meta.current_page >= history.meta.last_page}
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
 * One audit row + disclosure. Renders the origin as "Sistema" (not
 * "Usuario #null") when `origin === "system"`, per docs/prompts/09 §5.
 */
function HistoryRow({ entry }: { entry: AuditLogEntry }) {
  const [showDetail, setShowDetail] = useState(false)
  const originLabel =
    entry.origin === "system"
      ? auditOriginLabels.system
      : entry.user_id !== null
        ? `Usuario #${entry.user_id}`
        : auditOriginLabels.user

  return (
    <>
      <TableRow>
        <TableCell>{formatShortDate(entry.created_at)}</TableCell>
        <TableCell>
          {entry.auditable_type}
          <span className="text-xs text-muted-foreground"> #{entry.auditable_id}</span>
        </TableCell>
        <TableCell>{auditActionLabels[entry.action]}</TableCell>
        <TableCell>{originLabel}</TableCell>
        <TableCell>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => setShowDetail((prev) => !prev)}
            aria-expanded={showDetail}
          >
            {showDetail ? "Ocultar detalle" : "Ver detalle"}
          </Button>
        </TableCell>
      </TableRow>
      {showDetail && (
        <TableRow>
          <TableCell colSpan={5} className="bg-muted/40">
            <pre className="max-h-64 overflow-auto rounded-md bg-background p-3 text-xs">
              {JSON.stringify(entry.changes, null, 2)}
            </pre>
          </TableCell>
        </TableRow>
      )}
    </>
  )
}
