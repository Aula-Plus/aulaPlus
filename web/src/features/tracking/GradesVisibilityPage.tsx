import { useEffect, useState } from "react"
import { useAuth } from "@/features/auth/AuthContext"
import { canManageGradesVisibility } from "@/lib/permissions"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { PageHeader } from "@/components/ui/page-header"
import { SectionCard } from "@/components/ui/section-card"
import { formatShortDate } from "@/lib/utils"
import type { GradesVisibilityMode, GradesVisibilitySettings } from "@/types"
import * as gradesVisibilityApi from "./gradesVisibilityApi"

const OPTIONS: { mode: GradesVisibilityMode; label: string; help: string }[] = [
  {
    mode: "own_subject",
    label: "Solo su materia",
    help: "Cada docente ve las calificaciones de las materias que dicta. Es la opción por defecto.",
  },
  {
    mode: "all_live",
    label: "Todas las materias, en vivo",
    help: "El docente ve las calificaciones de todas las materias apenas se cargan.",
  },
  {
    mode: "all_periodic",
    label: "Todas las materias, con corte periódico",
    help: "De otras materias se ven las calificaciones cargadas hasta el último corte (las correcciones posteriores de esas calificaciones se reflejan). Las de su materia siempre están al día.",
  },
]

/** Today as YYYY-MM-DD in the browser's local time (the `max` of the anchor). */
function localToday(): string {
  const now = new Date()
  const pad = (n: number) => String(n).padStart(2, "0")
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}

/**
 * School-wide setting, route `/ajustes/calificaciones` (the old
 * `/ajustes/notas` redirects here): how much of the grades in other
 * subjects a teacher sees. Director-only (UX gate; the server enforces it).
 */
export function GradesVisibilityPage() {
  const { user } = useAuth()
  const canManage = canManageGradesVisibility(user)

  const [settings, setSettings] = useState<GradesVisibilitySettings | null>(null)
  const [mode, setMode] = useState<GradesVisibilityMode>("own_subject")
  const [months, setMonths] = useState("3")
  // Empty = let the server keep the stored anchor (or use today the first time).
  const [anchor, setAnchor] = useState("")
  const [error, setError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    gradesVisibilityApi
      .fetchGradesVisibility()
      .then((data) => {
        setSettings(data)
        setMode(data.mode)
        if (data.cutoff_months) setMonths(String(data.cutoff_months))
        if (data.cutoff_anchor) setAnchor(data.cutoff_anchor)
      })
      .catch(() => setError("No pudimos cargar la configuración."))
  }, [])

  async function save() {
    setError(null)
    setSaved(false)
    try {
      const next = await gradesVisibilityApi.updateGradesVisibility(
        mode === "all_periodic"
          ? { mode, cutoff_months: Number(months), cutoff_anchor: anchor || null }
          : { mode },
      )
      setSettings(next)
      if (next.cutoff_anchor) setAnchor(next.cutoff_anchor)
      setSaved(true)
    } catch {
      setError(
        "No pudimos guardar. Revisá que el corte sea un número de meses entre 1 y 60 y que la fecha de inicio no sea futura.",
      )
    }
  }

  if (!canManage) {
    return <p className="text-muted-foreground">Esta sección es solo para dirección.</p>
  }

  return (
    <div className="grid gap-6">
      <PageHeader
        title="Visibilidad de calificaciones"
        description="Elegí qué ve un docente de las calificaciones de las materias que no dicta. Psicopedagogía y dirección ven todas las calificaciones siempre."
      />
      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      <SectionCard title="Para todo el colegio">
        <fieldset className="grid gap-3">
          <legend className="sr-only">Visibilidad de calificaciones para docentes</legend>
          {OPTIONS.map((option) => (
            <label key={option.mode} className="flex items-start gap-3 rounded-md border p-3 text-sm">
              <input
                type="radio"
                name="grades-visibility"
                className="mt-1"
                checked={mode === option.mode}
                onChange={() => setMode(option.mode)}
              />
              <span>
                <span className="font-medium">{option.label}</span>
                <span className="block text-muted-foreground">{option.help}</span>
              </span>
            </label>
          ))}
        </fieldset>
        {mode === "all_periodic" && (
          <div className="mt-4 grid max-w-xs gap-2">
            <Label htmlFor="cutoff-months">Corte cada (meses)</Label>
            <Input
              id="cutoff-months"
              type="number"
              min={1}
              max={60}
              value={months}
              onChange={(event) => setMonths(event.target.value)}
            />
            <Label htmlFor="cutoff-anchor">Contar los cortes desde</Label>
            <Input
              id="cutoff-anchor"
              type="date"
              min="2000-01-01"
              max={localToday()}
              value={anchor}
              onChange={(event) => setAnchor(event.target.value)}
            />
            {!settings?.cutoff_anchor && (
              <p className="text-xs text-muted-foreground">Si la dejás vacía, se cuenta desde hoy.</p>
            )}
            {settings?.mode === "all_periodic" && settings.last_cutoff_at && (
              <p className="text-xs text-muted-foreground">
                Último corte: {formatShortDate(settings.last_cutoff_at)}
              </p>
            )}
          </div>
        )}
        <div className="mt-4 flex items-center gap-3">
          <Button onClick={save}>Guardar</Button>
          {saved && <span role="status" className="text-sm text-muted-foreground">Guardado.</span>}
        </div>
      </SectionCard>
    </div>
  )
}
