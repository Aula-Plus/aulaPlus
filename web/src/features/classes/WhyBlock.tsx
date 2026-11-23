import { SectionCard } from "@/components/ui/section-card"
import { plannerNote, type DataUsed, type PlannerNote } from "./classesApi"

/**
 * «¿Por qué la IA generó esta propuesta?» (P15-PRO-02, ClickUp 86e3jpzdr):
 * the AI's explanation for THIS group, read before the activities, with the
 * data it took, where from and why (PA-116) folded underneath, and the note
 * that no unit planner was read while there is none (Q3 of 86e3jujh0).
 * Plain text only: React escapes it, nothing is rendered as HTML.
 */
export function WhyBlock({
  rationale,
  dataUsed,
  planner,
}: {
  rationale: string | null
  dataUsed: DataUsed[]
  planner: PlannerNote | null | undefined
}) {
  const note = plannerNote(planner)

  return (
    <SectionCard title="¿Por qué la IA generó esta propuesta?">
      <div className="grid gap-3 text-sm">
        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">Propuesta de la IA</p>
        <p className="whitespace-pre-line">{rationale ?? "—"}</p>
        <details className="rounded-md border px-3 py-2">
          <summary className="cursor-pointer font-medium">Datos usados para esta propuesta</summary>
          <div className="mt-2 grid gap-2">
            {dataUsed.length === 0 ? (
              <p className="text-muted-foreground">
                La IA no tomó datos de desempeño del grupo: armó la propuesta según el objetivo.
              </p>
            ) : (
              <ul className="grid gap-2">
                {dataUsed.map((row, index) => (
                  <li key={index}>
                    <span className="font-medium">{row.data}</span>
                    {row.source && <span className="text-muted-foreground"> · {row.source}</span>}
                    {row.reason && <p className="text-muted-foreground">{row.reason}</p>}
                  </li>
                ))}
              </ul>
            )}
            {note && <p className="text-muted-foreground">{note}</p>}
            <p className="text-xs text-muted-foreground">
              A la IA le llega lo del grupo, agregado: nunca nombres de alumnos ni diagnósticos.
            </p>
          </div>
        </details>
      </div>
    </SectionCard>
  )
}
