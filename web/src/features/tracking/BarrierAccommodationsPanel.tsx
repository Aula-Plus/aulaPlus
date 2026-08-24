import { useCallback, useState } from "react"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { Select } from "@/components/ui/select"
import { useAuth } from "@/features/auth/AuthContext"
import {
  canProposeBarrierAccommodation,
  canValidateBarrierAccommodation,
} from "@/lib/permissions"
import type { Accommodation, BarrierAccommodationLink } from "@/types"
import * as trackingApi from "./trackingApi"

interface BarrierAccommodationsPanelProps {
  barrierId: number
  /**
   * Accommodations of the SAME student — the only ones known to the SPA (there
   * is no general-purpose "list accommodations" endpoint). Reused as the
   * options of the "vincular adaptación existente" `<select>`.
   */
  studentAccommodations: Accommodation[]
}

/**
 * Expandable per-barrier panel that lists linked accommodations and lets the
 * viewer propose a new link or validate an existing one — kept collapsed until
 * clicked so the tracking page does not fan out into N × M requests on load
 * (docs/prompts/09 §4). Fetching, validation and linking each update local
 * state directly (no refetch), matching the "use the response, not a refetch"
 * rule from §3 — same reason: the tracking aggregate is server-cached and a
 * refetch may not reflect the fresh write.
 */
export function BarrierAccommodationsPanel({
  barrierId,
  studentAccommodations,
}: BarrierAccommodationsPanelProps) {
  const { user } = useAuth()
  const canPropose = canProposeBarrierAccommodation(user)

  const [expanded, setExpanded] = useState(false)
  const [links, setLinks] = useState<BarrierAccommodationLink[] | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [selectedAccommodationId, setSelectedAccommodationId] = useState<string>("")
  const [submitting, setSubmitting] = useState(false)

  const loadLinks = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const data = await trackingApi.fetchBarrierAccommodations(barrierId)
      setLinks(data)
    } catch {
      setError("No pudimos cargar las adaptaciones vinculadas.")
    } finally {
      setLoading(false)
    }
  }, [barrierId])

  async function handleToggle() {
    const nextExpanded = !expanded
    setExpanded(nextExpanded)
    if (nextExpanded && links === null) {
      await loadLinks()
    }
  }

  async function handleLink(event: React.FormEvent) {
    event.preventDefault()
    setError(null)
    const id = Number(selectedAccommodationId)
    if (!id) {
      setError("Elegí una adaptación.")
      return
    }
    setSubmitting(true)
    try {
      const created = await trackingApi.linkAccommodationToBarrier(barrierId, id)
      // Update in place, don't refetch — see file-level comment.
      setLinks((prev) => {
        const base = prev ?? []
        // The server upserts (syncWithoutDetaching), so if the accommodation
        // was already linked, replace the row rather than duplicate it.
        const withoutDupe = base.filter((link) => link.id !== created.id)
        return [...withoutDupe, created]
      })
      setSelectedAccommodationId("")
    } catch {
      setError("No pudimos vincular la adaptación.")
    } finally {
      setSubmitting(false)
    }
  }

  async function handleValidate(accommodationId: number) {
    setError(null)
    try {
      const updated = await trackingApi.validateBarrierAccommodation(barrierId, accommodationId)
      setLinks((prev) => prev?.map((link) => (link.id === updated.id ? updated : link)) ?? null)
    } catch {
      setError("No pudimos validar el vínculo.")
    }
  }

  // Accommodations of this student that are NOT already linked to this
  // barrier — those are the meaningful choices for the "vincular" select. The
  // server would upsert either way, but hiding already-linked options avoids
  // no-op clicks.
  const linkedIds = new Set((links ?? []).map((link) => link.id))
  const linkOptions = studentAccommodations.filter(
    (accommodation) => !linkedIds.has(accommodation.id),
  )

  return (
    <div className="mt-2 border-t pt-2">
      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={handleToggle}
        aria-expanded={expanded}
      >
        {expanded ? "Ocultar adaptaciones" : "Ver adaptaciones vinculadas"}
      </Button>

      {expanded && (
        <div className="mt-3 grid gap-3">
          {loading && <p className="text-xs text-muted-foreground">Cargando…</p>}
          {error && <p className="text-xs text-destructive">{error}</p>}

          {!loading && links !== null && (
            links.length === 0 ? (
              <p className="text-xs text-muted-foreground">
                Todavía no hay adaptaciones vinculadas a esta barrera.
              </p>
            ) : (
              <ul className="grid gap-2">
                {links.map((link) => {
                  const showValidate =
                    !link.validated && canValidateBarrierAccommodation(user, link.proposed_by_id)
                  return (
                    <li
                      key={link.id}
                      className="flex items-start justify-between gap-3 rounded-md border p-2 text-sm"
                    >
                      <div>
                        <p className="font-medium">{link.type}</p>
                        {link.description && (
                          <p className="text-xs text-muted-foreground">{link.description}</p>
                        )}
                        <p className="mt-1 text-xs">
                          {link.validated ? (
                            <span className="rounded bg-green-100 px-2 py-0.5 text-green-800">
                              Validada
                            </span>
                          ) : (
                            <span className="rounded bg-amber-100 px-2 py-0.5 text-amber-800">
                              Pendiente de validación
                            </span>
                          )}
                        </p>
                      </div>
                      {showValidate && (
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          onClick={() => handleValidate(link.id)}
                        >
                          Validar
                        </Button>
                      )}
                    </li>
                  )
                })}
              </ul>
            )
          )}

          {canPropose && (
            <form onSubmit={handleLink} className="grid gap-2 border-t pt-3">
              <Label htmlFor={`link-accommodation-${barrierId}`} className="text-xs">
                Vincular adaptación existente
              </Label>
              <div className="flex flex-wrap items-center gap-2">
                <Select
                  id={`link-accommodation-${barrierId}`}
                  value={selectedAccommodationId}
                  onChange={(event) => setSelectedAccommodationId(event.target.value)}
                  className="min-w-56 flex-1"
                >
                  <option value="">Elegí una adaptación…</option>
                  {linkOptions.map((accommodation) => (
                    <option key={accommodation.id} value={accommodation.id}>
                      {accommodation.type}
                      {accommodation.description ? ` — ${accommodation.description}` : ""}
                    </option>
                  ))}
                </Select>
                <Button type="submit" size="sm" disabled={submitting || linkOptions.length === 0}>
                  {submitting ? "Vinculando…" : "Vincular"}
                </Button>
              </div>
              {linkOptions.length === 0 && (
                <p className="text-xs text-muted-foreground">
                  Todas las adaptaciones conocidas del alumno ya están vinculadas.
                </p>
              )}
            </form>
          )}
        </div>
      )}
    </div>
  )
}
