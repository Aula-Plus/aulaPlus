import { useState } from "react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { SingleSelect } from "@/components/ui/single-select"
import { Textarea } from "@/components/ui/textarea"
import { formatShortDate } from "@/lib/utils"
import { followUpStageStatusLabels } from "@/types"
import type { FollowUpStage, ScheduledFollowUpDetail, StaffMember } from "@/types"
import * as detailApi from "./followUpDetailApi"
import type { StageInput } from "./followUpDetailApi"
import { personLabel, type RunAction } from "./followUpDetailUtils"

interface FollowUpStagesSectionProps {
  detail: ScheduledFollowUpDetail
  candidates: StaffMember[]
  run: RunAction
}

/**
 * Stages ("etapas") of a follow-up inside FollowUpDetailDialog (ClickUp
 * 86e3qkwna): each with its responsible, date, progress notes and closure,
 * plus adding, editing and subdividing. What each person may do comes from
 * the server's `can` flags; the backend re-checks every action.
 */
export function FollowUpStagesSection({ detail, candidates, run }: FollowUpStagesSectionProps) {
  const [adding, setAdding] = useState(false)

  return (
    <section className="grid gap-3" aria-labelledby="follow-up-stages-heading">
      <div className="flex items-center justify-between gap-2">
        <h3 id="follow-up-stages-heading" className="font-semibold">
          Etapas
        </h3>
        {detail.can.add_stage && !adding && (
          <Button type="button" size="sm" variant="outline" onClick={() => setAdding(true)}>
            Agregar etapa
          </Button>
        )}
      </div>

      {adding && (
        <StageForm
          idPrefix="new-stage"
          candidates={candidates}
          submitLabel="Guardar etapa"
          onCancel={() => setAdding(false)}
          onSubmit={async (input) => {
            if (await run(() => detailApi.addStage(detail.id, input))) setAdding(false)
          }}
        />
      )}

      {detail.stages.length === 0 ? (
        <p className="text-sm text-muted-foreground">No hay etapas visibles.</p>
      ) : (
        <ol className="grid gap-3">
          {detail.stages.map((stage, index) => (
            <StageItem
              key={stage.id}
              stage={stage}
              number={index + 1}
              parent={detail.stages.find((other) => other.id === stage.parent_stage_id)}
              candidates={candidates}
              run={run}
            />
          ))}
        </ol>
      )}
    </section>
  )
}

type Mode = "idle" | "note" | "edit" | "split" | "close"

