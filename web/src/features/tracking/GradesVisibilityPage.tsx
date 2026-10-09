import { useEffect, useState } from "react"
import { useAuth } from "@/features/auth/AuthContext"
import { canManageGradesVisibility } from "@/lib/permissions"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { PageHeader } from "@/components/ui/page-header"
import { SectionCard } from "@/components/ui/section-card"
import type { GradesVisibilityMode, GradesVisibilitySettings } from "@/types"
import * as gradesVisibilityApi from "./gradesVisibilityApi"

const OPTIONS: { mode: GradesVisibilityMode; label: string; help: string }[] = [
  {
    mode: "own_subject",
    label: "Solo su materia",
    help: "Cada docente ve las notas de las materias que dicta. Es la opción por defecto.",
  },
  {
    mode: "all_live",
    label: "Todas las materias, en vivo",
    help: "El docente ve las notas de todas las materias apenas se cargan.",
  },
  {
    mode: "all_periodic",
    label: "Todas las materias, con corte periódico",
    help: "Las notas de otras materias se ven como estaban en el último corte. Las de su materia siempre están al día.",
  },
]

/**
 * School-wide setting, route `/ajustes/notas`: how much of the grades in other
 * subjects a teacher sees. Director-only (UX gate; the server enforces it).
 */
export function GradesVisibilityPage() {
  const { user } = useAuth()
  const canManage = canManageGradesVisibility(user)

  const [settings, setSettings] = useState<GradesVisibilitySettings | null>(null)
  const [mode, setMode] = useState<GradesVisibilityMode>("own_subject")
  const [months, setMonths] = useState("3")
  const [error, setError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    gradesVisibilityApi
      .fetchGradesVisibility()
      .then((data) => {
        setSettings(data)
        setMode(data.mode)
        if (data.cutoff_months) setMonths(String(data.cutoff_months))
      })
      .catch(() => setError("No pudimos cargar la configuración."))
  }, [])

  async function save() {
    setError(null)
    setSaved(false)
    try {
      const next = await gradesVisibilityApi.updateGradesVisibility({
        mode,
        cutoff_months: mode === "all_periodic" ? Number(months) : null,
      })
      setSettings(next)
      setSaved(true)
    } catch {
      setError("No pudimos guardar. Revisá que el corte sea un número de meses entre 1 y 60.")
    }
  }

  if (!canManage) {
    return <p className="text-muted-foreground">Esta sección es solo para dirección.</p>
  }

  return (
    <div className="grid gap-6">
      <PageHeader
        title="Visibilidad de notas"
        description="Elegí qué ve un docente de las notas de las materias que no dicta. Psicopedagogía y dirección ven todas las notas siempre."
      />
      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      <SectionCard title="Para todo el colegio">
        <fieldset className="grid gap-3">
          <legend className="sr-only">Visibilidad de notas para docentes</legend>
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
            {settings?.last_cutoff_at && (
              <p className="text-xs text-muted-foreground">
                Último corte: {settings.last_cutoff_at}
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
