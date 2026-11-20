import { useEffect, useMemo, useState, type FormEvent } from "react"
import { Link, useSearchParams } from "react-router-dom"
import { FileText, Upload } from "lucide-react"
import { useAuth } from "@/features/auth/AuthContext"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { EmptyState } from "@/components/ui/empty-state"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { MultiSelect } from "@/components/ui/multi-select"
import { PageHeader } from "@/components/ui/page-header"
import { SectionCard } from "@/components/ui/section-card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { canUploadMaterials, isSchoolWideStaff } from "@/lib/permissions"
import { cn } from "@/lib/utils"
import {
  materialTypeLabels,
  type MaterialDetail,
  type MaterialOptions,
  type MaterialProgramItem,
  type MaterialRow,
  type MaterialType,
} from "@/types"
import * as materialsApi from "./materialsApi"
import type { MaterialsTab } from "./materialsApi"

const TABS: { id: MaterialsTab; label: string }[] = [
  { id: "generated", label: "Lo generado" },
  { id: "catalog", label: "Institucional" },
]

/** Types a person can pick when uploading; «Evaluación» comes from an assessment. */
const UPLOAD_TYPES = (Object.keys(materialTypeLabels) as MaterialType[]).filter((type) => type !== "assessment")

const SELECT_CLASS =
  "h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"

/** «none» is the «Sin grado» folder in the URL and the selects. */
function gradeKey(id: number | null): string {
  return id === null ? "none" : String(id)
}

function statusLabel(row: MaterialRow): { label: string; tone: "neutral" | "success" | "warning" } {
  if (row.catalog_status === "published") return { label: "En el catálogo", tone: "success" }
  if (row.catalog_status === "removed") return { label: "Sacado del catálogo", tone: "warning" }
  return { label: row.is_mine ? "Solo tuyo" : "Sin publicar", tone: "neutral" }
}

/**
 * Screen 19 «Materiales» (ClickUp 1dfd3mc4bgx): what the teacher generated and
 * uploaded, and the school's institutional catalog, always behind the hard
 * subject × grade prefilter (P19-DEC-02). Docente and Psicopedagogía upload;
 * Dirección reads. Psicopedagogía and Dirección take a material out of the
 * catalog (P19-INS-01). What each role may do comes from the server (`can`).
 */
