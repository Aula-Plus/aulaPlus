import { useEffect, useState, type FormEvent } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { SectionCard } from "@/components/ui/section-card"
import * as resourcesApi from "./resourcesApi"
import type { GroupResources, ResourceVisibility } from "./resourcesApi"

/**
 * The group's «Recursos disponibles» card «Del colegio y del grupo» (PA-085,
 * ClickUp 1dfd3mc4bh4): the school's resources, each one checked while the
 * group has it at hand, plus the ones added to the group. The group's
 * teachers, Psicopedagogía and Dirección uncheck and add (`can_manage` comes
 * from the server, GroupPolicy::manageResources). A teacher chooses whether
 * what they add is for the whole group or only theirs (`can_add_own`); the
 * server decides who sees and removes each one.
 */
export function GroupResourcesCard({ groupId }: { groupId: number }) {
  const [resources, setResources] = useState<GroupResources | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [name, setName] = useState("")
  const [visibility, setVisibility] = useState<ResourceVisibility>("group")
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
    const chosen = resources?.can_add_own ? visibility : "group"
    if (await run(() => resourcesApi.createGroupResource(groupId, trimmed, chosen), "No pudimos agregar el recurso.")) {
      setName("")
      setVisibility("group")
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
              <p className="text-muted-foreground">Todavía no se sumaron recursos al grupo.</p>
            ) : (
              <ul className="grid gap-1">
                {resources.group.map((resource) => (
                  <li key={resource.id} className="flex flex-wrap items-center gap-2">
                    <span>{resource.name}</span>
                    {resource.visibility === "own" ? (
                      <span className="text-xs text-muted-foreground">
                        · solo de {resource.created_by_name ?? "un docente"}
                      </span>
                    ) : (
                      resource.created_by_name && (
                        <span className="text-xs text-muted-foreground">· lo sumó {resource.created_by_name}</span>
                      )
                    )}
                    {resource.can_remove && (
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
              <form className="grid gap-2" onSubmit={add}>
                <div className="flex flex-wrap gap-2">
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
                </div>
                {resources.can_add_own ? (
                  <fieldset className="flex flex-wrap items-center gap-3">
                    <legend className="sr-only">Para quién es el recurso</legend>
                    <label className="flex items-center gap-1.5">
                      <input
                        type="radio"
                        name="resource-visibility"
                        value="group"
                        checked={visibility === "group"}
                        onChange={() => setVisibility("group")}
                      />
                      Para todo el grupo
                    </label>
                    <label className="flex items-center gap-1.5">
                      <input
                        type="radio"
                        name="resource-visibility"
                        value="own"
                        checked={visibility === "own"}
                        onChange={() => setVisibility("own")}
                      />
                      Solo para mí
                    </label>
                  </fieldset>
                ) : (
                  <p className="text-xs text-muted-foreground">Lo que sumes lo ven todos los docentes del grupo.</p>
                )}
                {resources.can_add_own && (
                  <p className="text-xs text-muted-foreground">
                    {visibility === "group"
                      ? "Lo ven todos los docentes del grupo, con tu nombre."
                      : "Entre los docentes, solo lo ves vos. Dirección y Psicopedagogía ven que existe y que es tuyo."}
                  </p>
                )}
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
