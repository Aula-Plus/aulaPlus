import { useEffect, useState } from "react"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { roleLabels, type Comment, type CommentShare, type StaffMember } from "@/types"
import * as scheduledFollowUpsApi from "./scheduledFollowUpsApi"
import * as trackingApi from "./trackingApi"

/** Same cap as `StoreCommentShareRequest` (`content` max:5000). */
const EXTRACT_MAX_LENGTH = 5000

export interface ShareCommentDialogProps {
  studentId: number
  /** The observation being shared; `null` keeps the dialog closed. */
  comment: Comment | null
  onOpenChange: (open: boolean) => void
  onShared: (share: CommentShare) => void
}

/**
 * «Compartir con» (ClickUp 86e3qkwnv): psychopedagogy writes an extract of one
 * observation for direction and/or teachers who see the student. Recipients
 * read only the extract; the original stays reserved. The candidate list
 * reuses the follow-up candidates endpoint (people who already see the
 * student); the server re-checks every recipient and who may share.
 *
 * Each recipient gets a notice (server side). «Usar el texto completo» copies
 * the original into the extract, still editable before sending.
 */
export function ShareCommentDialog({ studentId, comment, onOpenChange, onShared }: ShareCommentDialogProps) {
  const open = comment !== null
  const [candidates, setCandidates] = useState<StaffMember[] | null>(null)
  const [recipientIds, setRecipientIds] = useState<number[]>([])
  const [content, setContent] = useState("")
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => {
    if (!open) return
    setRecipientIds([])
    setContent("")
    setError(null)
    let cancelled = false
    scheduledFollowUpsApi
      .fetchFollowUpCandidates(studentId)
      .then((people) => {
        // Psychopedagogy already reads the original; only direction and
        // teachers are recipients.
        if (!cancelled) {
          setCandidates(people.filter((person) => person.role === "director" || person.role === "teacher"))
        }
      })
      .catch(() => {
        if (!cancelled) setCandidates([])
      })
    return () => {
      cancelled = true
    }
  }, [open, studentId])

  function toggle(id: number) {
    setRecipientIds((current) =>
      current.includes(id) ? current.filter((value) => value !== id) : [...current, id],
    )
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault()
    if (!comment) return
    setError(null)

    const trimmed = content.trim()
    if (recipientIds.length === 0) {
      setError("Elegí al menos una persona.")
      return
    }
    if (!trimmed) {
      setError("Escribí el extracto que vas a compartir.")
      return
    }
    // Observations have no length cap, so «Usar el texto completo» can bring
    // in more than an extract may hold: say so instead of a generic failure.
    if (trimmed.length > EXTRACT_MAX_LENGTH) {
      setError(`El extracto no puede superar los ${EXTRACT_MAX_LENGTH} caracteres. Acortalo antes de compartir.`)
      return
    }

    setSubmitting(true)
    try {
      const share = await trackingApi.shareComment(comment.id, {
        content: trimmed,
        recipient_ids: recipientIds,
      })
      onShared(share)
      onOpenChange(false)
    } catch {
      setError("No pudimos compartir el extracto.")
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>Compartir con</DialogTitle>
          <DialogDescription>
            Escribí un extracto. Quien lo reciba ve solo el extracto, no la observación original.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="grid gap-4">
          <fieldset className="grid gap-2">
            <legend className="mb-1.5 text-sm font-medium">Destinatarios</legend>
            {candidates === null ? (
              <p className="text-sm text-muted-foreground">Cargando…</p>
            ) : candidates.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                No hay personas de Dirección ni docentes con acceso a este alumno.
              </p>
            ) : (
              candidates.map((person) => (
                <label key={person.id} className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={recipientIds.includes(person.id)}
                    onChange={() => toggle(person.id)}
                  />
                  {person.name}
                  {person.role && (
                    <span className="text-muted-foreground">· {roleLabels[person.role]}</span>
                  )}
                </label>
              ))
            )}
          </fieldset>

          <div className="grid gap-1.5">
            <div className="flex items-center justify-between gap-2">
              <Label htmlFor="share-extract">Extracto</Label>
              <Button
                type="button"
                size="sm"
                variant="link"
                className="h-auto p-0"
                onClick={() => setContent(comment?.content ?? "")}
              >
                Usar el texto completo
              </Button>
            </div>
            <Textarea
              id="share-extract"
              value={content}
              onChange={(event) => setContent(event.target.value)}
              placeholder="Lo que necesitan saber, en tus palabras…"
            />
          </div>

          {error && <p className="text-sm text-destructive">{error}</p>}

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancelar
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? "Compartiendo…" : "Compartir extracto"}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
