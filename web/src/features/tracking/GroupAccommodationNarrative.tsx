import { useCallback, useEffect, useState } from "react"
import { Button } from "@/components/ui/button"
import { formatShortDate } from "@/lib/utils"
import type { AccommodationNarrativeView } from "@/types"
import {
  fetchAccommodationNarrative,
  generateAccommodationNarrative,
  publishAccommodationNarrative,
} from "./trackingApi"

const POLL_MS = 2000
/**
 * Polls before giving up (~3 min, past the job's own timeout). The backend
 * reports a generation that never finished as failed after 10 min anyway;
 * this only keeps the page from polling forever if no worker is running.
 */
const MAX_POLLS = 90

interface GroupAccommodationNarrativeProps {
  groupId: number
  /** Generating needs at least one active accommodation to summarise. */
  canSummarise: boolean
}

/**
 * Text summary of the group's accommodations, shown above the "Ajustes activos"
 * table. Psychopedagogy generates a draft with AI, reads it and publishes it;
 * teachers of the group, psychopedagogy and direction read the last published
 * one. The text never names a student (the backend discards it if it does).
 * Who may generate/publish is decided by the backend (`can_generate`).
 */
export function GroupAccommodationNarrative({ groupId, canSummarise }: GroupAccommodationNarrativeProps) {
  const [view, setView] = useState<AccommodationNarrativeView | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [pollsLeft, setPollsLeft] = useState(MAX_POLLS)

  const load = useCallback(async () => {
    try {
      setView(await fetchAccommodationNarrative(groupId))
    } catch {
      setError("No pudimos cargar el resumen de ajustes.")
    }
  }, [groupId])

  useEffect(() => {
    load()
  }, [load])

  const pending = view?.draft?.status === "pending"
  const waiting = pending && pollsLeft > 0
  useEffect(() => {
    if (!waiting) return
    const timer = setInterval(() => {
      setPollsLeft((left) => left - 1)
      load()
    }, POLL_MS)
    return () => clearInterval(timer)
  }, [waiting, load])

  async function run(action: () => Promise<void>, failure: string) {
    setBusy(true)
    setError(null)
    try {
      await action()
      await load()
    } catch {
      setError(failure)
    } finally {
      setBusy(false)
    }
  }

  if (!view) return error ? <p className="mb-4 text-sm text-destructive">{error}</p> : null

  const { published, draft, can_generate: canGenerate } = view
  const hasContent = Boolean(published || draft)
  if (!hasContent && !canGenerate) return null

  return (
    <div className="mb-4 grid gap-3">
      {error && <p className="text-sm text-destructive">{error}</p>}

      {published && (
        <div className="rounded-md border bg-muted/40 p-3 text-sm">
          <p className="whitespace-pre-wrap">{published.content}</p>
          <p className="mt-2 text-xs text-muted-foreground">
            Resumen generado con IA
            {published.published_by ? ` · Publicado por ${published.published_by}` : ""}
            {published.published_at ? ` el ${formatShortDate(published.published_at)}` : ""}
          </p>
          {published.outdated && (
            <p className="mt-1 text-xs font-medium text-amber-700" role="status">
              Los ajustes cambiaron desde que se generó este resumen: puede estar desactualizado.
            </p>
          )}
        </div>
      )}

      {canGenerate && draft && (
        <div className="rounded-md border border-dashed p-3 text-sm">
          <p className="mb-1 text-xs font-medium text-muted-foreground">Borrador (solo lo ve psicopedagogía)</p>
          {draft.status === "pending" &&
            (waiting ? (
              <p className="text-muted-foreground">Generando resumen…</p>
            ) : (
              <p className="text-muted-foreground">
                La generación está tardando más de lo esperado. Volvé a intentarlo en unos minutos.
              </p>
            ))}
          {draft.status === "error" && <NarrativeError draft={draft} />}
          {draft.status === "draft" && (
            <>
              <p className="whitespace-pre-wrap">{draft.content}</p>
              {draft.outdated && (
                <p className="mt-1 text-xs font-medium text-amber-700">
                  Los ajustes cambiaron desde que se generó este borrador.
                </p>
              )}
              <div className="mt-3">
                <Button
                  size="sm"
                  disabled={busy}
                  onClick={() => run(() => publishAccommodationNarrative(groupId, draft.id), "No pudimos publicar el resumen.")}
                >
                  Publicar para los docentes
                </Button>
              </div>
            </>
          )}
        </div>
      )}

      {canGenerate && (
        <div>
          <Button
            variant="outline"
            size="sm"
            disabled={busy || waiting || !canSummarise}
            onClick={() => {
              setPollsLeft(MAX_POLLS)
              run(() => generateAccommodationNarrative(groupId), "No pudimos generar el resumen.")
            }}
          >
            {published || draft ? "Generar nuevo resumen" : "Generar resumen"}
          </Button>
        </div>
      )}
    </div>
  )
}

type Draft = NonNullable<AccommodationNarrativeView["draft"]>

/** Why the last generation failed, and what to do about it. */
function NarrativeError({ draft }: { draft: Draft }) {
  if (draft.name_in_data) {
    const types = draft.flagged_types ?? []
    return (
      <div className="text-destructive">
        <p>
          {types.length === 1
            ? "Este ajuste menciona el nombre de un alumno, así que no lo enviamos a la IA:"
            : types.length > 1
              ? "Estos ajustes mencionan el nombre de un alumno, así que no los enviamos a la IA:"
              : "Algún ajuste del grupo menciona el nombre de un alumno, así que no lo enviamos a la IA."}
        </p>
        {types.length > 0 && (
          <ul className="my-1 list-disc pl-5">
            {types.map((type) => (
              <li key={type}>«{type}»</li>
            ))}
          </ul>
        )}
        <p>Corregí {types.length > 1 ? "esos ajustes" : "ese ajuste"} y volvé a generar el resumen.</p>
      </div>
    )
  }
  if (draft.stale) {
    return <p className="text-destructive">La generación anterior no terminó. Generá el resumen de nuevo.</p>
  }
  return <p className="text-destructive">No pudimos generar el resumen. Probá de nuevo.</p>
}
