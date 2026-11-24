import { useEffect, useRef, useState } from "react"
import { Button } from "@/components/ui/button"
import { SectionCard } from "@/components/ui/section-card"
import type { JudgmentPreviewGroupOption, JudgmentStylePreview } from "@/types"
import * as aiInstructionsApi from "./aiInstructionsApi"

const POLL_MS = 2000

/**
 * «Así quedaría un juicio con tu estilo (alumno de ejemplo)» — screen 24,
 * P24-JUI-02 / P24-DEC-110 (ClickUp 1dfd3mc4bh6). Generated only when
 * Direction taps «Ver ejemplo», never on each change: an invented student,
 * with the subject and the unit names of the group Direction picks. No data
 * of a real student reaches the AI.
 */
export function JudgmentPreviewCard() {
  const [options, setOptions] = useState<JudgmentPreviewGroupOption[] | null>(null)
  const [choice, setChoice] = useState("")
  const [preview, setPreview] = useState<JudgmentStylePreview | null>(null)
  const [error, setError] = useState<string | null>(null)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => {
    aiInstructionsApi
      .fetchJudgmentPreviewOptions()
      .then(setOptions)
      .catch(() => setOptions([]))
    return () => {
      if (timer.current) clearTimeout(timer.current)
    }
  }, [])

  function poll(id: number) {
    timer.current = setTimeout(() => {
      aiInstructionsApi
        .fetchJudgmentPreview(id)
        .then((next) => {
          setPreview(next)
          if (next.status === "pending") poll(id)
        })
        .catch(() => setError("No pudimos traer el ejemplo."))
    }, POLL_MS)
  }

  async function generate() {
    const [groupId, subjectId] = choice.split(":").map(Number)
    if (!groupId || !subjectId) {
      setError("Elegí un grupo y una materia.")
      return
    }
    setError(null)
    setPreview(null)
    try {
      const created = await aiInstructionsApi.requestJudgmentPreview({ group_id: groupId, subject_id: subjectId })
      setPreview(created)
      if (created.status === "pending") poll(created.id)
    } catch {
      setError("No pudimos generar el ejemplo. Probá de nuevo en un rato.")
    }
  }

  const pending = preview?.status === "pending"

  return (
    <SectionCard title="Así quedaría un juicio con tu estilo (alumno de ejemplo)">
      <p className="mb-3 text-sm text-muted-foreground">
        Se genera solo al tocar «Ver ejemplo». El alumno y lo que escribió el docente son inventados; la
        materia y las unidades son las del grupo que elijas. Los juicios de verdad se escriben con lo que
        el docente dice de cada alumno.
      </p>
      <div className="flex flex-wrap items-end gap-3">
        <label className="grid gap-1 text-sm">
          Grupo y materia
          <select
            className="rounded-md border px-2 py-1"
            value={choice}
            onChange={(event) => setChoice(event.target.value)}
            disabled={options === null}
          >
            <option value="">{options === null ? "Cargando…" : "Elegí un grupo y una materia"}</option>
            {options?.flatMap((group) =>
              group.subjects.map((subject) => (
                <option key={`${group.id}:${subject.id}`} value={`${group.id}:${subject.id}`}>
                  {subject.name} · {group.name}
                  {group.school_year ? ` (${group.school_year})` : ""}
                </option>
              )),
            )}
          </select>
        </label>
        <Button type="button" onClick={generate} disabled={pending || !choice}>
          {pending ? "Generando…" : "Ver ejemplo"}
        </Button>
      </div>
      {options?.length === 0 && (
        <p className="mt-2 text-xs text-muted-foreground">
          Todavía no hay grupos con materias asignadas para armar el ejemplo.
        </p>
      )}
      {error && (
        <p role="alert" className="mt-3 text-sm text-destructive">
          {error}
        </p>
      )}
      {preview?.status === "error" && (
        <p role="alert" className="mt-3 text-sm text-destructive">
          La IA no pudo escribir el ejemplo. Probá de nuevo en un rato.
        </p>
      )}
      {preview?.status === "completed" && preview.draft && (
        <blockquote className="mt-3 rounded-md border-l-4 bg-muted px-3 py-2 text-sm whitespace-pre-wrap">
          {preview.draft}
        </blockquote>
      )}
    </SectionCard>
  )
}