function StageItem({
  stage,
  number,
  parent,
  candidates,
  run,
}: {
  stage: FollowUpStage
  number: number
  parent: FollowUpStage | undefined
  candidates: StaffMember[]
  run: RunAction
}) {
  const [mode, setMode] = useState<Mode>("idle")
  const [text, setText] = useState("")
  const tone = stage.status === "done" ? "success" : stage.status === "split" ? "neutral" : "warning"

  function reset() {
    setMode("idle")
    setText("")
  }

  return (
    <li className="grid gap-2 rounded-md border p-3 text-sm" aria-label={`Etapa ${number}`}>
      <div className="flex items-start justify-between gap-3">
        <div className="grid gap-0.5">
          <p className="font-medium whitespace-pre-wrap">{stage.description}</p>
          <p className="text-xs text-muted-foreground">
            Responsable: {personLabel(stage.responsible)} · Fecha: {formatShortDate(stage.due_date)}
          </p>
          {parent && <p className="text-xs text-muted-foreground">Surge de subdividir: {parent.description}</p>}
        </div>
        <Badge tone={tone}>{followUpStageStatusLabels[stage.status]}</Badge>
      </div>

      {stage.status === "done" && (
        <div className="rounded-md bg-muted/50 p-2 text-xs">
          <p className="font-medium">Qué se hizo</p>
          <p className="whitespace-pre-wrap">{stage.conclusion}</p>
          <p className="mt-1 text-muted-foreground">
            Cerrada el {formatShortDate(stage.closed_at)}
            {stage.closed_by && ` por ${stage.closed_by.name}`}
          </p>
        </div>
      )}

      {stage.notes.length > 0 && (
        <div className="grid gap-1">
          <p className="text-xs font-medium">Avances y aclaraciones</p>
          <ul className="grid gap-1 border-l pl-3">
            {stage.notes.map((note) => (
              <li key={note.id} className="text-xs">
                <span className="text-muted-foreground">
                  {note.author?.name ?? "—"} · {formatShortDate(note.created_at)}:
                </span>{" "}
                <span className="whitespace-pre-wrap">{note.body}</span>
              </li>
            ))}
          </ul>
        </div>
      )}

      {mode === "idle" && (
        <div className="flex flex-wrap gap-2">
          {stage.can.add_note && (
            <Button type="button" size="sm" variant="outline" onClick={() => setMode("note")}>
              Agregar avance
            </Button>
          )}
          {stage.can.update && (
            <Button type="button" size="sm" variant="outline" onClick={() => setMode("edit")}>
              Editar
            </Button>
          )}
          {stage.can.split && (
            <Button type="button" size="sm" variant="outline" onClick={() => setMode("split")}>
              Subdividir
            </Button>
          )}
          {stage.can.close && (
            <Button type="button" size="sm" variant="outline" onClick={() => setMode("close")}>
              Cerrar etapa
            </Button>
          )}
        </div>
      )}

      {(mode === "note" || mode === "close") && (
        <div className="grid gap-2 border-t pt-2">
          <Label htmlFor={`stage-${stage.id}-${mode}`} className="text-xs">
            {mode === "note" ? "Avance o aclaración" : "Qué se hizo"}
          </Label>
          <Textarea id={`stage-${stage.id}-${mode}`} value={text} onChange={(event) => setText(event.target.value)} />
          <div className="flex gap-2">
            <Button
              type="button"
              size="sm"
              disabled={!text.trim()}
              onClick={async () => {
                const body = text.trim()
                const ok = await run(() =>
                  mode === "note" ? detailApi.addStageNote(stage.id, body) : detailApi.closeStage(stage.id, body),
                )
                if (ok) reset()
              }}
            >
              {mode === "note" ? "Guardar avance" : "Cerrar etapa"}
            </Button>
            <Button type="button" size="sm" variant="outline" onClick={reset}>
              Cancelar
            </Button>
          </div>
        </div>
      )}

      {mode === "edit" && (
        <StageForm
          idPrefix={`stage-${stage.id}-edit`}
          candidates={candidates}
          initial={{
            description: stage.description,
            responsible_id: stage.responsible?.id ?? 0,
            due_date: stage.due_date,
          }}
          submitLabel="Guardar cambios"
          onCancel={reset}
          onSubmit={async (input) => {
            if (await run(() => detailApi.updateStage(stage.id, input))) reset()
          }}
        />
      )}

      {mode === "split" && <SplitForm stage={stage} candidates={candidates} run={run} onDone={reset} />}
    </li>
  )
}

