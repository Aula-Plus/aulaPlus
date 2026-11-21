import { useCallback, useEffect, useState } from "react"
import { Link, useParams } from "react-router-dom"

import { PageHeader } from "@/components/ui/page-header"
import { SectionCard } from "@/components/ui/section-card"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { formatDateTime, formatShortDate } from "@/lib/utils"
import type { ObjectiveCoverage } from "@/features/annual-plans/annualPlansApi"
import { WhyBlock } from "./WhyBlock"
import { classSectionLabels, closeClass, closingLabels, fetchClass, type ClassDetail } from "./classesApi"

/**
 * A confirmed class (screen 15, ClickUp 86e3jujh0): the sections the teacher
 * approved, the «¿por qué?» of its proposal, and closing it — one tap
 * (P15-DEC-03): «¿Se dio lo que planificaste?» Todo, Una parte or Nada. Only
 * «Una parte» asks which objectives were given. Optional, enabled from the day
 * of the class; Psicopedagogía and Dirección only read it (`can_close`).
 */
export function ClassPage() {
  const { id } = useParams()
  const classId = Number(id)
  const [detail, setDetail] = useState<ClassDetail | null>(null)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(
    () =>
      fetchClass(classId)
        .then(setDetail)
        .catch(() => setError("No pudimos cargar la clase.")),
    [classId],
  )

  useEffect(() => {
    void load()
  }, [load])

  if (error) return <p className="text-sm text-destructive">{error}</p>
  if (!detail) return <p className="text-sm text-muted-foreground">Cargando…</p>

  const meta = [
    detail.subject?.name,
    detail.group?.name,
    detail.unit ? `Unidad ${detail.unit.position} — ${detail.unit.name}` : "Clase libre",
    formatShortDate(detail.date),
    detail.start_time,
    detail.room,
    `${detail.duration_minutes} minutos`,
  ]
    .filter(Boolean)
    .join(" · ")

  return (
    <div className="grid gap-6">
      <PageHeader backTo="/nueva-clase" backLabel="Tus clases" title={detail.title} description={meta}>
        {detail.status === "cancelled" ? (
          <Badge>Cancelada</Badge>
        ) : detail.closing_result ? (
          <Badge tone="success">Cerrada · {closingLabels[detail.closing_result]}</Badge>
        ) : (
          <Badge tone="info">Confirmada</Badge>
        )}
      </PageHeader>

      {detail.unit && (
        <p className="text-sm">
          <Link
            className="text-primary underline-offset-2 hover:underline"
            to={`/programa-anual/${detail.unit.annual_plan_id}`}
          >
            Ver el Programa anual
          </Link>
        </p>
      )}

      {(detail.objective || detail.objectives.length > 0) && (
        <SectionCard title="Lo que querés que logren">
          <div className="grid gap-2 text-sm">
            {detail.objective && <p>{detail.objective}</p>}
            {detail.objectives.length > 0 && (
              <ul className="grid gap-1">
                {detail.objectives.map((o) => (
                  <li key={o.id} className="flex flex-wrap items-center gap-2">
                    <span>{o.description}</span>
                    {o.coverage && <Badge tone={o.coverage === "full" ? "success" : "neutral"}>{closingLabels[o.coverage]}</Badge>}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </SectionCard>
      )}

      <CloseClassCard detail={detail} onClosed={setDetail} />

      {detail.proposal && (
        <WhyBlock
          rationale={detail.proposal.rationale}
          dataUsed={detail.proposal.data_used}
          planner={detail.proposal.planner}
        />
      )}

      <section aria-label="La clase, sección por sección" className="grid gap-4">
        {detail.sections.length === 0 && detail.description && (
          <SectionCard title="La clase">
            <p className="text-sm whitespace-pre-line">{detail.description}</p>
          </SectionCard>
        )}
        {detail.sections.map((section) => (
          <SectionCard
            key={section.key}
            title={
              <span className="flex flex-wrap items-center gap-2">
                {classSectionLabels[section.key]}
                {section.duration_minutes ? (
                  <span className="text-sm font-normal text-muted-foreground">{section.duration_minutes} min</span>
                ) : null}
                {section.edited && <Badge>Editada por el docente</Badge>}
              </span>
            }
          >
            <p className="text-sm whitespace-pre-line">{section.content}</p>
          </SectionCard>
        ))}
      </section>
    </div>
  )
}

function CloseClassCard({ detail, onClosed }: { detail: ClassDetail; onClosed: (next: ClassDetail) => void }) {
  const [choosingPart, setChoosingPart] = useState(false)
  const [given, setGiven] = useState<number[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [reclosing, setReclosing] = useState(false)

  async function submit(result: ObjectiveCoverage, givenIds: number[] = []) {
    setBusy(true)
    setError(null)
    try {
      onClosed(await closeClass(detail.id, result, givenIds))
      setChoosingPart(false)
      setReclosing(false)
      setGiven([])
    } catch {
      setError("No pudimos cerrar la clase. Probá de nuevo.")
    } finally {
      setBusy(false)
    }
  }

  function onPart() {
    // With no objectives to mark, «Una parte» is one tap too.
    if (detail.objectives.length === 0) void submit("partial")
    else setChoosingPart(true)
  }

  const closed = detail.closing_result !== null

  return (
    <SectionCard title="Cerrar la clase">
      <div className="grid gap-3 text-sm">
        {closed && !reclosing ? (
          <>
            <p>
              Cerrada con «{closingLabels[detail.closing_result as ObjectiveCoverage]}»
              {detail.closed_at ? ` el ${formatDateTime(detail.closed_at)}` : ""}.
            </p>
            {detail.can_close && detail.closable_now && (
              <div>
                <Button size="sm" variant="ghost" onClick={() => setReclosing(true)}>
                  Cambiar el cierre
                </Button>
              </div>
            )}
          </>
        ) : !detail.can_close ? (
          <p className="text-muted-foreground">Todavía no se cerró. La cierra el docente de la materia.</p>
        ) : !detail.closable_now ? (
          <p className="text-muted-foreground">
            {detail.status === "cancelled" ? "La clase se canceló: no se cierra." : "Se habilita el día de la clase."}
          </p>
        ) : choosingPart ? (
          <fieldset className="grid gap-2">
            <legend className="font-medium">¿Qué objetivos se dieron?</legend>
            {detail.objectives.map((o) => (
              <label key={o.id} className="flex items-start gap-2">
                <input
                  type="checkbox"
                  className="mt-1"
                  checked={given.includes(o.id)}
                  onChange={() =>
                    setGiven((current) => (current.includes(o.id) ? current.filter((x) => x !== o.id) : [...current, o.id]))
                  }
                />
                <span>{o.description}</span>
              </label>
            ))}
            <p className="text-xs text-muted-foreground">Los demás quedan empezados: todavía no cuentan como dados.</p>
            <div className="flex flex-wrap gap-2">
              <Button size="sm" disabled={busy} onClick={() => void submit("partial", given)}>
                Guardar «Una parte»
              </Button>
              <Button size="sm" variant="ghost" onClick={() => setChoosingPart(false)}>
                Cancelar
              </Button>
            </div>
          </fieldset>
        ) : (
          <>
            <p className="font-medium">¿Se dio lo que planificaste?</p>
            <div className="flex flex-wrap gap-2">
              <Button disabled={busy} onClick={() => void submit("full")}>
                Todo
              </Button>
              <Button variant="outline" disabled={busy} onClick={onPart}>
                Una parte
              </Button>
              <Button variant="outline" disabled={busy} onClick={() => void submit("none")}>
                Nada
              </Button>
            </div>
            <p className="text-xs text-muted-foreground">
              No es obligatorio: si no la cerrás, sus objetivos quedan como planificados, no como dados.
            </p>
          </>
        )}
        {error && <p className="text-destructive">{error}</p>}
      </div>
    </SectionCard>
  )
}