export function MaterialsPage() {
  const { user } = useAuth()
  const [params, setParams] = useSearchParams()
  const [options, setOptions] = useState<MaterialOptions | null>(null)
  const [optionsError, setOptionsError] = useState(false)
  const [rows, setRows] = useState<MaterialRow[] | null>(null)
  const [listError, setListError] = useState(false)
  const [reload, setReload] = useState(0)
  const [uploading, setUploading] = useState(false)
  const [detail, setDetail] = useState<MaterialDetail | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)

  const tab: MaterialsTab = params.get("solapa") === "institucional" ? "catalog" : "generated"
  const subjectParam = params.get("materia") ?? ""
  const gradeParam = params.get("nivel") ?? ""
  const schoolWide = isSchoolWideStaff(user)
  const canUpload = canUploadMaterials(user)

  useEffect(() => {
    let cancelled = false
    materialsApi
      .fetchMaterialOptions()
      .then((loaded) => !cancelled && setOptions(loaded))
      .catch(() => !cancelled && setOptionsError(true))
    return () => {
      cancelled = true
    }
  }, [])

  const ready = subjectParam !== "" && gradeParam !== ""

  useEffect(() => {
    if (!ready) return
    let cancelled = false
    setRows(null)
    setListError(false)
    materialsApi
      .fetchMaterials(tab, Number(subjectParam), gradeParam === "none" ? null : Number(gradeParam))
      .then((loaded) => !cancelled && setRows(loaded))
      .catch(() => !cancelled && setListError(true))
    return () => {
      cancelled = true
    }
  }, [ready, tab, subjectParam, gradeParam, reload])

  function setParam(key: string, value: string) {
    const next = new URLSearchParams(params)
    if (value === "") next.delete(key)
    else next.set(key, value)
    setParams(next, { replace: true })
  }

  const subjectName = options?.subjects.find((subject) => String(subject.id) === subjectParam)?.name
  const gradeLabel = options?.grades.find((grade) => gradeKey(grade.id) === gradeParam)?.label
  const school = user?.school?.name

  async function run(action: () => Promise<unknown>, failure: string) {
    setActionError(null)
    try {
      await action()
      setReload((n) => n + 1)
    } catch {
      setActionError(failure)
    }
  }

  function open(row: MaterialRow) {
    setActionError(null)
    if (row.kind === "upload") {
      // Open the tab inside the click (Safari blocks a window.open after an await).
      const opened = window.open("", "_blank")
      if (opened) opened.opener = null
      materialsApi
        .fetchMaterialUrl(row.id)
        .then((url) => {
          if (opened) opened.location.href = url
          else window.location.assign(url)
        })
        .catch(() => {
          opened?.close()
          setActionError("No pudimos abrir el archivo.")
        })
      return
    }
    if (row.material_id !== null) {
      materialsApi
        .fetchMaterial(row.material_id)
        .then(setDetail)
        .catch(() => setActionError("No pudimos abrir el material."))
    }
  }

  return (
    <div className="grid gap-6">
      <PageHeader
        title="Materiales"
        description={`${school ? `${school} · ` : ""}${
          schoolWide ? "todo lo generado y todo el catálogo institucional" : "lo que generaste vos y lo que publicó el colegio"
        }`}
      >
        {canUpload && (
          <Button type="button" onClick={() => setUploading((open) => !open)}>
            <Upload className="size-4" aria-hidden="true" />
            Subir un recurso propio
          </Button>
        )}
      </PageHeader>

      {uploading && options && (
        <UploadForm
          options={options}
          defaultSubjectId={subjectParam}
          defaultGradeId={gradeParam}
          onCancel={() => setUploading(false)}
          onSaved={(saved) => {
            setUploading(false)
            const grade = options.groups.find((group) => group.id === saved.group?.id)?.grade_id ?? null
            const next = new URLSearchParams(params)
            next.set("materia", String(saved.subject_id))
            next.set("nivel", gradeKey(grade))
            next.delete("solapa")
            setParams(next, { replace: true })
            setReload((n) => n + 1)
          }}
        />
      )}

      <SectionCard title="Antes de buscar">
        {optionsError ? (
          <p role="alert" className="text-sm text-destructive">
            No pudimos cargar las materias y los niveles.
          </p>
        ) : (
          <div className="grid gap-3">
            <div className="grid gap-3 sm:grid-cols-2">
              <div className="grid gap-1.5">
                <Label htmlFor="materials-subject">Materia</Label>
                <select
                  id="materials-subject"
                  className={SELECT_CLASS}
                  value={subjectParam}
                  onChange={(event) => setParam("materia", event.target.value)}
                  disabled={!options}
                >
                  <option value="">Elegí una materia</option>
                  {options?.subjects.map((subject) => (
                    <option key={subject.id} value={subject.id}>
                      {subject.name}
                    </option>
                  ))}
                </select>
              </div>
              <div className="grid gap-1.5">
                <Label htmlFor="materials-grade">Nivel</Label>
                <select
                  id="materials-grade"
                  className={SELECT_CLASS}
                  value={gradeParam}
                  onChange={(event) => setParam("nivel", event.target.value)}
                  disabled={!options}
                >
                  <option value="">Elegí un nivel</option>
                  {options?.grades.map((grade) => (
                    <option key={gradeKey(grade.id)} value={gradeKey(grade.id)}>
                      {grade.label}
                    </option>
                  ))}
                </select>
              </div>
            </div>
            <p className="text-sm text-muted-foreground">
              Sin elegir los dos, no hay resultados. Así una búsqueda no mezcla materiales de distintos niveles ni de
              distintos programas.
            </p>
          </div>
        )}
      </SectionCard>

      <div role="tablist" aria-label="Solapas de Materiales" className="flex gap-1 border-b">
        {TABS.map((item) => (
          <button
            key={item.id}
            type="button"
            role="tab"
            id={`tab-${item.id}`}
            aria-selected={tab === item.id}
            aria-controls={`panel-${item.id}`}
            onClick={() => setParam("solapa", item.id === "catalog" ? "institucional" : "")}
            className={cn(
              "-mb-px border-b-2 px-3 py-2 text-sm font-medium",
              tab === item.id
                ? "border-primary text-foreground"
                : "border-transparent text-muted-foreground hover:text-foreground",
            )}
          >
            {item.label}
          </button>
        ))}
      </div>

      <div role="tabpanel" id={`panel-${tab}`} aria-labelledby={`tab-${tab}`} className="grid gap-4">
        {actionError && (
          <p role="alert" className="text-sm text-destructive">
            {actionError}
          </p>
        )}
        {tab === "catalog" && schoolWide && (
          <p className="text-sm text-muted-foreground">
            El colegio no pide aprobación para el catálogo: lo que se publica rige al guardar, te llega un aviso y lo
            podés sacar desde acá con «Sacar del catálogo».
          </p>
        )}
        {!ready ? (
          <EmptyState icon={FileText} message="Elegí una materia y un nivel para ver los materiales." />
        ) : listError ? (
          <p role="alert" className="text-sm text-destructive">
            No pudimos cargar los materiales.
          </p>
        ) : rows === null ? (
          <p className="text-sm text-muted-foreground">Cargando…</p>
        ) : (
          <SectionCard
            title={
              tab === "catalog"
                ? `Catálogo institucional — ${subjectName ?? ""}, ${gradeLabel ?? ""}`
                : `${schoolWide ? "Lo generado en el colegio" : "Tus materiales"} — ${subjectName ?? ""}, ${gradeLabel ?? ""}`
            }
            bare
          >
            {rows.length === 0 ? (
              <EmptyState
                className="m-4"
                message={
                  tab === "catalog"
                    ? "Todavía no hay materiales publicados para esta materia y este nivel."
                    : "Todavía no hay materiales para esta materia y este nivel."
                }
              />
            ) : (
              <MaterialsTable tab={tab} rows={rows} onOpen={open} run={run} />
            )}
            {tab === "generated" && (
              <p className="px-6 pb-4 text-sm text-muted-foreground">
                Las evaluaciones que confirmás llegan solas. Las clases confirmadas se suman cuando exista «Nueva clase».
              </p>
            )}
          </SectionCard>
        )}
      </div>

      <Dialog open={detail !== null} onOpenChange={(isOpen) => !isOpen && setDetail(null)}>
        <DialogContent className="max-h-[85vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{detail?.title}</DialogTitle>
            <DialogDescription>
              Versión del catálogo: Aula+ le sacó los nombres de alumnos, el grupo y los ajustes individuales.
            </DialogDescription>
          </DialogHeader>
          {detail && <ContentView value={detail.content} />}
        </DialogContent>
      </Dialog>
    </div>
  )
}

