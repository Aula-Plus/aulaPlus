import { useEffect, useState } from "react"
import { Download, Pencil, Upload } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { SectionCard } from "@/components/ui/section-card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import {
  createExternalMeasure,
  fetchExternalMeasureRoster,
  matchTemplateStudents,
  saveExternalMeasureValues,
  type ExternalMeasureRoster,
  type IndicatorDataCatalog,
  type StudentCandidate,
} from "./indicatorsApi"
import { buildTemplateCsv, parseTemplateCsv, TEMPLATE_HEADER, type TemplateRow } from "./csvTemplate"
import { coverageText, scaleText, sourceLabel } from "./indicatorText"

type LoadMode = "template" | "csv" | "manual"

const modeTitles: Record<LoadMode, string> = {
  template: "Descargar la plantilla de Aula+",
  csv: "Cargar desde Excel (CSV)",
  manual: "Cargar a mano",
}


/**
 * «Datos» (P30-DAT-02): everything that can be used as raw material, with
 * its original scale and how many students have it, and the three ways to
 * bring a datum from outside. Aggregate only: no student appears in the
 * table. Rubric criteria are not listed until per-criterion grading exists
 * (screen 17, PO decision on ClickUp).
 */
export function DataTab({ catalog, onChanged }: { catalog: IndicatorDataCatalog; onChanged: () => Promise<void> }) {
  const [mode, setMode] = useState<LoadMode | null>(null)

  return (
    <SectionCard
      title="Datos disponibles"
      action={
        <div className="flex flex-wrap gap-2">
          <Button type="button" size="sm" variant="outline" onClick={() => setMode("template")}>
            <Download aria-hidden="true" />
            Descargar la plantilla de Aula+
          </Button>
          <Button type="button" size="sm" variant="outline" onClick={() => setMode("csv")}>
            <Upload aria-hidden="true" />
            Cargar desde Excel (CSV)
          </Button>
          <Button type="button" size="sm" variant="outline" onClick={() => setMode("manual")}>
            <Pencil aria-hidden="true" />
            Cargar a mano
          </Button>
        </div>
      }
    >
      {catalog.sources.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          Todavía no hay datos de este año: aparecen cuando se cargan screenings, calificaciones o un dato de afuera.
        </p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Dato</TableHead>
              <TableHead>Escala original</TableHead>
              <TableHead>Cobertura</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {catalog.sources.map((source) => (
              <TableRow key={`${source.type}-${source.id}`}>
                <TableCell className="font-medium">{sourceLabel(source.type, source.name)}</TableCell>
                <TableCell>{scaleText(source.scale, source)}</TableCell>
                <TableCell>{coverageText(source)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
      <p className="mt-3 text-xs text-muted-foreground">
        De {catalog.roster_count} alumnos de {catalog.school_year}. Los screenings vienen de sus tomas y las notas de
        Evaluaciones. Un screening que se toma todos los años usa la toma de este año; los que no son anuales usan la
        última y muestran de qué año es. Acá cada materia cuenta todas las calificaciones del año; en cada indicador o
        cruce se elige cuáles usa (todas, todas menos una categoría, una categoría o solo la última). En Primaria con
        escala, la materia se lee por nivel de avance, salvo que el colegio haya configurado su equivalencia. Siempre se guarda el número original. Un indicador ya armado también
        es un dato para otro.
      </p>

      {mode && (
        <ExternalLoadDialog
          mode={mode}
          catalog={catalog}
          onClose={() => setMode(null)}
          onChanged={onChanged}
        />
      )}
    </SectionCard>
  )
}

const NEW_MEASURE = "new"

/** «No cargar esta fila», in the select of a repeated name. */
const SKIP_ROW = "skip"

/** A CSV whose repeated names wait for the person to say who each row is. */
interface PendingUpload {
  measureId: number
  values: { student_id: number; value: number }[]
  ambiguous: { row: TemplateRow; candidates: StudentCandidate[] }[]
  errors: number
}

function rowsText(count: number): string {
  return `${count} ${count === 1 ? "fila con errores" : "filas con errores"}`
}

/**
 * Bringing a datum from outside: pick (or create) the datum and a group, then
 * download the template, upload it as CSV, or type each value.
 */
function ExternalLoadDialog({
  mode,
  catalog,
  onClose,
  onChanged,
}: {
  mode: LoadMode
  catalog: IndicatorDataCatalog
  onClose: () => void
  onChanged: () => Promise<void>
}) {
  const externals = catalog.sources.filter((source) => source.type === "external")
  const [measureKey, setMeasureKey] = useState<string>(externals[0] ? String(externals[0].id) : NEW_MEASURE)
  const [newName, setNewName] = useState("")
  const [newMin, setNewMin] = useState("0")
  const [newMax, setNewMax] = useState("100")
  const [newAnnual, setNewAnnual] = useState(true)
  const [groupId, setGroupId] = useState<string>("")
  const [roster, setRoster] = useState<ExternalMeasureRoster | null>(null)
  const [manual, setManual] = useState<Record<number, string>>({})
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [pending, setPending] = useState<PendingUpload | null>(null)
  const [choices, setChoices] = useState<Record<number, string>>({})

  const measureId = measureKey === NEW_MEASURE ? null : Number(measureKey)

  useEffect(() => {
    if (measureId === null) {
      setRoster(null)
      return
    }
    let cancelled = false
    fetchExternalMeasureRoster(measureId, groupId ? Number(groupId) : undefined).then(
      (data) => {
        if (cancelled) return
        setRoster(data)
        setManual(
          Object.fromEntries(data.rows.map((row) => [row.student_id, row.value === null ? "" : String(row.value)])),
        )
      },
      () => {
        if (!cancelled) setError("No pudimos cargar los alumnos.")
      },
    )
    return () => {
      cancelled = true
    }
  }, [measureId, groupId])

  async function ensureMeasure(): Promise<number | null> {
    if (measureId !== null) return measureId
    if (!newName.trim()) {
      setError("Poné un nombre al dato.")
      return null
    }
    if (!(Number(newMax) > Number(newMin))) {
      setError("El valor máximo tiene que ser mayor que el mínimo.")
      return null
    }
    try {
      const measure = await createExternalMeasure({
        name: newName.trim(),
        scale_min: Number(newMin),
        scale_max: Number(newMax),
        taken_every_year: newAnnual,
      })
      await onChanged()
      setMeasureKey(String(measure.id))
      return measure.id
    } catch {
      setError("No pudimos crear el dato.")
      return null
    }
  }

  async function downloadTemplate() {
    setError(null)
    const id = await ensureMeasure()
    if (id === null) return
    try {
      const data = roster && measureId === id ? roster : await fetchExternalMeasureRoster(id, groupId ? Number(groupId) : undefined)
      triggerDownload(`plantilla_${slug(data.measure.name)}.csv`, buildTemplateCsv(data.rows))
      setMessage("Plantilla descargada. Completá la columna «valor» y cargala desde Excel (CSV).")
    } catch {
      setError("No pudimos armar la plantilla.")
    }
  }

  async function uploadCsv(file: File) {
    setError(null)
    setMessage(null)
    setPending(null)
    const id = await ensureMeasure()
    if (id === null) return
    setBusy(true)
    try {
      const { rows, bad } = parseTemplateCsv(await file.text())
      if (rows.length === 0) {
        setError("El archivo no tiene filas con un alumno y un valor.")
        return
      }
      // Who each row is: by its code, else by name (and group). A repeated
      // name is never guessed: the person picks (PO answer of 17/11).
      const matches = await matchTemplateStudents(
        id,
        rows.map(({ student_id, name, group }) => ({ student_id, name, group })),
      )
      const values: PendingUpload["values"] = []
      const ambiguous: PendingUpload["ambiguous"] = []
      let errors = bad
      matches.forEach((match, index) => {
        const row = rows[index]
        if (match.status === "matched" && match.student_id !== null) {
          // The same student twice in the file: only the first row counts.
          if (values.some((value) => value.student_id === match.student_id)) errors++
          else values.push({ student_id: match.student_id, value: row.value })
        } else if (match.status === "ambiguous") {
          ambiguous.push({ row, candidates: match.candidates })
        } else {
          errors++
        }
      })
      if (ambiguous.length > 0) {
        setChoices({})
        setPending({ measureId: id, values, ambiguous, errors })
        return
      }
      await store(id, values, errors)
    } catch {
      setError("No pudimos cargar el archivo.")
    } finally {
      setBusy(false)
    }
  }

  async function store(id: number, values: PendingUpload["values"], errors: number) {
    if (values.length === 0) {
      setError(`No quedó ninguna fila para cargar${errors > 0 ? ` (${rowsText(errors)})` : ""}.`)
      return
    }
    const result = await saveExternalMeasureValues(id, values)
    const skipped = result.skipped + errors
    setMessage(
      `${result.saved} ${result.saved === 1 ? "valor cargado" : "valores cargados"}` +
        (skipped > 0 ? ` · ${rowsText(skipped)}` : "") +
        ".",
    )
    await onChanged()
  }

  async function confirmPending() {
    if (!pending) return
    setError(null)
    if (pending.ambiguous.some((_, index) => !choices[index])) {
      setError("Elegí a qué alumno corresponde cada fila, o «No cargar esta fila».")
      return
    }
    const values = [...pending.values]
    let skippedRows = 0
    for (const [index, { row, candidates }] of pending.ambiguous.entries()) {
      if (choices[index] === SKIP_ROW) {
        skippedRows++
        continue
      }
      const studentId = Number(choices[index])
      if (values.some((value) => value.student_id === studentId)) {
        const name = candidates.find((candidate) => candidate.student_id === studentId)?.name ?? row.name
        setError(`«${name}» (código ${studentId}) quedó en dos filas. Elegí otro alumno o no cargues una de ellas.`)
        return
      }
      values.push({ student_id: studentId, value: row.value })
    }
    setBusy(true)
    try {
      await store(pending.measureId, values, pending.errors + skippedRows)
      setPending(null)
    } catch {
      setError("No pudimos cargar el archivo.")
    } finally {
      setBusy(false)
    }
  }

  async function saveManual() {
    setError(null)
    setMessage(null)
    const id = await ensureMeasure()
    if (id === null || !roster) return
    const values = roster.rows
      .filter((row) => (manual[row.student_id] ?? "") !== (row.value === null ? "" : String(row.value)))
      .map((row) => {
        const raw = (manual[row.student_id] ?? "").trim().replace(",", ".")
        return { student_id: row.student_id, value: raw === "" ? null : Number(raw) }
      })
    if (values.some((row) => row.value !== null && Number.isNaN(row.value))) {
      setError("Cada valor tiene que ser un número.")
      return
    }
    if (values.length === 0) {
      setMessage("No hay cambios para guardar.")
      return
    }
    setBusy(true)
    try {
      const result = await saveExternalMeasureValues(id, values)
      setMessage(
        `${result.saved} ${result.saved === 1 ? "valor guardado" : "valores guardados"}` +
          (result.skipped > 0 ? ` · ${result.skipped} fuera de la escala` : "") +
          ".",
      )
      await onChanged()
      setRoster(await fetchExternalMeasureRoster(id, groupId ? Number(groupId) : undefined))
    } catch {
      setError("No pudimos guardar los valores.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{modeTitles[mode]}</DialogTitle>
        </DialogHeader>
        <div className="grid gap-4">
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="grid gap-1 text-sm font-medium">
              Dato
              <select
                className="h-9 rounded-md border bg-background px-2 text-sm"
                value={measureKey}
                onChange={(event) => setMeasureKey(event.target.value)}
              >
                {externals.map((source) => (
                  <option key={source.id} value={String(source.id)}>
                    {source.name}
                  </option>
                ))}
                <option value={NEW_MEASURE}>Nuevo dato de afuera…</option>
              </select>
            </label>
            <label className="grid gap-1 text-sm font-medium">
              Grupo
              <select
                className="h-9 rounded-md border bg-background px-2 text-sm"
                value={groupId}
                onChange={(event) => setGroupId(event.target.value)}
              >
                <option value="">Todos los grupos</option>
                {catalog.groups.map((group) => (
                  <option key={group.id} value={String(group.id)}>
                    {group.name}
                  </option>
                ))}
              </select>
            </label>
          </div>

          {measureKey === NEW_MEASURE && (
            <div className="grid gap-3 rounded-md border p-3 sm:grid-cols-3">
              <div className="grid gap-1 sm:col-span-3">
                <Label htmlFor="external-name">Nombre del dato</Label>
                <Input
                  id="external-name"
                  placeholder="Por ejemplo: Prueba de vocabulario"
                  value={newName}
                  onChange={(event) => setNewName(event.target.value)}
                />
              </div>
              <div className="grid gap-1">
                <Label htmlFor="external-min">Valor mínimo</Label>
                <Input id="external-min" type="number" value={newMin} onChange={(event) => setNewMin(event.target.value)} />
              </div>
              <div className="grid gap-1">
                <Label htmlFor="external-max">Valor máximo</Label>
                <Input id="external-max" type="number" value={newMax} onChange={(event) => setNewMax(event.target.value)} />
              </div>
              <label className="flex items-center gap-2 self-end pb-2 text-sm">
                <input type="checkbox" checked={newAnnual} onChange={(event) => setNewAnnual(event.target.checked)} />
                Se toma todos los años
              </label>
            </div>
          )}

          {mode === "template" && (
            <div className="grid gap-2">
              <p className="text-sm text-muted-foreground">
                La plantilla trae una fila por alumno del grupo, con las columnas {TEMPLATE_HEADER.join(", ")}. Se completa
                «valor» con el número original y se vuelve a cargar desde Excel guardada como CSV.
              </p>
              <div>
                <Button type="button" onClick={() => void downloadTemplate()}>
                  <Download aria-hidden="true" />
                  Descargar
                </Button>
              </div>
            </div>
          )}

          {mode === "csv" && (
            <div className="grid gap-2">
              <Label htmlFor="external-csv">Archivo CSV con la plantilla completa</Label>
              <Input
                id="external-csv"
                type="file"
                accept=".csv,text/csv"
                disabled={busy}
                onChange={(event) => {
                  const file = event.target.files?.[0]
                  if (file) void uploadCsv(file)
                }}
              />
              <p className="text-xs text-muted-foreground">
                Se usan las columnas «valor» y el código del alumno («id_alumno»); si el archivo no trae el código, se
                busca por «alumno» (y «grupo», si está). Si un nombre se repite, te preguntamos a cuál corresponde. Las
                filas sin un alumno válido o con un valor fuera de la escala se cuentan y se avisan.
              </p>
            </div>
          )}

          {mode === "csv" && pending && (
            <div className="grid gap-3 rounded-md border p-3" aria-live="polite">
              <p className="text-sm font-medium">
                {pending.ambiguous.length === 1
                  ? "Hay un nombre que se repite. Elegí a qué alumno corresponde la fila."
                  : "Hay nombres que se repiten. Elegí a qué alumno corresponde cada fila."}
              </p>
              {pending.ambiguous.map(({ row, candidates }, index) => (
                <label key={index} className="grid gap-1 text-sm">
                  <span>
                    «{row.name}»{row.group ? ` (${row.group})` : ""} · valor {String(row.value).replace(".", ",")}
                  </span>
                  <select
                    aria-label={`Alumno de la fila «${row.name}» ${index + 1}`}
                    className="h-9 rounded-md border bg-background px-2 text-sm"
                    value={choices[index] ?? ""}
                    onChange={(event) => setChoices((current) => ({ ...current, [index]: event.target.value }))}
                  >
                    <option value="" disabled>
                      Elegí el alumno…
                    </option>
                    {candidates.map((candidate) => (
                      <option key={candidate.student_id} value={String(candidate.student_id)}>
                        {candidate.name} · {candidate.group_name} · código {candidate.student_id}
                      </option>
                    ))}
                    <option value={SKIP_ROW}>No cargar esta fila</option>
                  </select>
                </label>
              ))}
              <div className="flex gap-2">
                <Button type="button" disabled={busy} onClick={() => void confirmPending()}>
                  {busy ? "Cargando…" : "Cargar"}
                </Button>
                <Button type="button" variant="outline" disabled={busy} onClick={() => setPending(null)}>
                  No cargar el archivo
                </Button>
              </div>
            </div>
          )}

          {mode === "manual" &&
            (measureId === null ? (
              <p className="text-sm text-muted-foreground">Creá el dato para cargar los valores de cada alumno.</p>
            ) : roster === null ? (
              <p className="text-sm text-muted-foreground">Cargando alumnos…</p>
            ) : (
              <div className="grid gap-3">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Alumno</TableHead>
                      <TableHead>Grupo</TableHead>
                      <TableHead>
                        Valor ({roster.measure.scale_min} a {roster.measure.scale_max})
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {roster.rows.map((row) => (
                      <TableRow key={row.student_id}>
                        <TableCell>{row.name}</TableCell>
                        <TableCell>{row.group_name}</TableCell>
                        <TableCell>
                          <Input
                            aria-label={`Valor de ${row.name}`}
                            inputMode="decimal"
                            className="w-28"
                            value={manual[row.student_id] ?? ""}
                            onChange={(event) =>
                              setManual((current) => ({ ...current, [row.student_id]: event.target.value }))
                            }
                          />
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
                <p className="text-xs text-muted-foreground">
                  Se guarda un valor por alumno para {roster.school_year}. Dejarlo vacío borra el de este año.
                </p>
              </div>
            ))}

          {mode === "manual" && measureKey === NEW_MEASURE && (
            <div>
              <Button type="button" onClick={() => void ensureMeasure()}>
                Crear el dato
              </Button>
            </div>
          )}

          {message && <p className="text-sm text-muted-foreground">{message}</p>}
          {error && (
            <p role="alert" className="text-sm text-destructive">
              {error}
            </p>
          )}

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={onClose}>
              Cerrar
            </Button>
            {mode === "manual" && measureId !== null && (
              <Button type="button" disabled={busy || roster === null} onClick={() => void saveManual()}>
                {busy ? "Guardando…" : "Guardar"}
              </Button>
            )}
          </div>
        </div>
      </DialogContent>
    </Dialog>
  )
}

function slug(value: string): string {
  return (
    value
      .normalize("NFD")
      .replace(/[̀-ͯ]/g, "")
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "_")
      .replace(/^_|_$/g, "") || "dato"
  )
}

function triggerDownload(filename: string, content: string) {
  const url = URL.createObjectURL(new Blob([content], { type: "text/csv;charset=utf-8" }))
  const link = document.createElement("a")
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
