import { useEffect, useState, type FormEvent } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { SectionCard } from "@/components/ui/section-card"
import * as resourcesApi from "@/features/resources/resourcesApi"
import type { SchoolResource } from "@/features/resources/resourcesApi"

/**
 * «Recursos disponibles» of Colegio y estructura (P23-REC-02, ClickUp
 * 1dfd3mc4bh4): Dirección loads the school's resources; they show up in each
 * group's profile, where its teachers uncheck what the group does not have.
 */
export function SchoolResourcesTab() {
  const [resources, setResources] = useState<SchoolResource[] | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [name, setName] = useState("")
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    let cancelled = false
    resourcesApi
      .fetchSchoolResources()
      .then((loaded) => !cancelled && setResources(loaded))
      .catch(() => !cancelled && setLoadError(true))
    return () => {
      cancelled = true
    }
  }, [])

  async function add(event: FormEvent) {
    event.preventDefault()
    const trimmed = name.trim()
    if (trimmed.length < 2) return
    setBusy(true)
    setError(null)
    try {
      const created = await resourcesApi.createSchoolResource(trimmed)
      setResources((current) => [...(current ?? []), created])
      setName("")
    } catch {
      setError("No pudimos agregar el recurso.")
    } finally {
      setBusy(false)
    }
  }

  async function remove(id: number) {
    setBusy(true)
    setError(null)
    try {
      await resourcesApi.deleteSchoolResource(id)
      setResources((current) => (current ?? []).filter((resource) => resource.id !== id))
    } catch {
      setError("No pudimos quitar el recurso.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <SectionCard title="Recursos disponibles del colegio">
      {loadError ? (
        <p role="alert" className="text-sm text-destructive">
          No pudimos cargar los recursos del colegio.
        </p>
      ) : resources === null ? (
        <p className="text-sm text-muted-foreground">Cargando…</p>
      ) : (
        <div className="grid gap-4 text-sm">
          {resources.length === 0 ? (
            <p className="text-muted-foreground">Todavía no hay recursos cargados.</p>
          ) : (
            <ul className="grid gap-1">
              {resources.map((resource) => (
                <li key={resource.id} className="flex items-center justify-between gap-2 border-b py-1 last:border-b-0">
                  <span>{resource.name}</span>
                  <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    disabled={busy}
                    aria-label={`Quitar ${resource.name}`}
                    onClick={() => void remove(resource.id)}
                  >
                    Quitar
                  </Button>
                </li>
              ))}
            </ul>
          )}
          <form className="flex flex-wrap gap-2" onSubmit={add}>
            <Input
              aria-label="Agregar un recurso"
              placeholder="Agregar un recurso"
              value={name}
              maxLength={120}
              onChange={(event) => setName(event.target.value)}
              className="max-w-xs"
            />
            <Button type="submit" disabled={busy || name.trim().length < 2}>
              Agregar
            </Button>
          </form>
          {error && (
            <p role="alert" className="text-destructive">
              {error}
            </p>
          )}
          <p className="text-muted-foreground">
            Aparecen en el perfil de cada grupo, donde el docente destilda los que su grupo no tiene a mano y suma los
            propios.
          </p>
        </div>
      )}
    </SectionCard>
  )
}
