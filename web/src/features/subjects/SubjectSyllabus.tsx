import { useRef, useState } from "react"
import { FileText } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { ConfirmDialog } from "@/components/ui/confirm-dialog"
import type { Subject } from "@/types"
import * as subjectsApi from "./subjectsApi"
import { syllabusFileError } from "./syllabusFile"

/**
 * The curricular program of one subject (ClickUp 86e3dt6ag): open it through
 * a short-lived URL, and — for direction — upload, replace or remove it.
 * Whether the user may open it comes from `syllabus.can_view`
 * (`SubjectPolicy::viewSyllabus`).
 */
export function SubjectSyllabus({
  subject,
  canManage,
  onChange,
}: {
  subject: Subject
  canManage: boolean
  onChange: (updated: Subject) => void
}) {
  const inputRef = useRef<HTMLInputElement>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const syllabus = subject.syllabus ?? null

  async function open() {
    setError(null)
    try {
      const url = await subjectsApi.fetchSyllabusUrl(subject.id)
      window.open(url, "_blank", "noopener,noreferrer")
    } catch {
      setError("No pudimos abrir el programa.")
    }
  }

  async function upload(file: File) {
    const fileError = syllabusFileError(file)
    if (fileError) {
      setError(fileError)
      return
    }
    setError(null)
    setBusy(true)
    try {
      onChange(await subjectsApi.uploadSyllabus(subject.id, file))
    } catch {
      setError("No pudimos subir el programa.")
    } finally {
      setBusy(false)
      if (inputRef.current) inputRef.current.value = ""
    }
  }

  async function remove() {
    setError(null)
    try {
      onChange(await subjectsApi.deleteSyllabus(subject.id))
    } catch {
      setError("No pudimos quitar el programa.")
    }
  }

  if (!syllabus && !canManage) return null

  return (
    <div className="grid gap-2">
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <FileText className="size-4 text-muted-foreground" aria-hidden="true" />
        {syllabus ? (
          <>
            <span>Programa: {syllabus.name}</span>
            {syllabus.can_view && (
              <Button type="button" variant="link" size="sm" className="h-auto p-0" onClick={open}>
                Ver programa
              </Button>
            )}
          </>
        ) : (
          <span className="text-muted-foreground">Sin programa curricular</span>
        )}
        {subject.in_catalog && <Badge tone="success">En el catálogo</Badge>}
      </div>
      {syllabus && !subject.in_catalog && (
        <p className="text-xs text-muted-foreground">
          {syllabus.text_extracted
            ? "La IA usa este programa para planificar la materia hasta que esté en el catálogo de Aula+."
            : "No pudimos leer el texto de este PDF (¿es un escaneo?): queda guardado, pero la IA no lo usa."}
        </p>
      )}
      {canManage && (
        <div className="flex flex-wrap gap-2">
          <input
            ref={inputRef}
            id={`syllabus-${subject.id}`}
            type="file"
            accept="application/pdf,.pdf"
            className="sr-only"
            aria-label={`Programa curricular de ${subject.name}`}
            onChange={(event) => {
              const file = event.target.files?.[0]
              if (file) upload(file)
            }}
          />
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={busy}
            onClick={() => inputRef.current?.click()}
          >
            {busy ? "Subiendo…" : syllabus ? "Reemplazar PDF" : "Subir programa (PDF)"}
          </Button>
          {syllabus && (
            <ConfirmDialog
              trigger={
                <Button type="button" variant="ghost" size="sm">
                  Quitar
                </Button>
              }
              title="Quitar programa"
              description="Se borra el PDF de la materia y la IA deja de usarlo."
              confirmLabel="Quitar"
              onConfirm={remove}
            />
          )}
        </div>
      )}
      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
    </div>
  )
}
