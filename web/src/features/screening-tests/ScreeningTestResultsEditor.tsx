import { useEffect, useState } from "react"
import { isAxiosError } from "axios"
import { Input } from "@/components/ui/input"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { screeningColorLabels } from "@/types"
import type { ScreeningColor, ScreeningTestResult } from "@/types"
import * as screeningApi from "./screeningTestsApi"

/**
 * Score-loading table for an application (docs/prompts/12 §5). Rows are keyed BY
 * CODE — never by name: this consumes the results endpoint, whose resource has
 * no `student_id`/name at all, so a name can neither arrive nor be rendered
 * here. On blur (or Enter) we PATCH the changed row and paint the traffic-light
 * `color` the backend returns — the colour is NEVER computed on the client.
 */
export function ScreeningTestResultsEditor({ applicationId }: { applicationId: number }) {
  const [results, setResults] = useState<ScreeningTestResult[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  // Per-row editing state: the text in the input and any per-row error.
  const [drafts, setDrafts] = useState<Record<number, string>>({})
  const [savingId, setSavingId] = useState<number | null>(null)
  const [rowError, setRowError] = useState<Record<number, string>>({})

  useEffect(() => {
    let active = true
    screeningApi
      .fetchScreeningTestResults(applicationId)
      .then((data) => {
        if (!active) return
        setResults(data)
        setDrafts(
          Object.fromEntries(
            data.map((result) => [result.id, result.score === null ? "" : String(result.score)]),
          ),
        )
      })
      .catch(() => {
        if (active) setError("No pudimos cargar los resultados.")
      })
    return () => {
      active = false
    }
  }, [applicationId])

  async function commit(result: ScreeningTestResult) {
    const raw = (drafts[result.id] ?? "").trim()
    setRowError((prev) => ({ ...prev, [result.id]: "" }))

    // Empty input: nothing to load (the PATCH requires a numeric score). Leave
    // the row as-is rather than sending an invalid request.
    if (raw === "") {
      return
    }

    const score = Number(raw)
    if (Number.isNaN(score)) {
      setRowError((prev) => ({ ...prev, [result.id]: "Número inválido" }))
      return
    }

    // No change from the persisted score → skip the request.
    if (result.score !== null && score === result.score) {
      return
    }

    setSavingId(result.id)
    try {
      const updated = await screeningApi.updateScreeningTestResult(result.id, score)
      setResults((prev) =>
        (prev ?? []).map((row) => (row.id === updated.id ? updated : row)),
      )
      setDrafts((prev) => ({
        ...prev,
        [updated.id]: updated.score === null ? "" : String(updated.score),
      }))
    } catch (err) {
      const message =
        isAxiosError(err) && err.response?.status === 422
          ? "Este tipo no tiene un diseño aprobado vigente."
          : "No pudimos guardar el puntaje."
      setRowError((prev) => ({ ...prev, [result.id]: message }))
    } finally {
      setSavingId(null)
    }
  }

  if (error) {
    return <p className="text-sm text-destructive">{error}</p>
  }

  if (!results) {
    return <p className="text-muted-foreground">Cargando resultados…</p>
  }

  if (results.length === 0) {
    return <p className="text-muted-foreground">Esta aplicación no tiene códigos asignados.</p>
  }

  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Código</TableHead>
          <TableHead>Puntaje</TableHead>
          <TableHead>Semáforo</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {results.map((result) => (
          <TableRow key={result.id}>
            <TableCell className="font-mono">{result.code}</TableCell>
            <TableCell>
              <Input
                type="number"
                step="any"
                className="w-28"
                aria-label={`Puntaje del código ${result.code}`}
                value={drafts[result.id] ?? ""}
                disabled={savingId === result.id}
                onChange={(event) =>
                  setDrafts((prev) => ({ ...prev, [result.id]: event.target.value }))
                }
                onBlur={() => commit(result)}
                onKeyDown={(event) => {
                  if (event.key === "Enter") {
                    event.preventDefault()
                    event.currentTarget.blur()
                  }
                }}
              />
              {rowError[result.id] && (
                <p className="mt-1 text-xs text-destructive">{rowError[result.id]}</p>
              )}
            </TableCell>
            <TableCell>
              <ScreeningColorBadge color={result.color} />
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  )
}

const colorClasses: Record<ScreeningColor, string> = {
  red: "bg-red-500",
  yellow: "bg-yellow-400",
  green: "bg-green-500",
}

/** The live traffic-light dot, painted from the backend-confirmed colour only. */
function ScreeningColorBadge({ color }: { color: ScreeningColor | null }) {
  if (color === null) {
    return <span className="text-sm text-muted-foreground">—</span>
  }
  return (
    <span className="inline-flex items-center gap-2 text-sm">
      <span className={`inline-block h-3 w-3 rounded-full ${colorClasses[color]}`} aria-hidden />
      {screeningColorLabels[color]}
    </span>
  )
}
