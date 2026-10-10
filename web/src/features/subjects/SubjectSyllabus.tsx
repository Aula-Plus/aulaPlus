import { useEffect, useRef, useState } from "react"
import { FileText } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { ConfirmDialog } from "@/components/ui/confirm-dialog"
import type { Subject } from "@/types"
import * as subjectsApi from "./subjectsApi"
import { SYLLABUS_PII_NOTICE, syllabusFileError, syllabusUploadErrorMessage } from "./syllabusFile"

/** While the text extraction is pending, re-fetch the subject this often… */
export const SYLLABUS_POLL_INTERVAL_MS = 3000
/** …at most this many times (~1 min, the extraction job's own timeout). */
export const SYLLABUS_MAX_POLLS = 20

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
  // Set when the browser blocked the new tab: a plain link the user can click.
  const [blockedUrl, setBlockedUrl] = useState<string | null>(null)
  // The upload (by its timestamp) we stopped polling for without an answer.
  const [gaveUpOn, setGaveUpOn] = useState<string | null>(null)
  const syllabus = subject.syllabus ?? null
  const pending = syllabus?.text_status === "pending"
  const uploadedAt = syllabus?.uploaded_at ?? null

  // The parent passes a fresh callback each render; polling must not restart.
  const onChangeRef = useRef(onChange)
  useEffect(() => {
    onChangeRef.current = onChange
  })

  // The text is extracted by a queued job after each upload: poll the subject
  // (bounded) until its status settles to ready/failed.
  useEffect(() => {
    if (!pending) return
    let cancelled = false
    let attempts = 0
    let timer: ReturnType<typeof setTimeout> | undefined

    const schedule = () => {
      timer = setTimeout(async () => {
        attempts += 1
        try {
          const updated = await subjectsApi.fetchSubject(subject.id)
          if (cancelled) return
          if (updated.syllabus?.text_status !== "pending") {
            onChangeRef.current(updated)
            return
          }
        } catch {
          if (cancelled) return
        }
        if (attempts >= SYLLABUS_MAX_POLLS) setGaveUpOn(uploadedAt)
        else schedule()
      }, SYLLABUS_POLL_INTERVAL_MS)
    }
    schedule()

    return () => {
      cancelled = true
      clearTimeout(timer)
    }
  }, [pending, subject.id, uploadedAt])

  function open() {
    setError(null)
    setBlockedUrl(null)
    // Open the tab synchronously, inside the click: Safari/iOS block a
    // window.open that only happens after an await. Point it at the URL once
    // it arrives.
    const tab = window.open("", "_blank")
    // noopener semantics: the PDF tab can't reach back into the app.
    if (tab) tab.opener = null

    subjectsApi
      .fetchSyllabusUrl(subject.id)
      .then((url) => {
        if (tab) tab.location.href = url
        else setBlockedUrl(url)
      })
      .catch(() => {
        tab?.close()
        setError("No pudimos abrir el programa.")
      })
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
    } catch (err) {
      setError(syllabusUploadErrorMessage(err, "No pudimos subir el programa."))
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
      {blockedUrl && (
        <p className="text-sm">
          Tu navegador bloqueó la pestaña nueva.{" "}
          <a href={blockedUrl} target="_blank" rel="noopener noreferrer" className="underline">
            Abrir el programa
          </a>
        </p>
      )}
      {syllabus && !subject.in_catalog && (
        <p className="text-xs text-muted-foreground" aria-live="polite">
          {syllabus.text_status === "ready"
            ? "La IA usa este programa para planificar la materia hasta que esté en el catálogo de Aula+."
            : syllabus.text_status === "failed"
              ? "No pudimos leer el texto de este PDF (¿es un escaneo?): queda guardado, pero la IA no lo usa."
              : gaveUpOn === uploadedAt
                ? "Seguimos procesando el PDF. Volvé a cargar la página en unos minutos."
                : "Procesando el PDF…"}
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
      {canManage && <p className="text-xs text-muted-foreground">{SYLLABUS_PII_NOTICE}</p>}
      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
    </div>
  )
}
