import { useEffect, useState } from "react"
import { Button } from "@/components/ui/button"
import { SectionCard } from "@/components/ui/section-card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import {
  fetchCurricularOfferings,
  updateCurricularOffering,
  type CurricularOfferingRow,
  type FrameworkOption,
} from "./schoolStructureApi"

function rowKey(row: CurricularOfferingRow): string {
  return `${row.group.id}-${row.subject.id}`
}

/**
 * Screen 23 › «Oferta curricular»: one row per group-subject with the programs
 * it follows. A row may have several (ANEP + Cambridge); ANEP is always there.
 */
export function CurricularOfferingTab() {
  const [rows, setRows] = useState<CurricularOfferingRow[] | null>(null)
  const [frameworks, setFrameworks] = useState<FrameworkOption[]>([])
  const [error, setError] = useState<string | null>(null)
  const [editing, setEditing] = useState<string | null>(null)
  const [selected, setSelected] = useState<number[]>([])
  const [rowError, setRowError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    fetchCurricularOfferings()
      .then(({ rows: loaded, frameworks: options }) => {
        setRows(loaded)
        setFrameworks(options)
      })
      .catch(() => setError("No pudimos cargar la oferta curricular."))
  }, [])

  function startEditing(row: CurricularOfferingRow) {
    setEditing(rowKey(row))
    setSelected(row.frameworks.map((framework) => framework.id))
    setRowError(null)
  }

  function toggle(id: number) {
    setSelected((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]))
  }

  const includesAnep = frameworks.some(
    (framework) => framework.planner_family === "anep" && selected.includes(framework.id),
  )

  async function save(row: CurricularOfferingRow) {
    if (!includesAnep) {
      setRowError("Tiene que quedar un programa de ANEP: la articulación con ANEP no se puede sacar.")
      return
    }
    setSaving(true)
    setRowError(null)
    try {
      const updated = await updateCurricularOffering(row.group.id, row.subject.id, selected)
      setRows((current) => current?.map((item) => (rowKey(item) === rowKey(row) ? updated : item)) ?? null)
      setEditing(null)
    } catch {
      setRowError("No pudimos guardar los programas. Revisá que haya un programa de ANEP.")
    } finally {
      setSaving(false)
    }
  }

  return (
    <SectionCard title="Oferta curricular por grupo y materia" bare>
      {error && <p role="alert" className="px-6 pb-4 text-sm text-destructive">{error}</p>}
      {rows === null && !error && <p className="px-6 pb-4 text-sm text-muted-foreground">Cargando…</p>}
      {rows !== null && rows.length === 0 && (
        <p className="px-6 pb-4 text-sm text-muted-foreground">
          Todavía no hay materias asignadas a grupos. Se asignan en Materias.
        </p>
      )}
      {rows !== null && rows.length > 0 && (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Grupo</TableHead>
              <TableHead>Materia</TableHead>
              <TableHead>Programas</TableHead>
              <TableHead>
                <span className="sr-only">Acciones</span>
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((row) => {
              const isEditing = editing === rowKey(row)
              return (
                <TableRow key={rowKey(row)}>
                  <TableCell>{row.group.name}</TableCell>
                  <TableCell>{row.subject.name}</TableCell>
                  <TableCell>
                    {isEditing ? (
                      <fieldset className="grid gap-1">
                        <legend className="sr-only">
                          Programas de {row.subject.name} en {row.group.name}
                        </legend>
                        {frameworks.map((framework) => (
                          <label key={framework.id} className="flex items-center gap-2 text-sm">
                            <input
                              type="checkbox"
                              checked={selected.includes(framework.id)}
                              onChange={() => toggle(framework.id)}
                            />
                            {framework.name}
                          </label>
                        ))}
                        {rowError && <p role="alert" className="text-sm text-destructive">{rowError}</p>}
                      </fieldset>
                    ) : row.frameworks.length > 0 ? (
                      row.frameworks.map((framework) => framework.name).join(" + ")
                    ) : (
                      <span className="text-muted-foreground">Sin programa asignado</span>
                    )}
                  </TableCell>
                  <TableCell className="text-right">
                    {isEditing ? (
                      <div className="flex justify-end gap-2">
                        <Button size="sm" onClick={() => save(row)} disabled={saving}>
                          Guardar
                        </Button>
                        <Button size="sm" variant="outline" onClick={() => setEditing(null)} disabled={saving}>
                          Cancelar
                        </Button>
                      </div>
                    ) : (
                      <Button size="sm" variant="outline" onClick={() => startEditing(row)}>
                        Editar programas
                      </Button>
                    )}
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      )}
      <p className="px-6 py-4 text-sm text-muted-foreground">
        Un grupo-materia puede tener varios programas a la vez, sin duplicar grupos. La articulación con ANEP
        está siempre: no se puede sacar.
      </p>
    </SectionCard>
  )
}
