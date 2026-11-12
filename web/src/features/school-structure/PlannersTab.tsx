import { useEffect, useState } from "react"
import { Lock } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { SectionCard } from "@/components/ui/section-card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { cn } from "@/lib/utils"
import {
  createCustomField,
  deleteCustomField,
  fetchPlannerSettings,
  updatePlannerField,
  type CustomFieldInput,
  type PlannerFamily,
  type PlannerFamilySettings,
  type PlannerField,
  type PlannerFieldType,
} from "./schoolStructureApi"

const plannerFamilyLabels: Record<PlannerFamily, string> = {
  common: "Base común (todos los marcos)",
  ib_myp: "IB MYP",
  ib_pyp: "IB PYP",
  cambridge: "Cambridge",
  anep: "ANEP (MCN/EBI y EMS)",
}

const FAMILY_ORDER: PlannerFamily[] = ["common", "ib_myp", "ib_pyp", "cambridge", "anep"]

const plannerFieldTypeLabels: Record<PlannerFieldType, string> = {
  short_text: "Texto corto",
  long_text: "Texto largo",
  number: "Número",
  list: "Lista",
  multiple_choice: "Varias opciones",
  automatic: "Automático",
}

const CUSTOM_TYPES: CustomFieldInput["type"][] = ["short_text", "long_text", "number", "list", "multiple_choice"]

/**
 * Screen 23 › «Planificadores»: per framework, the unit-planner fields with
 * the school's name and whether they are shown, plus the school's own fields.
 * A field a program asks for (🔒) can be renamed but never hidden; its
 * official name stays next to it. Changes apply to new units.
 */
export function PlannersTab() {
  const [settings, setSettings] = useState<PlannerFamilySettings[] | null>(null)
  const [family, setFamily] = useState<PlannerFamily>("common")
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    fetchPlannerSettings()
      .then(setSettings)
      .catch(() => setError("No pudimos cargar los planificadores."))
  }, [])

  const current = settings?.find((item) => item.family === family)

  return (
    <div className="grid gap-6">
      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      {settings === null && !error && <p className="text-sm text-muted-foreground">Cargando…</p>}
      {settings !== null && (
        <>
          <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Campos de">
            <span className="text-sm text-muted-foreground">Campos de</span>
            {FAMILY_ORDER.map((item) => (
              <Button
                key={item}
                size="sm"
                variant={item === family ? "default" : "outline"}
                aria-pressed={item === family}
                onClick={() => setFamily(item)}
              >
                {plannerFamilyLabels[item]}
              </Button>
            ))}
          </div>
          {current && (
            <SectionCard title={`Planificador de unidad · ${plannerFamilyLabels[family]}`} bare>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Campo (nombre oficial)</TableHead>
                    <TableHead>Nombre en el colegio</TableHead>
                    <TableHead>Tipo</TableHead>
                    <TableHead>Lo pide</TableHead>
                    <TableHead>Se muestra</TableHead>
                    <TableHead>
                      <span className="sr-only">Acciones</span>
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {current.fields.map((field) => (
                    <CatalogFieldRow key={field.id} field={field} onSaved={setSettings} />
                  ))}
                  {current.custom_fields.map((field) => (
                    <CustomFieldRow key={`school-${field.id}`} field={field} onSaved={setSettings} />
                  ))}
                </TableBody>
              </Table>
              <p className="px-6 py-4 text-sm text-muted-foreground">
                Los campos que pide un programa (🔒) no se pueden sacar: se les cambia el nombre y el oficial queda a
                la vista al lado, que es el que leen la IA y los controles. Ningún campo es obligatorio para generar
                una clase. Los cambios rigen para las unidades nuevas.
              </p>
            </SectionCard>
          )}
          <AddCustomField family={family} onSaved={setSettings} />
        </>
      )}
    </div>
  )
}

function CatalogFieldRow({
  field,
  onSaved,
}: {
  field: PlannerField
  onSaved: (settings: PlannerFamilySettings[]) => void
}) {
  const [name, setName] = useState(field.school_name ?? "")
  const [shown, setShown] = useState(field.shown)
  const [status, setStatus] = useState<"idle" | "saving" | "error">("idle")
  const dirty = name.trim() !== (field.school_name ?? "") || shown !== field.shown
  const inputId = `planner-field-${field.id}`

  async function save() {
    setStatus("saving")
    try {
      onSaved(await updatePlannerField(field.id, { school_name: name.trim() || null, shown }))
      setStatus("idle")
    } catch {
      setStatus("error")
    }
  }

  return (
    <TableRow>
      <TableCell>
        <label htmlFor={inputId} className="flex items-center gap-1">
          {field.official_name}
          {field.required_by_program && <Lock aria-label="Lo pide el programa" className="size-3.5" />}
        </label>
      </TableCell>
      <TableCell>
        <Input
          id={inputId}
          value={name}
          placeholder="—"
          maxLength={120}
          onChange={(event) => setName(event.target.value)}
        />
      </TableCell>
      <TableCell>{plannerFieldTypeLabels[field.type]}</TableCell>
      <TableCell>{field.required_by_program ? "El programa" : "Opcional"}</TableCell>
      <TableCell>
        <input
          type="checkbox"
          aria-label={`Mostrar ${field.official_name}`}
          checked={shown}
          disabled={field.required_by_program}
          onChange={(event) => setShown(event.target.checked)}
        />
      </TableCell>
      <TableCell className="text-right">
        {dirty && (
          <Button size="sm" onClick={save} disabled={status === "saving"}>
            Guardar
          </Button>
        )}
        {status === "error" && (
          <p role="alert" className="text-sm text-destructive">
            No pudimos guardar.
          </p>
        )}
      </TableCell>
    </TableRow>
  )
}

