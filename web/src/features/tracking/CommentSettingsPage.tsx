import { useEffect, useState } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { PageHeader } from "@/components/ui/page-header"
import { SectionCard } from "@/components/ui/section-card"
import type { CommentCategory } from "@/types"
import * as trackingApi from "./trackingApi"

/**
 * Director-only settings for comments, route `/ajustes/comentarios`: the
 * school's category list (add, rename, remove) and the recurrence threshold of
 * the trend mark ("N comentarios de una misma categoría en D días"). Role
 * gating here is UX only — the backend enforces it on every request.
 */
export function CommentSettingsPage() {
  const [categories, setCategories] = useState<CommentCategory[] | null>(null)
  const [newName, setNewName] = useState("")
  const [minCount, setMinCount] = useState("")
  const [days, setDays] = useState("")
  const [error, setError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    Promise.all([trackingApi.fetchCommentCategories(), trackingApi.fetchCommentTrendSettings()])
      .then(([loaded, settings]) => {
        setCategories(loaded)
        setMinCount(String(settings.min_count))
        setDays(String(settings.days))
      })
      .catch(() => {
        setCategories([])
        setError("No pudimos cargar la configuración.")
      })
  }, [])

  async function addCategory(event: React.FormEvent) {
    event.preventDefault()
    const name = newName.trim()
    if (!name) return
    setError(null)
    try {
      const created = await trackingApi.createCommentCategory(name)
      setCategories((prev) => [...(prev ?? []), created])
      setNewName("")
    } catch {
      setError("No pudimos crear la categoría (¿ya existe una con ese nombre?).")
    }
  }

  async function rename(category: CommentCategory, name: string) {
    const trimmed = name.trim()
    if (!trimmed || trimmed === category.name) return
    setError(null)
    try {
      const updated = await trackingApi.renameCommentCategory(category.id, trimmed)
      setCategories((prev) => (prev ?? []).map((c) => (c.id === updated.id ? updated : c)))
    } catch {
      setError("No pudimos renombrar la categoría (¿ya existe una con ese nombre?).")
    }
  }

  async function remove(category: CommentCategory) {
    setError(null)
    try {
      await trackingApi.deleteCommentCategory(category.id)
      setCategories((prev) => (prev ?? []).filter((c) => c.id !== category.id))
    } catch {
      setError("No pudimos eliminar la categoría.")
    }
  }

  async function saveThreshold(event: React.FormEvent) {
    event.preventDefault()
    setError(null)
    setSaved(false)
    try {
      await trackingApi.updateCommentTrendSettings({
        min_count: Number(minCount),
        days: Number(days),
      })
      setSaved(true)
    } catch {
      setError("No pudimos guardar el umbral (cantidad de 2 a 50, días de 1 a 365).")
    }
  }

  return (
    <div className="grid gap-6">
      <PageHeader
        title="Comentarios"
        description="Categorías disponibles al escribir un comentario y umbral de la marca de tendencia."
      />

      {error && <p className="text-sm text-destructive">{error}</p>}

      <SectionCard title="Categorías">
        {categories === null ? (
          <p className="text-muted-foreground">Cargando…</p>
        ) : (
          <div className="grid gap-3">
            <ul className="grid gap-2">
              {categories.map((category) => (
                <li key={category.id} className="flex items-center gap-2">
                  <Input
                    aria-label={`Nombre de la categoría ${category.name}`}
                    defaultValue={category.name}
                    onBlur={(event) => rename(category, event.target.value)}
                    className="max-w-xs"
                  />
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    aria-label={`Eliminar ${category.name}`}
                    onClick={() => remove(category)}
                  >
                    Eliminar
                  </Button>
                </li>
              ))}
            </ul>
            <p className="text-xs text-muted-foreground">
              Al eliminar una categoría, sus comentarios quedan sin esa categoría.
            </p>
            <form onSubmit={addCategory} className="flex items-end gap-2">
              <div className="grid gap-1.5">
                <Label htmlFor="new-category">Nueva categoría</Label>
                <Input
                  id="new-category"
                  value={newName}
                  onChange={(event) => setNewName(event.target.value)}
                  className="max-w-xs"
                />
              </div>
              <Button type="submit">Agregar</Button>
            </form>
          </div>
        )}
      </SectionCard>

      <SectionCard title="Marca de tendencia">
        <form onSubmit={saveThreshold} className="grid gap-3">
          <p className="text-sm text-muted-foreground">
            Psicopedagogía y dirección ven una marca en la ficha del alumno cuando junta esta
            cantidad de comentarios de una misma categoría en ese plazo. Es una marca para mirar,
            no una alerta.
          </p>
          <div className="flex flex-wrap gap-3">
            <div className="grid gap-1.5">
              <Label htmlFor="trend-min-count">Cantidad de comentarios</Label>
              <Input
                id="trend-min-count"
                type="number"
                min={2}
                max={50}
                value={minCount}
                onChange={(event) => setMinCount(event.target.value)}
                className="w-40"
              />
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="trend-days">En cuántos días</Label>
              <Input
                id="trend-days"
                type="number"
                min={1}
                max={365}
                value={days}
                onChange={(event) => setDays(event.target.value)}
                className="w-40"
              />
            </div>
          </div>
          <div className="flex items-center gap-3">
            <Button type="submit">Guardar</Button>
            {saved && <span className="text-sm text-muted-foreground">Guardado.</span>}
          </div>
        </form>
      </SectionCard>
    </div>
  )
}
