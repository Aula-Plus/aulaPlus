import { useEffect, useMemo, useRef, useState } from "react"
import { Button } from "@/components/ui/button"
import { ConfirmDialog } from "@/components/ui/confirm-dialog"
import { SectionCard } from "@/components/ui/section-card"
import { fetchEducationLevels } from "@/features/groups/groupsApi"
import {
  aiRequestTypeLabels,
  type AIInstructionSettings,
  type AIInstructionType,
  type EducationLevelOption,
  type SchoolAIInstruction,
} from "@/types"
import * as aiInstructionsApi from "./aiInstructionsApi"
import { JudgmentPreviewCard } from "./JudgmentPreviewCard"

type ConfigurableType = "judgment" | "assessment"

/** How long after the last keystroke the rule saves on its own (P24-DEC-07). */
const AUTOSAVE_MS = 700

const TONES = [
  { value: "close", label: "Cercano y respetuoso" },
  { value: "descriptive", label: "Descriptivo, en base a evidencia" },
  { value: "formal", label: "Formal" },
] as const

const PERSONS = [
  { value: "second", label: "Segunda persona: «Lograste…»" },
  { value: "third", label: "Tercera persona: «Lucía logró…»" },
] as const

const LENGTHS = [
  { value: "short", label: "Breve: 2 a 3 líneas" },
  { value: "medium", label: "Media: 4 a 5 líneas" },
  { value: "long", label: "Extensa: un párrafo" },
] as const

const texts: Record<ConfigurableType, { title: string; newRule: string }> = {
  judgment: {
    title: "Cómo querés que escriba los juicios",
    newRule: "+ Nueva regla para un nivel o rango",
  },
  assessment: {
    title: "Cómo querés que arme las evaluaciones",
    newRule: "+ Nueva regla para un nivel o grado",
  },
}

/** «Toda la institución», «Primaria», «De 1° a 6° de Primaria», «Solo 3° de Bachillerato». */
function ruleScopeLabel(
  rule: Pick<SchoolAIInstruction, "education_level_id" | "school_grade_ids">,
  levels: EducationLevelOption[],
): string {
  if (rule.education_level_id === null) return "Toda la institución"
  const level = levels.find((l) => l.id === rule.education_level_id)
  if (!level) return "Un nivel"
  const grades = level.grades.filter((g) => rule.school_grade_ids.includes(g.id))
  if (grades.length === 0) return `Todo ${level.label}`
  if (grades.length === 1) return `Solo ${grades[0].label}`
  return `De ${grades[0].label} a ${grades[grades.length - 1].label}`
}

/**
 * Screen 24, tabs «Juicios» (P24-JUI-01/02) and «Evaluaciones» (P24-EVA-02)
 * — ClickUp 1dfd3mc4bh6. One rule for the whole institution and, if needed,
 * others for a level or a range of its grades; the most specific wins
 * (P24-DEC-02). Each rule saves on its own while Direction writes
 * (P24-DEC-07) and shows «Así le llega a la IA», exactly what the model
 * receives. The other kinds of request have their place, empty for now.
 */
