import { useEffect, useState, type FormEvent } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { SectionCard } from "@/components/ui/section-card"
import * as resourcesApi from "./resourcesApi"
import type { GroupResources } from "./resourcesApi"

/**
 * The group's «Recursos disponibles» card «Del colegio y del grupo» (PA-085,
 * ClickUp 1dfd3mc4bh4): the school's resources, each one checked while the
 * group has it at hand, plus the group's own. The group's teachers and
 * Dirección uncheck and add; Psicopedagogía reads (`can_manage` comes from
 * the server, GroupPolicy::manageResources).
 */
export function GroupResourcesCard({ groupId }: { groupId: number }) {
  const [resources, setResources] = useState<GroupResources | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [name, setName] = useState("")
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    let cancelled = false
    resourcesApi
      .fetchGroupResources(groupId)
      .then((loaded) => !cancelled && setResources(loaded))
      .catch(() => !cancelled && setLoadError(true))
    return () => {
      cancelled = true
    }
  }, [groupId])

  async function run(action: () => Promise<GroupResources>, failure: string) {
    setBusy(true)
    setError(null)
    try {
      setResources(await action())
      return true
    } catch {
      setError(failure)
      return false
    } finally {
      setBusy(false)
    }
  }

  async function add(event: FormEvent) {
    event.preventDefault()
    const trimmed = name.trim()
    if (trimmed.length < 2) return
    if (await run(() => resourcesApi.createGroupResource(groupId, trimmed), "No pudimos agregar el recurso.")) {
      setName("")
    }
  }

  return (
    <SectionCard title="Recursos disponibles · Del colegio y del grupo">
      {loadError ? (
        <p role="alert" className="text-sm text-destructive">
          No pudimos cargar los recursos del grupo.
        </p>
      ) : resources === null ? (
        <p className="text-sm text-muted-foreground">Cargando…</p>
      ) : (
        <div className="grid gap-4 text-sm">
          <div className="grid gap-2">
            <h3 className="font-medium">Del colegio</h3>
            {resources.school.length === 0 ? (
              <p className="text-muted-foreground">Dirección todavía no cargó los recursos del colegio.</p>
            ) : (
              <ul className="grid gap-1">
                {resources.school.map((resource) => (
                  <li key={resource.id}>
                    <label className="flex items-center gap-2">
                      <input
                        type="checkbox"
                        checked={resource.available}
                        disabled={!resources.can_manage || busy}
                        onChange={(event) =>
                          void run(
                            () => resourcesApi.setSchoolResourceAvailable(groupId, resource.id, event.target.checked),
                            "No pudimos guardar el cambio.",
                          )
                        }
                      />
                      {resource.name}
                    </label>
                  </li>
                ))}
              </ul>
            )}
            {resources.can_manage && resources.school.length > 0 && (
              <p className="text-xs text-muted-foreground">Destildá los que este grupo no tiene a mano.</p>
            )}
          </div>
          <div className="grid gap-2">
            <h3 className="font-medium">Del grupo</h3>
            {resources.group.length === 0 ? (
              <p className="text-muted-foreground">El grupo todavía no sumó recursos propios.</p>
            ) : (
              <ul className="grid gap-1">
                {resources.group.map((resource) => (
                  <li key={resource.id} className="flex flex-wrap items-center gap-2">
                    <span>{resource.name}</span>
                    {resource.created_by_name && (
                      <span className="text-xs text-muted-foreground">· lo sumó {resource.created_by_name}</span>
                    )}
                    {resources.can_manage && (
                      <Button
                        type="button"
                        size="xs"
                        variant="ghost"
                        disabled={busy}
                        aria-label={`Quitar ${resource.name}`}
                        onClick={() =>
                          void run(
                            () => resourcesApi.deleteGroupResource(groupId, resource.id),
                            "No pudimos quitar el recurso.",
                          )
                        }
                      >
                        Quitar
                      </Button>
                    )}
                  </li>
                ))}
              </ul>
            )}
            {resources.can_manage && (
              <form className="flex flex-wrap gap-2" onSubmit={add}>
                <Input
                  aria-label="Agregar un recurso del grupo"
                  placeholder="Por ejemplo, una tele en el salón"
                  value={name}
                  maxLength={120}
                  onChange={(event) => setName(event.target.value)}
                  className="max-w-xs"
                />
                <Button type="submit" size="sm" disabled={busy || name.trim().length < 2}>
                  Agregar
                </Button>
              </form>
            )}
          </div>
          {error && (
            <p role="alert" className="text-destructive">
              {error}
            </p>
          )}
        </div>
      )}
    </SectionCard>
  )
}