function CustomFieldRow({
  field,
  onSaved,
}: {
  field: PlannerField
  onSaved: (settings: PlannerFamilySettings[]) => void
}) {
  const [failed, setFailed] = useState(false)

  async function remove() {
    try {
      onSaved(await deleteCustomField(field.id))
    } catch {
      setFailed(true)
    }
  }

  return (
    <TableRow>
      <TableCell className="text-muted-foreground">Campo del colegio</TableCell>
      <TableCell>
        {field.school_name}
        {field.options && (
          <span className="block text-xs text-muted-foreground">{field.options.join(" · ")}</span>
        )}
      </TableCell>
      <TableCell>{plannerFieldTypeLabels[field.type]}</TableCell>
      <TableCell>{field.required_by_school ? "Obligatorio para el colegio" : "Opcional"}</TableCell>
      <TableCell>{field.shown ? "Sí" : "No"}</TableCell>
      <TableCell className="text-right">
        <Button size="sm" variant="outline" onClick={remove}>
          Quitar
        </Button>
        {failed && (
          <p role="alert" className="text-sm text-destructive">
            No pudimos quitarlo.
          </p>
        )}
      </TableCell>
    </TableRow>
  )
}

function AddCustomField({
  family,
  onSaved,
}: {
  family: PlannerFamily
  onSaved: (settings: PlannerFamilySettings[]) => void
}) {
  const [name, setName] = useState("")
  const [type, setType] = useState<CustomFieldInput["type"]>("short_text")
  const [options, setOptions] = useState("")
  const [required, setRequired] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const needsOptions = type === "list" || type === "multiple_choice"

  async function add() {
    const parsed = options
      .split(",")
      .map((option) => option.trim())
      .filter(Boolean)
    if (name.trim() === "") {
      setError("Escribí el nombre del campo.")
      return
    }
    if (needsOptions && parsed.length < 2) {
      setError("Una lista necesita al menos dos opciones, separadas por coma.")
      return
    }
    setError(null)
    try {
      onSaved(
        await createCustomField({
          name: name.trim(),
          type,
          family: family === "common" ? null : family,
          options: needsOptions ? parsed : undefined,
          required_by_school: required,
        }),
      )
      setName("")
      setOptions("")
      setRequired(false)
    } catch {
      setError("No pudimos agregar el campo. Revisá que las opciones no se repitan.")
    }
  }

  return (
    <SectionCard title="Agregar un campo del colegio">
      <div className="grid gap-3 sm:max-w-xl">
        <p className="text-sm text-muted-foreground">
          {family === "common"
            ? "Va en todos los marcos."
            : `Va solo en las materias que siguen ${plannerFamilyLabels[family]}.`}
        </p>
        <div className="grid gap-2">
          <Label htmlFor="custom-field-name">Nombre del campo</Label>
          <Input
            id="custom-field-name"
            value={name}
            maxLength={120}
            onChange={(event) => setName(event.target.value)}
          />
        </div>
        <div className="grid gap-2">
          <Label htmlFor="custom-field-type">Tipo</Label>
          <select
            id="custom-field-type"
            className={cn("h-9 rounded-md border border-input bg-transparent px-3 text-sm")}
            value={type}
            onChange={(event) => setType(event.target.value as CustomFieldInput["type"])}
          >
            {CUSTOM_TYPES.map((item) => (
              <option key={item} value={item}>
                {plannerFieldTypeLabels[item]}
              </option>
            ))}
          </select>
        </div>
        {needsOptions && (
          <div className="grid gap-2">
            <Label htmlFor="custom-field-options">Opciones, separadas por coma</Label>
            <Input
              id="custom-field-options"
              value={options}
              onChange={(event) => setOptions(event.target.value)}
            />
          </div>
        )}
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={required} onChange={(event) => setRequired(event.target.checked)} />
          Obligatorio para el colegio
        </label>
        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        <div>
          <Button onClick={add}>Agregar</Button>
        </div>
      </div>
    </SectionCard>
  )
}