export function AIInstructionsTab({ type }: { type: ConfigurableType }) {
  const [types, setTypes] = useState<AIInstructionType[] | null>(null)
  const [levels, setLevels] = useState<EducationLevelOption[]>([])
  const [selected, setSelected] = useState<number | "institution">("institution")
  const [error, setError] = useState<string | null>(null)
  const [adding, setAdding] = useState(false)

  useEffect(() => {
    Promise.all([aiInstructionsApi.fetchAIInstructions(), fetchEducationLevels()])
      .then(([loaded, loadedLevels]) => {
        setTypes(loaded)
        setLevels(loadedLevels.data)
      })
      .catch(() => setError("No pudimos cargar las indicaciones del colegio."))
  }, [])

  const entry = types?.find((t) => t.request_type === type) ?? null
  const rules = useMemo(() => entry?.rules ?? [], [entry])
  const institution = rules.find((rule) => rule.education_level_id === null) ?? null
  const scoped = rules.filter((rule) => rule.education_level_id !== null)
  const current = selected === "institution" ? institution : (rules.find((r) => r.id === selected) ?? null)
  const ruleTabs: { key: number | "institution"; label: string }[] = [
    { key: "institution", label: "Toda la institución" },
    ...scoped.map((rule) => ({ key: rule.id, label: ruleScopeLabel(rule, levels) })),
  ]

  function replaceRule(rule: SchoolAIInstruction) {
    setTypes((all) =>
      all?.map((t) =>
        t.request_type !== type
          ? t
          : {
              ...t,
              rules: t.rules.some((r) => r.id === rule.id)
                ? t.rules.map((r) => (r.id === rule.id ? rule : r))
                : [...t.rules, rule],
            },
      ) ?? null,
    )
  }

  function removeRule(id: number) {
    setTypes((all) =>
      all?.map((t) => (t.request_type !== type ? t : { ...t, rules: t.rules.filter((r) => r.id !== id) })) ??
      null,
    )
    setSelected("institution")
  }

  if (error) {
    return (
      <p role="alert" className="text-sm text-destructive">
        {error}
      </p>
    )
  }

  if (types === null || entry === null) {
    return <p className="text-sm text-muted-foreground">Cargando…</p>
  }

  return (
    <div className="grid gap-6">
      <SectionCard title={texts[type].title}>
        <div role="tablist" aria-label="Reglas" className="mb-4 flex flex-wrap gap-2">
          {ruleTabs.map((item) => (
              <button
                key={String(item.key)}
                type="button"
                role="tab"
                aria-selected={selected === item.key}
                onClick={() => setSelected(item.key)}
                className={
                  "rounded-md px-3 py-1.5 text-sm font-medium transition-colors " +
                  (selected === item.key
                    ? "bg-primary text-primary-foreground"
                    : "bg-muted text-muted-foreground")
                }
              >
                {item.label}
              </button>
            ))}
          <Button type="button" variant="outline" size="sm" onClick={() => setAdding((v) => !v)}>
            {texts[type].newRule}
          </Button>
        </div>

        {adding && (
          <NewRuleForm
            type={type}
            levels={levels}
            onCancel={() => setAdding(false)}
            onCreated={(rule) => {
              replaceRule(rule)
              setSelected(rule.id)
              setAdding(false)
            }}
          />
        )}

        <p className="mb-3 text-sm text-muted-foreground">
          {selected === "institution"
            ? "Es la regla de base: se usa en todos los grupos que no tienen una regla para su nivel o su grado."
            : "Se usa en los grupos de este nivel o rango. Si hay otra regla más específica para un grupo, gana esa."}
        </p>

        <RuleEditor
          key={String(selected)}
          type={type}
          rule={current}
          defaultPromptText={entry.default_prompt_text}
          onSaved={replaceRule}
          onDeleted={removeRule}
          isInstitution={selected === "institution"}
          scopeLabel={current ? ruleScopeLabel(current, levels) : "Toda la institución"}
        />
      </SectionCard>

      {type === "judgment" && <JudgmentPreviewCard />}

      <SectionCard title="Otros pedidos a la IA">
        <p className="mb-3 text-sm text-muted-foreground">
          Cada pedido a la IA va a tener su indicación del colegio. Estos todavía no se configuran: se suman
          cuando se defina qué va en su base.
        </p>
        <ul className="grid gap-1 text-sm">
          {types
            .filter((t) => !t.configurable)
            .map((t) => (
              <li key={t.request_type} className="flex items-center justify-between rounded-md border px-3 py-2">
                <span>{aiRequestTypeLabels[t.request_type]}</span>
                <span className="text-xs text-muted-foreground">Todavía sin indicación</span>
              </li>
            ))}
        </ul>
      </SectionCard>
    </div>
  )
}

