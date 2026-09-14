import { useEffect, useState } from "react"
import { Button } from "@/components/ui/button"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import type { ScreeningTestRosterEntry } from "@/types"
import * as screeningApi from "./screeningTestsApi"

/**
 * The printable code→student roster for an application (docs/prompts/12 §5).
 * PSYCHOPEDAGOGY ONLY — this is the single place code and full name are crossed;
 * the component is mounted solely behind `canManageScreeningTests` and must not
 * be reused anywhere another role could reach it.
 *
 * Print styling is plain `@media print` via Tailwind's `print:` variant (no PDF
 * library, spec §5): on print everything but the sheet is hidden and the sheet
 * fills the page. Each row lists the code, the student, and a blank box to write
 * the score by hand while applying the test.
 */
export function ScreeningTestRosterSheet({ applicationId }: { applicationId: number }) {
  const [roster, setRoster] = useState<ScreeningTestRosterEntry[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let active = true
    screeningApi
      .fetchScreeningTestRoster(applicationId)
      .then((data) => {
        if (active) setRoster(data)
      })
      .catch(() => {
        if (active) setError("No pudimos cargar la hoja.")
      })
    return () => {
      active = false
    }
  }, [applicationId])

  if (error) {
    return <p className="text-sm text-destructive">{error}</p>
  }

  if (!roster) {
    return <p className="text-muted-foreground">Cargando hoja…</p>
  }

  return (
    <div className="mt-3">
      <div className="print:hidden">
        <Button type="button" variant="outline" size="sm" onClick={() => window.print()}>
          Imprimir hoja
        </Button>
      </div>

      {/* The printable sheet. `print:block` + hiding the rest of the app is done
          globally in index.css via the `screening-roster-sheet` marker class. */}
      <div className="screening-roster-sheet mt-3">
        <h2 className="mb-2 text-lg font-semibold">Hoja de aplicación</h2>
        {roster.length === 0 ? (
          <p className="text-muted-foreground">Esta aplicación no tiene códigos asignados.</p>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Código</TableHead>
                <TableHead>Alumno</TableHead>
                <TableHead>Puntaje</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {roster.map((entry) => (
                <TableRow key={entry.code}>
                  <TableCell className="font-mono">{entry.code}</TableCell>
                  <TableCell>{entry.full_name ?? "—"}</TableCell>
                  <TableCell>
                    <span className="inline-block h-6 w-24 border-b border-foreground/40" />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </div>
    </div>
  )
}
