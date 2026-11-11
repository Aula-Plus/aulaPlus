import { useEffect, useState } from "react"
import { useAuth } from "@/features/auth/AuthContext"
import { canViewOfficialScales } from "@/lib/permissions"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { PageHeader } from "@/components/ui/page-header"
import { SectionCard } from "@/components/ui/section-card"
import type { NumericGradeSettings, OfficialScale } from "@/types"
import * as officialScalesApi from "./officialScalesApi"

/** Visible label of a scale: the framework's name, then which part of it. */
function scaleLabel(scale: OfficialScale): string {
  return `${scale.framework.name} — ${scale.name}`
}

/** «REDE 2024, artículo 21, p. 14». */
function citationText(scale: OfficialScale): string {
  return `${scale.citation.document}, p. ${scale.citation.page}`
}

const NUMBERS = Array.from({ length: 10 }, (_, i) => 10 - i)

/**
 * Screen 24 «Evaluación y juicios», route `/administracion/evaluacion-y-juicios`
 * (ClickUp 1dfd3mc4bgg). Only the «Escalas oficiales» tab exists for now; the
 * «Juicios» and «Evaluaciones» tabs are out of scope and not shown.
 *
 * - The official scales of the school's frameworks, each expandable with its
 *   levels in order and its citation. Read-only: nobody edits them
 *   (P24-ESC-01, P24-DEC-03) — there is no write endpoint either.
 * - What the school decides (P24-ESC-02 / P24-EVA-03): the switch «Mostrar la
 *   calificación numérica junto al nivel de avance» and, optionally, its own
 *   equivalence level → 1..10 for the Primaria scale.
 *
 * Direction only (UX gate; the server enforces it).
 */
