import { useEffect, useState } from "react"
import { isAxiosError } from "axios"
import { Input } from "@/components/ui/input"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import type { ScreeningColor, ScreeningTestResult } from "@/types"
import * as screeningApi from "./screeningTestsApi"
import { screeningColorDot } from "./ScreeningBandList"

/**
 * Score-loading table for an application (docs/prompts/12 §5). Rows are keyed BY
 * CODE — never by name: this consumes the results endpoint, whose resource has
 * no `student_id`/name at all, so a name can neither arrive nor be rendered
 * here. On blur (or Enter) we PATCH the changed row and paint the band the
 * backend returns — the band is NEVER computed on the client.
 *
 * Each row shows the band with the design in force; when the cutoffs changed
 * since the score was loaded and the band differs, it adds «con el diseño
 * anterior era X» (decided 15/10). That second reading can be hidden so the
 * table doesn't get overloaded.
 */
export function ScreeningTestResultsEditor({ applicationId }: { applicationId: number }) {
  const [results, setResults] = useState<ScreeningTestResult[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  // Per-row editing state: the text in the input and any per-row error.
  const [drafts, setDrafts] = useState<Record<number, string>>({})
  const [savingId, setSavingId] = useState<number | null>(null)
  const [rowError, setRowError] = useState<Record<number, string>>({})
  const [showPrevious, setShowPrevious] = useState(true)

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
    if (result.score !== null && score === Number(result.score)) {
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
          ? "Este screening todavía no tiene franjas vigentes."
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

  const anyChanged = results.some((result) => result.band_changed)

  return (
    <div className="grid gap-2">
      {anyChanged && (
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={showPrevious}
            onChange={(event) => setShowPrevious(event.target.checked)}
          />
          Mostrar la lectura con el diseño anterior
        </label>
      )}
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Código</TableHead>
            <TableHead>Puntaje</TableHead>
            <TableHead>Franja</TableHead>
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
                <BandBadge name={result.current_band} color={result.current_color} />
                {showPrevious && result.band_changed && result.band && (
                  <p className="mt-1 text-xs text-muted-foreground">
                    Con el diseño anterior era {result.band}.
                  </p>
                )}
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  )
}

/** The band dot and name, painted from the backend-confirmed reading only. */
function BandBadge({ name, color }: { name: string | null; color: ScreeningColor | null }) {
  if (name === null || color === null) {
    return <span className="text-sm text-muted-foreground">—</span>
  }
  return (
    <span className="inline-flex items-center gap-2 text-sm">
      <span className={`inline-block h-3 w-3 rounded-full ${screeningColorDot[color]}`} aria-hidden />
      {name}
    </span>
  )
}
