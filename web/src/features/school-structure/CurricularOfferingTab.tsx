import { useEffect, useState } from "react"
import { Button } from "@/components/ui/button"
import { SectionCard } from "@/components/ui/section-card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import {
  fetchCurricularOfferings,
  updateCurricularOffering,
  type CurricularOfferingRow,
} from "./schoolStructureApi"

function rowKey(row: CurricularOfferingRow): string {
  return `${row.group.id}-${row.subject.id}`
}

/**
 * Screen 23 › «Oferta curricular»: one row per group-subject with the programs
 * it follows. A row may have several (ANEP + Cambridge). The editor offers only
 * the programs the school follows for the group's level; the ones of the
 * school's regime come already checked and cannot be removed (`locked`). Why
 * they are there is never explained on screen (PO 13/11).
 */
export function CurricularOfferingTab() {
  const [rows, setRows] = useState<CurricularOfferingRow[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [editing, setEditing] = useState<string | null>(null)
  const [selected, setSelected] = useState<number[]>([])
  const [rowError, setRowError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    fetchCurricularOfferings()
      .then(setRows)
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

  async function save(row: CurricularOfferingRow) {
    setSaving(true)
    setRowError(null)
    try {
      const updated = await updateCurricularOffering(row.group.id, row.subject.id, selected)
      setRows((current) => current?.map((item) => (rowKey(item) === rowKey(row) ? updated : item)) ?? null)
      setEditing(null)
    } catch {
      setRowError("No pudimos guardar los programas.")
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
                  <TableCell>
                    {row.group.name}
                    <span className="block text-xs text-muted-foreground">
                      {row.group.level ? `${row.group.level} · ` : ""}
                      {row.group.school_year}
                    </span>
                  </TableCell>
                  <TableCell>{row.subject.name}</TableCell>
                  <TableCell>
                    {isEditing ? (
                      <fieldset className="grid gap-1">
                        <legend className="sr-only">
                          Programas de {row.subject.name} en {row.group.name}
                        </legend>
                        {row.available_frameworks.map((framework) => (
                          <label key={framework.id} className="flex items-center gap-2 text-sm">
                            <input
                              type="checkbox"
                              checked={framework.locked || selected.includes(framework.id)}
                              disabled={framework.locked}
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
                      <span className="text-muted-foreground">
                        Sin programa asignado
                        {row.group.level === null && (
                          <span className="block text-xs">Asigná el nivel del grupo en Grupos para ver sus programas.</span>
                        )}
                      </span>
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
        Un grupo-materia puede tener varios programas a la vez, sin duplicar grupos. Se ofrecen los programas
        que sigue el colegio para el nivel de cada grupo.
      </p>
    </SectionCard>
  )
}