function SplitForm({
  stage,
  candidates,
  run,
  onDone,
}: {
  stage: FollowUpStage
  candidates: StaffMember[]
  run: RunAction
  onDone: () => void
}) {
  const blank = (): StageInput => ({ description: "", responsible_id: stage.responsible?.id ?? 0, due_date: stage.due_date })
  const [parts, setParts] = useState<StageInput[]>([blank(), blank()])
  const valid = parts.every((part) => part.description.trim() && part.responsible_id && part.due_date)

  function update(index: number, patch: Partial<StageInput>) {
    setParts((prev) => prev.map((part, i) => (i === index ? { ...part, ...patch } : part)))
  }

  return (
    <div className="grid gap-3 border-t pt-2">
      <p className="text-xs text-muted-foreground">
        La etapa original queda en el historial como subdividida, con sus avances.
      </p>
      {parts.map((part, index) => (
        <StageFields
          key={index}
          idPrefix={`stage-${stage.id}-part-${index}`}
          legend={`Parte ${index + 1}`}
          value={part}
          candidates={candidates}
          onChange={(patch) => update(index, patch)}
        />
      ))}
      <div className="flex flex-wrap gap-2">
        <Button type="button" size="sm" variant="outline" onClick={() => setParts((prev) => [...prev, blank()])}>
          Agregar otra parte
        </Button>
        <Button
          type="button"
          size="sm"
          disabled={!valid}
          onClick={async () => {
            const payload = parts.map((part) => ({ ...part, description: part.description.trim() }))
            if (await run(() => detailApi.splitStage(stage.id, payload))) onDone()
          }}
        >
          Subdividir etapa
        </Button>
        <Button type="button" size="sm" variant="outline" onClick={onDone}>
          Cancelar
        </Button>
      </div>
    </div>
  )
}

function StageForm({
  idPrefix,
  candidates,
  initial,
  submitLabel,
  onSubmit,
  onCancel,
}: {
  idPrefix: string
  candidates: StaffMember[]
  initial?: StageInput
  submitLabel: string
  onSubmit: (input: StageInput) => Promise<void>
  onCancel: () => void
}) {
  const [value, setValue] = useState<StageInput>(initial ?? { description: "", responsible_id: 0, due_date: "" })
  const valid = value.description.trim() && value.responsible_id && value.due_date

  return (
    <div className="grid gap-3 rounded-md border p-3">
      <StageFields
        idPrefix={idPrefix}
        value={value}
        candidates={candidates}
        onChange={(patch) => setValue((prev) => ({ ...prev, ...patch }))}
      />
      <div className="flex gap-2">
        <Button
          type="button"
          size="sm"
          disabled={!valid}
          onClick={() => onSubmit({ ...value, description: value.description.trim() })}
        >
          {submitLabel}
        </Button>
        <Button type="button" size="sm" variant="outline" onClick={onCancel}>
          Cancelar
        </Button>
      </div>
    </div>
  )
}

/**
 * Description, responsible and date of a stage. Candidates are the people who
 * already see the student (backend-enforced on save as well).
 */
function StageFields({
  idPrefix,
  legend,
  value,
  candidates,
  onChange,
}: {
  idPrefix: string
  legend?: string
  value: StageInput
  candidates: StaffMember[]
  onChange: (patch: Partial<StageInput>) => void
}) {
  const options = candidates.map((person) => ({ value: String(person.id), label: personLabel(person) }))

  return (
    <fieldset className="grid gap-2">
      {legend && <legend className="text-xs font-medium">{legend}</legend>}
      <div className="grid gap-1.5">
        <Label htmlFor={`${idPrefix}-description`} className="text-xs">
          Qué hay que hacer
        </Label>
        <Textarea
          id={`${idPrefix}-description`}
          value={value.description}
          onChange={(event) => onChange({ description: event.target.value })}
        />
      </div>
      <div className="flex flex-wrap gap-3">
        <div className="grid gap-1.5">
          <Label htmlFor={`${idPrefix}-responsible`} className="text-xs">
            Responsable de la etapa
          </Label>
          <SingleSelect
            id={`${idPrefix}-responsible`}
            options={options}
            value={value.responsible_id ? String(value.responsible_id) : ""}
            onChange={(id) => onChange({ responsible_id: Number(id) })}
            placeholder="Elegí una persona"
            className="w-56"
          />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor={`${idPrefix}-due-date`} className="text-xs">
            Fecha de la etapa
          </Label>
          <Input
            id={`${idPrefix}-due-date`}
            type="date"
            value={value.due_date}
            onChange={(event) => onChange({ due_date: event.target.value })}
            className="w-44"
          />
        </div>
      </div>
    </fieldset>
  )
}