function MaterialsTable({
  tab,
  rows,
  onOpen,
  run,
}: {
  tab: MaterialsTab
  rows: MaterialRow[]
  onOpen: (row: MaterialRow) => void
  run: (action: () => Promise<unknown>, failure: string) => Promise<void>
}) {
  return (
    <div className="overflow-x-auto">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Nombre</TableHead>
            <TableHead>Tipo</TableHead>
            <TableHead>Elementos del programa</TableHead>
            <TableHead>{tab === "catalog" ? "Publicó" : "Grupo"}</TableHead>
            {tab === "generated" && <TableHead>Estado</TableHead>}
            <TableHead>
              <span className="sr-only">Acciones</span>
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((row) => {
            const status = statusLabel(row)
            return (
              <TableRow key={`${row.kind}-${row.id}`}>
                <TableCell className="font-medium">
                  {row.title}
                  {row.tags.length > 0 && (
                    <span className="block text-xs font-normal text-muted-foreground">{row.tags.join(", ")}</span>
                  )}
                </TableCell>
                <TableCell>{materialTypeLabels[row.type] ?? row.type}</TableCell>
                <TableCell className="text-sm">
                  {row.curricular_items.length === 0
                    ? "—"
                    : row.curricular_items.map((item) => (item.code ? `${item.code} · ${item.name}` : item.name)).join("; ")}
                </TableCell>
                <TableCell>{tab === "catalog" ? (row.published_by_name ?? "—") : (row.group?.name ?? "—")}</TableCell>
                {tab === "generated" && (
                  <TableCell>
                    <Badge tone={status.tone}>{status.label}</Badge>
                  </TableCell>
                )}
                <TableCell>
                  <div className="flex flex-wrap justify-end gap-2">
                    {row.kind === "assessment" && tab === "generated" && row.group ? (
                      <Button asChild size="sm" variant="outline">
                        <Link to={`/clases/${row.group.id}/evaluaciones`}>Abrir</Link>
                      </Button>
                    ) : (
                      <Button type="button" size="sm" variant="outline" onClick={() => onOpen(row)}>
                        Abrir
                      </Button>
                    )}
                    {row.can.publish && <PublishDialog row={row} run={run} />}
                    {row.can.remove_from_catalog && (
                      <SimpleConfirm
                        trigger="Sacar del catálogo"
                        title="¿Sacar este material del catálogo?"
                        description="Deja de verse en el catálogo del colegio. Quien lo subió lo sigue teniendo en sus materiales."
                        confirmLabel="Sacar del catálogo"
                        onConfirm={() =>
                          run(() => materialsApi.removeFromCatalog(row.material_id ?? row.id), "No pudimos sacarlo del catálogo.")
                        }
                      />
                    )}
                    {row.can.delete && (
                      <SimpleConfirm
                        trigger="Eliminar"
                        title="¿Eliminar este material?"
                        description="Se borra el archivo. Si estaba en el catálogo, también sale de ahí."
                        confirmLabel="Eliminar"
                        onConfirm={() => run(() => materialsApi.deleteMaterial(row.id), "No pudimos eliminar el material.")}
                      />
                    )}
                  </div>
                </TableCell>
              </TableRow>
            )
          })}
        </TableBody>
      </Table>
    </div>
  )
}