export function OfficialScalesPage() {
  const { user } = useAuth()
  const canView = canViewOfficialScales(user)

  const [scales, setScales] = useState<OfficialScale[] | null>(null)
  const [settings, setSettings] = useState<NumericGradeSettings | null>(null)
  const [show, setShow] = useState(false)
  const [useEquivalence, setUseEquivalence] = useState(false)
  const [numbers, setNumbers] = useState<Record<number, string>>({})
  const [error, setError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    if (!canView) return
    Promise.all([officialScalesApi.fetchOfficialScales(), officialScalesApi.fetchNumericGradeSettings()])
      .then(([loadedScales, loadedSettings]) => {
        setScales(loadedScales)
        applySettings(loadedSettings)
      })
      .catch(() => setError("No pudimos cargar las escalas oficiales."))
  }, [canView])

  function applySettings(next: NumericGradeSettings) {
    setSettings(next)
    setShow(next.show_numeric_grade)
    setUseEquivalence(next.equivalences.length > 0)
    setNumbers(
      Object.fromEntries(next.equivalences.map((row) => [row.level_id, String(row.numeric_grade)])),
    )
  }

  if (!canView) {
    return <p className="text-muted-foreground">Esta sección es solo para Dirección.</p>
  }

  const primaryScale = scales?.find((scale) => scale.id === settings?.primary_scale_id) ?? null

  async function save() {
    setError(null)
    setSaved(false)
    const equivalences =
      useEquivalence && primaryScale
        ? primaryScale.levels.map((level) => ({
            level_id: level.id,
            numeric_grade: Number(numbers[level.id] ?? ""),
          }))
        : []
    if (equivalences.some((row) => !Number.isInteger(row.numeric_grade) || row.numeric_grade < 1)) {
      setError("Elegí una nota de 1 a 10 para cada nivel, o sacá la equivalencia.")
      return
    }
    try {
      applySettings(
        await officialScalesApi.updateNumericGradeSettings({ show_numeric_grade: show, equivalences }),
      )
      setSaved(true)
    } catch {
      setError("No pudimos guardar. Revisá que cada nivel tenga una nota de 1 a 10.")
    }
  }

  return (
    <div className="grid gap-6">
      <PageHeader
        title="Evaluación y juicios"
        description="Escalas oficiales de los marcos que usa el colegio."
      />
      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}

      <SectionCard title="Escalas oficiales">
        <p className="mb-3 text-sm text-muted-foreground">
          Las define un organismo externo; no se configuran. Cada una se despliega con sus niveles y
          la cita al documento oficial con su página.
        </p>
        {scales === null && !error && (
          <p className="text-sm text-muted-foreground">Cargando escalas…</p>
        )}
        {scales?.length === 0 && (
          <p className="text-sm text-muted-foreground">
            Los marcos del colegio todavía no tienen escalas oficiales cargadas.
          </p>
        )}
        <div className="grid gap-2">
          {scales?.map((scale) => (
            <details key={scale.id} className="rounded-md border p-3">
              <summary className="cursor-pointer text-sm font-medium">
                {scaleLabel(scale)} <Badge>{scale.issuing_body}</Badge>
              </summary>
              <ol className="mt-3 grid gap-2" aria-label={`Niveles de ${scaleLabel(scale)}`}>
                {scale.levels.map((level) => (
                  <li key={level.id} className="text-sm">
                    <span className="font-medium">{level.name}</span>
                    {level.description && (
                      <span className="block text-muted-foreground">{level.description}</span>
                    )}
                  </li>
                ))}
              </ol>
              <p className="mt-3 text-xs text-muted-foreground">
                Fuente: {citationText(scale)}
                {scale.citation.url && (
                  <>
                    {" · "}
                    <a
                      href={scale.citation.url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="underline"
                    >
                      documento oficial
                    </a>
                  </>
                )}
              </p>
            </details>
          ))}
        </div>
      </SectionCard>

      <SectionCard title="Lo que el colegio decide, dentro de lo permitido">
        <p className="mb-3 text-sm text-muted-foreground">
          En Primaria, si se muestra la calificación numérica junto al nivel de avance. Aula+ no
          convierte entre escalas: si el colegio quiere una equivalencia, la configura y queda claro
          que es suya.
        </p>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={show} onChange={(event) => setShow(event.target.checked)} />
          Mostrar la calificación numérica junto al nivel de avance
        </label>
        <p className="mt-1 text-xs text-muted-foreground">
          Vale para todos los lugares donde se ve una calificación de Primaria. Si está apagado, no se
          muestra ningún número.
        </p>

        {primaryScale ? (
          <div className="mt-4 grid gap-2">
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={useEquivalence}
                onChange={(event) => setUseEquivalence(event.target.checked)}
              />
              El colegio tiene su propia equivalencia con la nota de 1 a 10 (opcional)
            </label>
            {useEquivalence ? (
              <div className="grid max-w-md gap-2">
                {primaryScale.levels.map((level) => (
                  <label key={level.id} className="flex items-center justify-between gap-3 text-sm">
                    <span>{level.name}</span>
                    <select
                      className="rounded-md border px-2 py-1"
                      aria-label={`Nota del colegio para ${level.name}`}
                      value={numbers[level.id] ?? ""}
                      onChange={(event) =>
                        setNumbers((current) => ({ ...current, [level.id]: event.target.value }))
                      }
                    >
                      <option value="">—</option>
                      {NUMBERS.map((n) => (
                        <option key={n} value={n}>
                          {n}
                        </option>
                      ))}
                    </select>
                  </label>
                ))}
              </div>
            ) : (
              <p className="text-xs text-muted-foreground">
                Sin equivalencia del colegio, cada docente escribe la nota junto al nivel al cargar
                el resultado.
              </p>
            )}
          </div>
        ) : null}

        <div className="mt-4 flex items-center gap-3">
          <Button onClick={save} disabled={settings === null}>
            Guardar
          </Button>
          {saved && (
            <span role="status" className="text-sm text-muted-foreground">
              Guardado.
            </span>
          )}
        </div>
      </SectionCard>

      <SectionCard title="Ejemplo — lo que nunca se toca">
        <p className="text-sm text-muted-foreground">
          Los criterios A a D del IB MYP, de 0 a 8, los define el IB. Ni Aula+ ni el colegio los
          cambian, y Aula+ no los convierte a la escala de ANEP: un 6 de 8 en el criterio B no es un
          7,5 y no se muestra como si lo fuera.
        </p>
      </SectionCard>
    </div>
  )
}