function NewRuleForm({
  type,
  levels,
  onCancel,
  onCreated,
}: {
  type: ConfigurableType
  levels: EducationLevelOption[]
  onCancel: () => void
  onCreated: (rule: SchoolAIInstruction) => void
}) {
  const [levelId, setLevelId] = useState<string>("")
  const [from, setFrom] = useState<string>("")
  const [to, setTo] = useState<string>("")
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const level = levels.find((l) => String(l.id) === levelId) ?? null
  const fromIndex = level?.grades.findIndex((g) => String(g.id) === from) ?? -1
  const toIndex = level?.grades.findIndex((g) => String(g.id) === to) ?? -1

  async function create() {
    if (!level) {
      setError("Elegí un nivel.")
      return
    }
    let gradeIds: number[] = []
    if (fromIndex >= 0 || toIndex >= 0) {
      const start = fromIndex >= 0 ? fromIndex : toIndex
      const end = toIndex >= 0 ? toIndex : fromIndex
      if (end < start) {
        setError("El grado «hasta» tiene que ser igual o posterior al grado «desde».")
        return
      }
      gradeIds = level.grades.slice(start, end + 1).map((g) => g.id)
    }
    setSaving(true)
    setError(null)
    try {
      onCreated(
        await aiInstructionsApi.createAIInstruction({
          request_type: type,
          education_level_id: level.id,
          school_grade_ids: gradeIds,
        }),
      )
    } catch {
      setError("No pudimos crear la regla. Revisá que no haya otra para el mismo nivel o rango.")
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="mb-4 grid gap-3 rounded-md border p-3">
      <div className="flex flex-wrap gap-3">
        <label className="grid gap-1 text-sm">
          Nivel
          <select
            className="rounded-md border px-2 py-1"
            value={levelId}
            onChange={(event) => {
              setLevelId(event.target.value)
              setFrom("")
              setTo("")
            }}
          >
            <option value="">Elegí un nivel</option>
            {levels.map((l) => (
              <option key={l.id} value={l.id}>
                {l.label}
              </option>
            ))}
          </select>
        </label>
        {level && level.grades.length > 0 && (
          <>
            <label className="grid gap-1 text-sm">
              Desde
              <select className="rounded-md border px-2 py-1" value={from} onChange={(e) => setFrom(e.target.value)}>
                <option value="">Todo el nivel</option>
                {level.grades.map((g) => (
                  <option key={g.id} value={g.id}>
                    {g.label}
                  </option>
                ))}
              </select>
            </label>
            <label className="grid gap-1 text-sm">
              Hasta
              <select className="rounded-md border px-2 py-1" value={to} onChange={(e) => setTo(e.target.value)}>
                <option value="">{from ? "Solo ese grado" : "Todo el nivel"}</option>
                {level.grades.map((g) => (
                  <option key={g.id} value={g.id}>
                    {g.label}
                  </option>
                ))}
              </select>
            </label>
          </>
        )}
      </div>
      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
      <div className="flex gap-2">
        <Button type="button" size="sm" onClick={create} disabled={saving}>
          {saving ? "Creando…" : "Crear regla"}
        </Button>
        <Button type="button" size="sm" variant="ghost" onClick={onCancel}>
          Cancelar
        </Button>
      </div>
    </div>
  )
}

function RuleEditor({
  type,
  rule,
  defaultPromptText,
  onSaved,
  onDeleted,
  isInstitution,
  scopeLabel,
}: {
  type: ConfigurableType
  rule: SchoolAIInstruction | null
  defaultPromptText: string | null
  onSaved: (rule: SchoolAIInstruction) => void
  onDeleted: (id: number) => void
  isInstitution: boolean
  scopeLabel: string
}) {
  const [settings, setSettings] = useState<AIInstructionSettings>(rule?.settings ?? {})
  const [status, setStatus] = useState<"idle" | "saving" | "saved" | "error">("idle")
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const ruleId = useRef<number | null>(rule?.id ?? null)
  const pending = useRef<Promise<unknown> | null>(null)
  const unsaved = useRef<AIInstructionSettings | null>(null)
  const [deleteFailed, setDeleteFailed] = useState(false)

  // «Se guarda solo» (P24-DEC-07): leaving the rule or the tab before the
  // debounce fires still saves the last change instead of dropping it.
  useEffect(
    () => () => {
      if (timer.current) clearTimeout(timer.current)
      if (unsaved.current) void save(unsaved.current)
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps -- runs once, on unmount
    [],
  )

  function change(patch: AIInstructionSettings) {
    const next = { ...settings, ...patch }
    setSettings(next)
    unsaved.current = next
    if (timer.current) clearTimeout(timer.current)
    timer.current = setTimeout(() => void save(next), AUTOSAVE_MS)
  }

  async function save(next: AIInstructionSettings) {
    timer.current = null
    if (unsaved.current === next) unsaved.current = null
    setStatus("saving")
    // The institution rule exists only once something is written: the first
    // save creates it; later saves wait for it.
    if (pending.current) await pending.current.catch(() => undefined)
    const request =
      ruleId.current === null
        ? aiInstructionsApi.createAIInstruction({
            request_type: type,
            education_level_id: null,
            school_grade_ids: [],
            settings: next,
          })
        : aiInstructionsApi.updateAIInstruction(ruleId.current, next)
    pending.current = request
    try {
      const saved = await request
      ruleId.current = saved.id
      onSaved(saved)
      setStatus("saved")
    } catch {
      setStatus("error")
    }
  }

  const promptText = rule?.prompt_text ?? defaultPromptText

  return (
    <div className="grid gap-4">
      {type === "judgment" ? (
        <JudgmentFields settings={settings} onChange={change} />
      ) : (
        <AssessmentFields settings={settings} onChange={change} />
      )}

      <div className="flex flex-wrap items-center gap-3">
        <span role="status" className="text-xs text-muted-foreground">
          {status === "saving"
            ? "Guardando…"
            : status === "saved"
              ? "Guardado. Se aplica desde el próximo borrador."
              : status === "error"
                ? ""
                : "Se guarda solo y se aplica desde el próximo borrador."}
        </span>
        {status === "error" && (
          <span role="alert" className="text-xs text-destructive">
            No pudimos guardar el último cambio. Revisá que ningún campo sea demasiado largo.
          </span>
        )}
        {deleteFailed && (
          <span role="alert" className="text-xs text-destructive">
            No pudimos borrar la regla. Probá de nuevo.
          </span>
        )}
        {!isInstitution && rule && (
          <ConfirmDialog
            trigger={
              <Button type="button" variant="ghost" size="sm">
                Borrar esta regla
              </Button>
            }
            title={`¿Borrar la regla «${scopeLabel}»?`}
            description="Los grupos de este nivel o rango pasan a usar la regla más específica que quede, o la de toda la institución."
            confirmLabel="Borrar"
            onConfirm={() => {
              setDeleteFailed(false)
              void aiInstructionsApi
                .deleteAIInstruction(rule.id)
                .then(() => onDeleted(rule.id))
                .catch(() => setDeleteFailed(true))
            }}
          />
        )}
      </div>

      <details className="rounded-md border p-3">
        <summary className="cursor-pointer text-sm font-medium">Así le llega a la IA</summary>
        <p className="mt-2 text-xs text-muted-foreground">
          Es exactamente lo que recibe la IA con esta regla. La parte fija de Aula+ no se puede cambiar.
        </p>
        <pre className="mt-2 whitespace-pre-wrap break-words text-xs">{promptText}</pre>
      </details>
    </div>
  )
}

function TextField({
  label,
  value,
  onChange,
  placeholder,
  multiline = false,
  hint,
}: {
  label: string
  value: string | null | undefined
  onChange: (value: string) => void
  placeholder?: string
  multiline?: boolean
  hint?: string
}) {
  return (
    <label className="grid gap-1 text-sm">
      <span className="font-medium">{label}</span>
      {multiline ? (
        <textarea
          className="min-h-20 rounded-md border px-2 py-1"
          value={value ?? ""}
          placeholder={placeholder}
          maxLength={2000}
          onChange={(event) => onChange(event.target.value)}
        />
      ) : (
        <input
          className="rounded-md border px-2 py-1"
          value={value ?? ""}
          placeholder={placeholder}
          maxLength={500}
          onChange={(event) => onChange(event.target.value)}
        />
      )}
      {hint && <span className="text-xs text-muted-foreground">{hint}</span>}
    </label>
  )
}

function ChoiceField<T extends string>({
  label,
  value,
  options,
  onChange,
}: {
  label: string
  value: T | null | undefined
  options: readonly { value: T; label: string }[]
  onChange: (value: T | null) => void
}) {
  return (
    <label className="grid gap-1 text-sm">
      <span className="font-medium">{label}</span>
      <select
        className="rounded-md border px-2 py-1"
        value={value ?? ""}
        onChange={(event) => onChange(event.target.value === "" ? null : (event.target.value as T))}
      >
        <option value="">Sin indicar</option>
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </label>
  )
}

function JudgmentFields({
  settings,
  onChange,
}: {
  settings: AIInstructionSettings
  onChange: (patch: AIInstructionSettings) => void
}) {
  return (
    <div className="grid gap-3">
      <div className="grid gap-3 sm:grid-cols-3">
        <ChoiceField label="Tono" value={settings.tone} options={TONES} onChange={(tone) => onChange({ tone })} />
        <ChoiceField
          label="Persona"
          value={settings.person}
          options={PERSONS}
          onChange={(person) => onChange({ person })}
        />
        <ChoiceField
          label="Extensión"
          value={settings.length}
          options={LENGTHS}
          onChange={(length) => onChange({ length })}
        />
      </div>
      <TextField
        label="Palabras o expresiones a usar"
        value={settings.words_to_use}
        placeholder="logró, avanzó, se destaca, le proponemos, puede seguir mejorando"
        onChange={(words_to_use) => onChange({ words_to_use })}
      />
      <TextField
        label="Palabras a evitar"
        value={settings.words_to_avoid}
        placeholder="flojo, vago, desinteresado, no sabe, nunca, siempre, problema"
        hint="Si aparecen en un borrador, se cambian por una expresión neutra o se saca la frase."
        onChange={(words_to_avoid) => onChange({ words_to_avoid })}
      />
      <TextField
        multiline
        label="Qué querés lograr con los juicios"
        value={settings.goal}
        placeholder="Que la familia entienda qué aprendió, qué le costó y qué puede hacer para seguir avanzando."
        onChange={(goal) => onChange({ goal })}
      />
      <TextField
        multiline
        label="Qué querés evitar"
        value={settings.avoid}
        placeholder="Comparar con compañeros, juzgar a la persona en lugar del trabajo, repetir la nota con palabras."
        onChange={(avoid) => onChange({ avoid })}
      />
      <TextField
        multiline
        label="Un juicio que te guste, como modelo (opcional)"
        value={settings.model_example}
        hint="Se usa como referencia de estilo."
        onChange={(model_example) => onChange({ model_example })}
      />
      <label className="flex items-center gap-2 text-sm">
        <input
          type="checkbox"
          checked={settings.show_rubric ?? false}
          onChange={(event) => onChange({ show_rubric: event.target.checked })}
        />
        Mostrarle al docente la rúbrica mientras escribe
      </label>
      <p className="text-xs text-muted-foreground">
        Lo que Aula+ no deja cambiar: el juicio puede nombrar un ajuste y lo que el alumno trabajó con él,
        pero nunca dice por qué lo tiene ni qué diagnóstico lo originó.
      </p>
    </div>
  )
}

function AssessmentFields({
  settings,
  onChange,
}: {
  settings: AIInstructionSettings
  onChange: (patch: AIInstructionSettings) => void
}) {
  return (
    <div className="grid gap-3">
      <TextField
        multiline
        label="Qué querés lograr con las evaluaciones"
        value={settings.goal}
        placeholder="Ítems que vayan de lo más simple a lo más exigente, que se puedan resolver de más de una forma."
        onChange={(goal) => onChange({ goal })}
      />
      <TextField
        multiline
        label="Qué querés evitar"
        value={settings.avoid}
        placeholder="Preguntas de memoria sin contexto, consignas largas o con doble negación, y preguntas trampa."
        onChange={(avoid) => onChange({ avoid })}
      />
      <TextField
        label="Palabras que no van en las consignas"
        value={settings.banned_words}
        placeholder="trampa, obvio, fácil, simplemente"
        onChange={(banned_words) => onChange({ banned_words })}
      />
      <p className="text-xs text-muted-foreground">
        La indicación orienta a la IA en cada evaluación que genera. No cambia la escala, los criterios ni la
        regla de una sola evaluación accesible, que define cada programa. Lo específico de cada evaluación lo
        elige el docente al generarla.
      </p>
    </div>
  )
}