/** «Proponer al catálogo»: a file needs «Revisé que no tenga datos de alumnos». */
function PublishDialog({
  row,
  run,
}: {
  row: MaterialRow
  run: (action: () => Promise<unknown>, failure: string) => Promise<void>
}) {
  const [checked, setChecked] = useState(false)
  const [open, setOpen] = useState(false)
  const isFile = row.kind === "upload"

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        if (!next) setChecked(false)
      }}
    >
      <Button type="button" size="sm" variant="outline" onClick={() => setOpen(true)}>
        Proponer al catálogo
      </Button>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Proponer al catálogo del colegio</DialogTitle>
          <DialogDescription>
            {isFile
              ? "Se publica al guardar y lo ve todo el colegio. Psicopedagogía y Dirección reciben un aviso y lo pueden sacar."
              : "Aula+ le saca los nombres de alumnos, el grupo y los ajustes individuales antes de publicarla. Se publica al guardar; Psicopedagogía y Dirección reciben un aviso y la pueden sacar."}
          </DialogDescription>
        </DialogHeader>
        {isFile && (
          <label className="flex items-start gap-2 text-sm">
            <input
              type="checkbox"
              className="mt-0.5"
              checked={checked}
              onChange={(event) => setChecked(event.target.checked)}
            />
            Revisé que no tenga datos de alumnos.
          </label>
        )}
        <DialogFooter>
          <DialogClose asChild>
            <Button type="button" variant="outline">
              Cancelar
            </Button>
          </DialogClose>
          <Button
            type="button"
            disabled={isFile && !checked}
            onClick={() => {
              setOpen(false)
              void run(
                () =>
                  isFile ? materialsApi.publishMaterial(row.id) : materialsApi.publishAssessment(row.assessment_id ?? row.id),
                "No pudimos publicarlo en el catálogo.",
              )
            }}
          >
            Publicar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function SimpleConfirm({
  trigger,
  title,
  description,
  confirmLabel,
  onConfirm,
}: {
  trigger: string
  title: string
  description: string
  confirmLabel: string
  onConfirm: () => void
}) {
  const [open, setOpen] = useState(false)
  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <Button type="button" size="sm" variant="ghost" onClick={() => setOpen(true)}>
        {trigger}
      </Button>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <DialogClose asChild>
            <Button type="button" variant="outline">
              Cancelar
            </Button>
          </DialogClose>
          <Button
            type="button"
            variant="destructive"
            onClick={() => {
              setOpen(false)
              onConfirm()
            }}
          >
            {confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

/** The cleaned content of an assessment's catalog copy, as plain text (never HTML). */
function ContentView({ value }: { value: unknown }) {
  if (value === null || value === undefined || value === "") {
    return <p className="text-sm text-muted-foreground">Sin contenido.</p>
  }
  if (typeof value === "string" || typeof value === "number" || typeof value === "boolean") {
    return <p className="text-sm whitespace-pre-wrap">{String(value)}</p>
  }
  if (Array.isArray(value)) {
    return (
      <ol className="grid list-decimal gap-2 pl-5">
        {value.map((item, index) => (
          <li key={index}>
            <ContentView value={item} />
          </li>
        ))}
      </ol>
    )
  }
  return (
    <dl className="grid gap-2">
      {Object.entries(value as Record<string, unknown>).map(([key, item]) => (
        <div key={key} className="grid gap-1">
          <dt className="text-xs font-medium text-muted-foreground">{key.replaceAll("_", " ")}</dt>
          <dd>
            <ContentView value={item} />
          </dd>
        </div>
      ))}
    </dl>
  )
}

/** «Subir un recurso propio» (P19-GEN-02, PA-109): everything by hand, no AI yet. */
function UploadForm({
  options,
  defaultSubjectId,
  defaultGradeId,
  onCancel,
  onSaved,
}: {
  options: MaterialOptions
  defaultSubjectId: string
  defaultGradeId: string
  onCancel: () => void
  onSaved: (saved: MaterialRow & { subject_id: number }) => void
}) {
  const initialGroup = useMemo(() => {
    const match = options.groups.find(
      (group) =>
        (defaultGradeId === "" || gradeKey(group.grade_id) === defaultGradeId) &&
        (defaultSubjectId === "" || group.subject_ids.includes(Number(defaultSubjectId))),
    )
    return match ? String(match.id) : ""
  }, [options.groups, defaultGradeId, defaultSubjectId])

  const [file, setFile] = useState<File | null>(null)
  const [groupId, setGroupId] = useState(initialGroup)
  const [subjectId, setSubjectId] = useState(defaultSubjectId)
  const [title, setTitle] = useState("")
  const [type, setType] = useState<MaterialType>("guide")
  const [tags, setTags] = useState("")
  const [items, setItems] = useState<MaterialProgramItem[] | null>(null)
  const [itemsError, setItemsError] = useState(false)
  const [selectedItems, setSelectedItems] = useState<number[]>([])
  const [confirmed, setConfirmed] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const group = options.groups.find((candidate) => String(candidate.id) === groupId)
  const subjects = options.subjects.filter((subject) => group?.subject_ids.includes(subject.id))
  const validSubject = subjects.some((subject) => String(subject.id) === subjectId)

  useEffect(() => {
    if (!group || !validSubject) {
      setItems(null)
      return
    }
    let cancelled = false
    setItems(null)
    setItemsError(false)
    setSelectedItems([])
    materialsApi
      .fetchProgramItems(group.id, Number(subjectId))
      .then((loaded) => !cancelled && setItems(loaded))
      .catch(() => !cancelled && setItemsError(true))
    return () => {
      cancelled = true
    }
  }, [group, subjectId, validSubject])

  async function save(publish: boolean) {
    if (!file || !group || !validSubject || title.trim() === "") {
      setError("Completá el archivo, el grupo, la materia y el título.")
      return
    }
    setSaving(true)
    setError(null)
    try {
      const saved = await materialsApi.uploadMaterial({
        file,
        groupId: group.id,
        subjectId: Number(subjectId),
        title: title.trim(),
        type,
        tags: tags
          .split(",")
          .map((tag) => tag.trim())
          .filter((tag, index, all) => tag !== "" && all.indexOf(tag) === index),
        curricularItemIds: selectedItems,
        publish,
        confirmNoStudentData: confirmed,
      })
      onSaved({ ...saved, subject_id: Number(subjectId) })
    } catch (err) {
      const response = (err as { response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } } })
        .response
      const firstError = response?.data?.errors ? Object.values(response.data.errors)[0]?.[0] : undefined
      setError(firstError ?? response?.data?.message ?? "No pudimos guardar el material.")
    } finally {
      setSaving(false)
    }
  }

  function onSubmit(event: FormEvent) {
    event.preventDefault()
    void save(false)
  }

  return (
    <SectionCard title="Subir un recurso propio">
      <form className="grid gap-4" onSubmit={onSubmit}>
        <p className="text-sm text-muted-foreground">
          Para material armado fuera de una clase o evaluación generada: una guía, una consigna, una planilla de trabajo.
        </p>
        <div className="grid gap-1.5">
          <Label htmlFor="material-file">Archivo</Label>
          <Input
            id="material-file"
            type="file"
            accept=".pdf,.docx,.pptx,.xlsx,.odt,.odp,.ods,.png,.jpg,.jpeg"
            onChange={(event) => setFile(event.target.files?.[0] ?? null)}
          />
        </div>
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="grid gap-1.5">
            <Label htmlFor="material-group">Grupo</Label>
            <select
              id="material-group"
              className={SELECT_CLASS}
              value={groupId}
              onChange={(event) => setGroupId(event.target.value)}
            >
              <option value="">Elegí un grupo</option>
              {options.groups.map((candidate) => (
                <option key={candidate.id} value={candidate.id}>
                  {candidate.name}
                </option>
              ))}
            </select>
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="material-subject">Materia</Label>
            <select
              id="material-subject"
              className={SELECT_CLASS}
              value={validSubject ? subjectId : ""}
              onChange={(event) => setSubjectId(event.target.value)}
              disabled={!group}
            >
              <option value="">Elegí una materia</option>
              {subjects.map((subject) => (
                <option key={subject.id} value={subject.id}>
                  {subject.name}
                </option>
              ))}
            </select>
          </div>
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="material-title">Título</Label>
          <Input
            id="material-title"
            maxLength={200}
            value={title}
            onChange={(event) => setTitle(event.target.value)}
            aria-describedby="material-title-help"
          />
          <p id="material-title-help" className="text-xs text-muted-foreground">
            Ponele palabras clave sobre los contenidos de este material para que sea fácil de ubicar.
          </p>
        </div>
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="grid gap-1.5">
            <Label htmlFor="material-type">Tipo</Label>
            <select
              id="material-type"
              className={SELECT_CLASS}
              value={type}
              onChange={(event) => setType(event.target.value as MaterialType)}
            >
              {UPLOAD_TYPES.map((value) => (
                <option key={value} value={value}>
                  {materialTypeLabels[value]}
                </option>
              ))}
            </select>
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="material-tags">Etiquetas</Label>
            <Input
              id="material-tags"
              value={tags}
              placeholder="Separadas por comas"
              onChange={(event) => setTags(event.target.value)}
            />
          </div>
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="material-items">Elementos del programa que trabaja</Label>
          {!group || !validSubject ? (
            <p className="text-sm text-muted-foreground">Elegí el grupo y la materia para ver los elementos del programa.</p>
          ) : itemsError ? (
            <p role="alert" className="text-sm text-destructive">
              No pudimos cargar los elementos del programa.
            </p>
          ) : items === null ? (
            <p className="text-sm text-muted-foreground">Cargando…</p>
          ) : items.length === 0 ? (
            <p className="text-sm text-muted-foreground">Todavía no hay elementos del programa cargados para esta materia.</p>
          ) : (
            <MultiSelect
              id="material-items"
              options={items.map((item) => ({ id: item.id, label: item.code ? `${item.code} · ${item.name}` : item.name }))}
              selected={selectedItems}
              onChange={setSelectedItems}
              placeholder="Elegí uno o más elementos"
            />
          )}
        </div>
        <label className="flex items-start gap-2 text-sm">
          <input
            type="checkbox"
            className="mt-0.5"
            checked={confirmed}
            onChange={(event) => setConfirmed(event.target.checked)}
          />
          Revisé que no tenga datos de alumnos (hace falta para proponerlo al catálogo).
        </label>
        {error && (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        )}
        <div className="flex flex-wrap gap-2">
          <Button type="submit" disabled={saving}>
            Guardar en mis materiales
          </Button>
          <Button type="button" variant="outline" disabled={saving || !confirmed} onClick={() => void save(true)}>
            Guardar y proponer al catálogo
          </Button>
          <Button type="button" variant="ghost" onClick={onCancel}>
            Cancelar
          </Button>
        </div>
      </form>
    </SectionCard>
  )
}
