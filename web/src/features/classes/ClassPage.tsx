import { useCallback, useEffect, useState } from "react"
import { Link, useParams } from "react-router-dom"

import { PageHeader } from "@/components/ui/page-header"
import { SectionCard } from "@/components/ui/section-card"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { formatDateTime, formatShortDate } from "@/lib/utils"
import type { ObjectiveCoverage } from "@/features/annual-plans/annualPlansApi"
import { WhyBlock } from "./WhyBlock"
import {
  classSectionLabels,
  closeClass,
  closingLabels,
  fetchClass,
  type ClassDetail,
  type PartialClosing,
} from "./classesApi"

/**
 * A confirmed class (screen 15, ClickUp 86e3jujh0): the sections the teacher
 * approved, the «¿por qué?» of its proposal, and closing it — one tap
 * (P15-DEC-03): «¿Se dio lo que planificaste?» Todo, Una parte or Nada. Only
 * «Una parte» asks, per objective, «Se dio» or «Se empezó»; one left as «No se
 * tocó» stays planned with no record (P15-PRO-05). Optional, enabled from the day
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
                    {o.coverage && (
                      <Badge tone={o.coverage === "full" ? "success" : "neutral"}>{objectiveCoverageLabels[o.coverage]}</Badge>
                    )}
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

/** What «Una parte» records for one objective; unmarked = stays planned. */
type ObjectiveMark = "given" | "started"

/** What the closing recorded for one objective of the class. */
const objectiveCoverageLabels: Record<ObjectiveCoverage, string> = {
  full: "Se dio",
  partial: "Se empezó",
  none: "No se dio",
}

const objectiveMarkLabels: Record<ObjectiveMark | "untouched", string> = {
  given: "Se dio",
  started: "Se empezó",
  untouched: "No se tocó",
}

function CloseClassCard({ detail, onClosed }: { detail: ClassDetail; onClosed: (next: ClassDetail) => void }) {
  const [choosingPart, setChoosingPart] = useState(false)
  const [marks, setMarks] = useState<Record<number, ObjectiveMark>>({})
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [reclosing, setReclosing] = useState(false)

  async function submit(result: ObjectiveCoverage, partial: PartialClosing = { given: [], started: [] }) {
    setBusy(true)
    setError(null)
    try {
      onClosed(await closeClass(detail.id, result, partial))
      setChoosingPart(false)
      setReclosing(false)
      setMarks({})
    } catch {
      setError("No pudimos cerrar la clase. Probá de nuevo.")
    } finally {
      setBusy(false)
    }
  }

  function onPart() {
    // With no objectives to mark, «Una parte» is one tap too.
    if (detail.objectives.length === 0) {
      void submit("partial")
      return
    }
    // Correcting an earlier «Una parte»: start from what it recorded.
    setMarks(
      detail.closing_result === "partial"
        ? Object.fromEntries(
            detail.objectives.flatMap((o): [number, ObjectiveMark][] =>
              o.coverage === "full" ? [[o.id, "given"]] : o.coverage === "partial" ? [[o.id, "started"]] : [],
            ),
          )
        : {},
    )
    setChoosingPart(true)
  }

  function mark(objectiveId: number, value: ObjectiveMark | "untouched") {
    setMarks((current) => {
      const next = { ...current }
      if (value === "untouched") delete next[objectiveId]
      else next[objectiveId] = value
      return next
    })
  }

  function savePart() {
    const ids = (value: ObjectiveMark) =>
      detail.objectives.filter((o) => marks[o.id] === value).map((o) => o.id)
    void submit("partial", { given: ids("given"), started: ids("started") })
  }

  const closed = detail.closing_result !== null

  return (
    // One rhythm (gap-4) between title, question, objectives, note and buttons.
    <SectionCard title="Cerrar la clase" className="gap-4">
      <div className="grid gap-4 text-sm">
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
          <>
            <p id="close-part-question" className="font-medium">
              ¿Qué pasó con cada objetivo?
            </p>
            <ul className="grid gap-4" aria-labelledby="close-part-question">
              {detail.objectives.map((o) => {
                const current = marks[o.id] ?? "untouched"
                return (
                  <li key={o.id} className="grid gap-2">
                    <span id={`objective-${o.id}`}>{o.description}</span>
                    <div role="radiogroup" aria-labelledby={`objective-${o.id}`} className="flex flex-wrap gap-x-4 gap-y-2">
                      {(["given", "started", "untouched"] as const).map((value) => (
                        <label key={value} className="flex items-center gap-2">
                          <input
                            type="radio"
                            name={`objective-${o.id}`}
                            checked={current === value}
                            onChange={() => mark(o.id, value)}
                          />
                          {objectiveMarkLabels[value]}
                        </label>
                      ))}
                    </div>
                  </li>
                )
              })}
            </ul>
            <p className="text-xs text-muted-foreground">
              Lo que se empezó queda empezado: lo terminás después con «Lo terminé» en el Programa anual. Lo que no se
              tocó sigue planificado.
            </p>
            <div className="flex flex-wrap gap-2">
              <Button size="sm" disabled={busy} onClick={savePart}>
                Guardar «Una parte»
              </Button>
              <Button size="sm" variant="ghost" onClick={() => setChoosingPart(false)}>
                Cancelar
              </Button>
            </div>
          </>
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
