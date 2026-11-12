import { useEffect, useState } from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { BellRing } from "lucide-react"
import { useAuth } from "@/features/auth/AuthContext"
import { canManageAlertSettings } from "@/lib/permissions"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { ConfirmDialog } from "@/components/ui/confirm-dialog"
import { EmptyState } from "@/components/ui/empty-state"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { PageHeader } from "@/components/ui/page-header"
import { SingleSelect } from "@/components/ui/single-select"
import { fetchEducationLevels } from "@/features/groups/groupsApi"
import { fetchSubjects } from "@/features/subjects/subjectsApi"
import {
  alertRecipientLabels,
  alertTypeLabels,
  type AlertRecipient,
  type AlertRoutingEntry,
  type AlertRule,
  type AlertType,
  type LevelRef,
  type Subject,
} from "@/types"
import * as alertSettingsApi from "./alertSettingsApi"
import { ALERT_RULE_PRESETS, describeAlertRule } from "./alertRuleText"

const RECIPIENTS: AlertRecipient[] = ["teacher", "psychopedagogue", "director"]

const ONLY_DIRECTOR_WARNING =
  "Esta alerta llegará solo a Dirección. El docente y Psicopedagogía no recibirán este aviso."

/** On this screen the performance type reads as the alert's product name. */
const routingTypeLabels: Partial<Record<AlertType, string>> = {
  performance: "Desempeño bajo sostenido",
}

/** Extra context per type on the routing card. */
const routingTypeHints: Partial<Record<AlertType, string>> = {
  difficulty_signal: "Nunca le llega a docentes: se arma con observaciones que quizás no pueden leer.",
}

/**
 * The levels a condition applies to (ClickUp 1dfd3mc2wx0; prototype screen 32,
 * «Niveles donde aplica cada alerta»): one or several from the same list groups
 * and screenings use. All ticked is sent as `null` ("every level"); with no
 * level list loaded the field is hidden and the rule's levels are left as they
 * were (`undefined`).
 */
function useLevelChoice(levels: LevelRef[], initial: number[] | null | undefined) {
  const [selected, setSelected] = useState<number[] | null>(initial ?? null)
  const [error, setError] = useState<string | null>(null)

  // `null` (every level) shows every box ticked, including levels loaded later.
  const checked = selected ?? levels.map((level) => level.id)

  function toggle(id: number) {
    setError(null)
    setSelected(checked.includes(id) ? checked.filter((value) => value !== id) : [...checked, id])
  }

  /** The value to send, or `false` when nothing is ticked (shows the error). */
  function value(): number[] | null | undefined | false {
    if (levels.length === 0) return undefined
    if (checked.length === 0) {
      setError("Elegí al menos un nivel.")
      return false
    }
    if (levels.every((level) => checked.includes(level.id))) return null
    return levels.filter((level) => checked.includes(level.id)).map((level) => level.id)
  }

  return { checked, toggle, error, value }
}

function LevelChecklist({
  idPrefix,
  levels,
  checked,
  error,
  onToggle,
}: {
  idPrefix: string
  levels: LevelRef[]
  checked: number[]
  error: string | null
  onToggle: (id: number) => void
}) {
  if (levels.length === 0) return null

  return (
    <fieldset className="grid gap-2 sm:col-span-2">
      <legend className="mb-2 text-sm font-medium">Niveles donde aplica</legend>
      <div className="flex flex-wrap gap-4">
        {levels.map((level) => {
          const id = `${idPrefix}-level-${level.id}`
          return (
            <div key={level.id} className="flex items-center gap-2">
              <input
                id={id}
                type="checkbox"
                className="size-4"
                checked={checked.includes(level.id)}
                onChange={() => onToggle(level.id)}
              />
              <Label htmlFor={id}>{level.label}</Label>
            </div>
          )
        })}
      </div>
      <p className="text-sm text-muted-foreground">
        Se genera solo para alumnos de grupos de esos niveles. Los grupos sin nivel no entran en
        ninguna alerta.
      </p>
      {error && <p className="text-sm text-destructive">{error}</p>}
    </fieldset>
  )
}

/** "Todos los niveles", or the chosen levels in list order. */
function levelsText(ids: number[] | null | undefined, levels: LevelRef[]): string {
  if (ids == null) return "Todos los niveles"
  const labels = levels.filter((level) => ids.includes(level.id)).map((level) => level.label)
  return labels.length > 0 ? labels.join(", ") : "Niveles elegidos"
}

const ruleSchema = z
  .object({
    condition: z.enum(["consecutive_below", "average_below"]),
    threshold: z.coerce.number({ message: "Ingresá un número" }).min(0, "Tiene que ser 0 o más"),
    consecutive_count: z.coerce.number().int().min(2, "Mínimo 2").max(10, "Máximo 10"),
    period_days: z.coerce.number().int().min(7, "Mínimo 7 días").max(365, "Máximo 365 días"),
    subject_id: z.string(),
  })

type RuleFormInput = z.input<typeof ruleSchema>
type RuleValues = z.output<typeof ruleSchema>

type RuleDraft = Pick<AlertRule, "condition" | "threshold" | "consecutive_count" | "period_days"> & {
  subject_id?: number | null
  education_level_ids?: number[] | null
}

type LevelIds = number[] | null | undefined

function toFormValues(rule: RuleDraft): RuleFormInput {
  return {
    condition: rule.condition === "average_below" ? "average_below" : "consecutive_below",
    threshold: rule.threshold ?? undefined,
    consecutive_count: rule.consecutive_count ?? 3,
    period_days: rule.period_days ?? 90,
    subject_id: rule.subject_id ? String(rule.subject_id) : "",
  }
}

function RuleForm({
  initial,
  subjects,
  levels,
  submitLabel,
  onSubmit,
  onCancel,
}: {
  initial: RuleDraft
  subjects: Subject[]
  levels: LevelRef[]
  submitLabel: string
  onSubmit: (values: RuleValues, levelIds: LevelIds) => Promise<void>
  onCancel: () => void
}) {
  const [formError, setFormError] = useState<string | null>(null)
  const levelChoice = useLevelChoice(levels, initial.education_level_ids)
  const {
    register,
    handleSubmit,
    watch,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<RuleFormInput, unknown, RuleValues>({
    resolver: zodResolver(ruleSchema),
    defaultValues: toFormValues(initial),
  })

  const condition = watch("condition")
  const subjectId = watch("subject_id")

  async function submit(values: RuleValues) {
    setFormError(null)
    const levelIds = levelChoice.value()
    if (levelIds === false) return
    try {
      await onSubmit(values, levelIds)
    } catch {
      setFormError("No pudimos guardar la condición. Revisá los valores.")
    }
  }

  return (
    <form onSubmit={handleSubmit(submit)} className="grid gap-4 sm:grid-cols-2" noValidate>
      <div className="grid gap-2 sm:col-span-2">
        <Label htmlFor="alert-rule-condition">Condición</Label>
        <select
          id="alert-rule-condition"
          className="h-9 rounded-md border bg-background px-3 text-sm"
          {...register("condition")}
        >
          <option value="consecutive_below">Calificaciones seguidas por debajo de un valor</option>
          <option value="average_below">Promedio del período por debajo de un valor</option>
        </select>
      </div>
      {condition === "consecutive_below" ? (
        <div className="grid gap-2">
          <Label htmlFor="alert-rule-count">Cantidad de calificaciones seguidas</Label>
          <Input id="alert-rule-count" type="number" min={2} max={10} {...register("consecutive_count")} />
          {errors.consecutive_count && (
            <p className="text-sm text-destructive">{errors.consecutive_count.message}</p>
          )}
        </div>
      ) : (
        <div className="grid gap-2">
          <Label htmlFor="alert-rule-period">Período (últimos días)</Label>
          <Input id="alert-rule-period" type="number" min={7} max={365} {...register("period_days")} />
          {errors.period_days && <p className="text-sm text-destructive">{errors.period_days.message}</p>}
        </div>
      )}
      <div className="grid gap-2">
        <Label htmlFor="alert-rule-threshold">Por debajo de</Label>
        <Input id="alert-rule-threshold" type="number" step="0.5" min={0} {...register("threshold")} />
        {errors.threshold && <p className="text-sm text-destructive">{errors.threshold.message}</p>}
      </div>
      <div className="grid gap-2 sm:col-span-2">
        <Label htmlFor="alert-rule-subject">Materia</Label>
        <SingleSelect
          id="alert-rule-subject"
          value={subjectId}
          onChange={(value) => setValue("subject_id", value)}
          options={[
            { value: "", label: "Todas las materias" },
            ...subjects.map((subject) => ({ value: String(subject.id), label: subject.name })),
          ]}
        />
      </div>
      <LevelChecklist
        idPrefix="alert-rule"
        levels={levels}
        checked={levelChoice.checked}
        error={levelChoice.error}
        onToggle={levelChoice.toggle}
      />
      {formError && (
        <p role="alert" className="text-sm text-destructive sm:col-span-2">
          {formError}
        </p>
      )}
      <div className="flex gap-2 sm:col-span-2">
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? "Guardando…" : submitLabel}
        </Button>
        <Button type="button" variant="outline" onClick={onCancel}>
          Cancelar
        </Button>
      </div>
    </form>
  )
}

function toInput(values: RuleValues, levelIds: LevelIds) {
  return {
    condition: values.condition,
    threshold: values.threshold,
    consecutive_count: values.condition === "consecutive_below" ? values.consecutive_count : null,
    period_days: values.condition === "average_below" ? values.period_days : null,
    subject_id: values.subject_id ? Number(values.subject_id) : null,
    ...(levelIds !== undefined && { education_level_ids: levelIds }),
  }
}

/**
 * «Señal de dificultad» (ClickUp 86e3qkwpn): "N señales de dificultad en una
 * misma categoría en D días". Both fields start EMPTY — Aula+ suggests no
 * value (product will define one later) — and both are required.
 */
const difficultyRuleSchema = z.object({
  signal_count: z
    .string()
    .trim()
    .min(1, "Completá la cantidad")
    .pipe(z.coerce.number<string>().int("Tiene que ser un número entero").min(2, "Mínimo 2").max(50, "Máximo 50")),
  period_days: z
    .string()
    .trim()
    .min(1, "Completá el período")
    .pipe(z.coerce.number<string>().int("Tiene que ser un número entero").min(1, "Mínimo 1 día").max(365, "Máximo 365 días")),
})

type DifficultyRuleFormInput = z.input<typeof difficultyRuleSchema>
type DifficultyRuleValues = z.output<typeof difficultyRuleSchema>

function DifficultyRuleForm({
  initial,
  levels,
  submitLabel,
  onSubmit,
  onCancel,
}: {
  initial?: Pick<AlertRule, "signal_count" | "period_days" | "education_level_ids">
  levels: LevelRef[]
  submitLabel: string
  onSubmit: (values: DifficultyRuleValues, levelIds: LevelIds) => Promise<void>
  onCancel: () => void
}) {
  const [formError, setFormError] = useState<string | null>(null)
  const levelChoice = useLevelChoice(levels, initial?.education_level_ids)
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<DifficultyRuleFormInput, unknown, DifficultyRuleValues>({
    resolver: zodResolver(difficultyRuleSchema),
    defaultValues: {
      signal_count: initial?.signal_count != null ? String(initial.signal_count) : "",
      period_days: initial?.period_days != null ? String(initial.period_days) : "",
    },
  })

  async function submit(values: DifficultyRuleValues) {
    setFormError(null)
    const levelIds = levelChoice.value()
    if (levelIds === false) return
    try {
      await onSubmit(values, levelIds)
    } catch {
      setFormError("No pudimos guardar la condición. Revisá los valores.")
    }
  }

  return (
    <form onSubmit={handleSubmit(submit)} className="grid gap-4 sm:grid-cols-2" noValidate>
      <div className="grid gap-2">
        <Label htmlFor="difficulty-rule-count">Cantidad de señales de dificultad</Label>
        <Input id="difficulty-rule-count" type="number" min={2} max={50} {...register("signal_count")} />
        {errors.signal_count && <p className="text-sm text-destructive">{errors.signal_count.message}</p>}
      </div>
      <div className="grid gap-2">
        <Label htmlFor="difficulty-rule-period">En cuántos días</Label>
        <Input id="difficulty-rule-period" type="number" min={1} max={365} {...register("period_days")} />
        {errors.period_days && <p className="text-sm text-destructive">{errors.period_days.message}</p>}
      </div>
      <p className="text-sm text-muted-foreground sm:col-span-2">
        Cuenta las señales de dificultad de un mismo alumno en una misma categoría, también las de
        observaciones reservadas, salvo las de «Solo quien escribe». La alerta solo muestra cuántas
        son y en qué categoría.
      </p>
      <LevelChecklist
        idPrefix="difficulty-rule"
        levels={levels}
        checked={levelChoice.checked}
        error={levelChoice.error}
        onToggle={levelChoice.toggle}
      />
      {formError && (
        <p role="alert" className="text-sm text-destructive sm:col-span-2">
          {formError}
        </p>
      )}
      <div className="flex gap-2 sm:col-span-2">
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? "Guardando…" : submitLabel}
        </Button>
        <Button type="button" variant="outline" onClick={onCancel}>
          Cancelar
        </Button>
      </div>
    </form>
  )
}

function toDifficultyInput(values: DifficultyRuleValues, levelIds: LevelIds) {
  return {
    condition: "difficulty_signals" as const,
    signal_count: values.signal_count,
    period_days: values.period_days,
    ...(levelIds !== undefined && { education_level_ids: levelIds }),
  }
}

function RoutingRow({
  entry,
  onSave,
}: {
  entry: AlertRoutingEntry
  onSave: (recipients: AlertRecipient[]) => Promise<void>
}) {
  const [selected, setSelected] = useState<AlertRecipient[]>(entry.recipients)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => setSelected(entry.recipients), [entry.recipients])

  // Teachers are never offered for «Señal de dificultad» (enforced server-side too).
  const options = RECIPIENTS.filter(
    (recipient) => !entry.allowed_recipients || entry.allowed_recipients.includes(recipient),
  )

  const changed =
    selected.length !== entry.recipients.length ||
    selected.some((recipient) => !entry.recipients.includes(recipient))

  function toggle(recipient: AlertRecipient) {
    setSelected((current) =>
      current.includes(recipient)
        ? current.filter((value) => value !== recipient)
        : options.filter((value) => value === recipient || current.includes(value)),
    )
  }

  async function save() {
    setError(null)
    setSaving(true)
    try {
      await onSave(selected)
    } catch {
      setError("No pudimos guardar el cambio.")
    } finally {
      setSaving(false)
    }
  }

  const label = routingTypeLabels[entry.type] ?? alertTypeLabels[entry.type]
  // Direction as the only recipient is allowed, but the school is told who
  // stops hearing about it before saving (ClickUp 86e3qkyrn).
  const onlyDirector = selected.length === 1 && selected[0] === "director"

  return (
    <fieldset className="grid gap-3 rounded-md border p-4">
      <legend className="px-1 text-sm font-medium">
        {label} {entry.is_default && <span className="text-muted-foreground">(por defecto)</span>}
      </legend>
      <div className="flex flex-wrap gap-4">
        {options.map((recipient) => {
          const id = `routing-${entry.type}-${recipient}`
          return (
            <div key={recipient} className="flex items-center gap-2">
              <input
                id={id}
                type="checkbox"
                className="size-4"
                checked={selected.includes(recipient)}
                onChange={() => toggle(recipient)}
              />
              <Label htmlFor={id}>{alertRecipientLabels[recipient]}</Label>
            </div>
          )
        })}
      </div>
      {routingTypeHints[entry.type] && (
        <p className="text-sm text-muted-foreground">{routingTypeHints[entry.type]}</p>
      )}
      {selected.length === 0 && (
        <p className="text-sm text-destructive">Elegí al menos a quién le llega.</p>
      )}
      {onlyDirector && (
        <p role="status" className="rounded-md bg-warning-background px-3 py-2 text-sm text-warning">
          {ONLY_DIRECTOR_WARNING}
        </p>
      )}
      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
      {changed && (
        <div>
          <Button
            type="button"
            size="sm"
            disabled={saving || selected.length === 0}
            onClick={save}
          >
            {saving ? "Guardando…" : onlyDirector ? "Guardar igual" : "Guardar"}
          </Button>
        </div>
      )}
    </fieldset>
  )
}

/**
 * Alert settings, route `/configuracion/alertas` (ClickUp 86e3jpzcv;
 * documento vivo screen 11, "el umbral es una perilla, no un cálculo
 * nuestro"). Direction and psychopedagogy pick the sustained-low-performance
 * condition from a few examples and adjust it, and decide who each alert type
 * reaches first. Role gating here is UX only — `AlertRulePolicy` decides.
 */
export function AlertSettingsPage() {
  const { user } = useAuth()
  const canManage = canManageAlertSettings(user)

  const [rules, setRules] = useState<AlertRule[] | null>(null)
  const [routing, setRouting] = useState<AlertRoutingEntry[]>([])
  const [subjects, setSubjects] = useState<Subject[]>([])
  const [levels, setLevels] = useState<LevelRef[]>([])
  const [groupsWithoutLevel, setGroupsWithoutLevel] = useState(0)
  const [error, setError] = useState<string | null>(null)
  const [draft, setDraft] = useState<RuleDraft | null>(null)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [addingDifficulty, setAddingDifficulty] = useState(false)

  function load() {
    return alertSettingsApi
      .fetchAlertSettings()
      .then((settings) => {
        setRules(settings.rules)
        setRouting(settings.routing)
      })
      .catch(() => setError("No pudimos cargar la configuración de alertas."))
  }

  useEffect(() => {
    if (!canManage) return
    load()
    fetchSubjects()
      .then(setSubjects)
      .catch(() => setSubjects([]))
    fetchEducationLevels()
      .then((result) => {
        setLevels(result.data)
        setGroupsWithoutLevel(result.groups_without_level)
      })
      .catch(() => setLevels([]))
  }, [canManage])

  if (!canManage) {
    return <p className="text-sm text-muted-foreground">No tenés permiso para ver esta pantalla.</p>
  }
  if (error) return <p className="text-sm text-destructive">{error}</p>
  if (!rules) return <p className="text-muted-foreground">Cargando…</p>

  const performanceRules = rules.filter((rule) => rule.condition !== "difficulty_signals")
  const difficultyRules = rules.filter((rule) => rule.condition === "difficulty_signals")

  const ruleActions = (rule: AlertRule) => (
    <div className="flex flex-wrap items-center gap-2">
      <Badge tone={rule.active ? "success" : "neutral"}>{rule.active ? "Activa" : "Pausada"}</Badge>
      <Button type="button" variant="outline" size="sm" onClick={() => setEditingId(rule.id)}>
        Editar
      </Button>
      <Button type="button" variant="outline" size="sm" onClick={() => onToggle(rule)}>
        {rule.active ? "Pausar" : "Activar"}
      </Button>
      <ConfirmDialog
        trigger={
          <Button type="button" variant="destructive" size="sm">
            Eliminar
          </Button>
        }
        title="Eliminar condición"
        description="Las alertas que ya se generaron se mantienen."
        confirmLabel="Eliminar"
        onConfirm={() => onDelete(rule.id)}
      />
    </div>
  )

  const subjectName = (id: number | null) =>
    id === null ? "Todas las materias" : (subjects.find((subject) => subject.id === id)?.name ?? "Materia")

  async function onCreate(values: RuleValues, levelIds: LevelIds) {
    await alertSettingsApi.createAlertRule(toInput(values, levelIds))
    setDraft(null)
    await load()
  }

  async function onUpdate(id: number, values: RuleValues, levelIds: LevelIds) {
    await alertSettingsApi.updateAlertRule(id, toInput(values, levelIds))
    setEditingId(null)
    await load()
  }

  async function onCreateDifficulty(values: DifficultyRuleValues, levelIds: LevelIds) {
    await alertSettingsApi.createAlertRule(toDifficultyInput(values, levelIds))
    setAddingDifficulty(false)
    await load()
  }

  async function onUpdateDifficulty(id: number, values: DifficultyRuleValues, levelIds: LevelIds) {
    await alertSettingsApi.updateAlertRule(id, toDifficultyInput(values, levelIds))
    setEditingId(null)
    await load()
  }

  async function onToggle(rule: AlertRule) {
    setError(null)
    try {
      await alertSettingsApi.updateAlertRule(rule.id, { active: !rule.active })
      await load()
    } catch {
      setError("No pudimos actualizar la condición.")
    }
  }

  async function onDelete(id: number) {
    try {
      await alertSettingsApi.deleteAlertRule(id)
      await load()
    } catch {
      setError("No pudimos eliminar la condición.")
    }
  }

  async function onSaveRouting(type: AlertType, recipients: AlertRecipient[]) {
    setRouting(await alertSettingsApi.updateAlertRouting(type, recipients))
  }

  return (
    <div className="grid gap-6">
      <PageHeader
        title="Alertas"
        description="Qué condición dispara una alerta de desempeño bajo sostenido o de señal de dificultad y a quién le llega primero. La decide el colegio, no Aula+."
      />

      {groupsWithoutLevel > 0 && (
        <p role="status" className="rounded-md bg-warning-background px-3 py-2 text-sm text-warning">
          {groupsWithoutLevel === 1
            ? "1 grupo sin nivel: no entra en ninguna alerta hasta que se le elija uno."
            : `${groupsWithoutLevel} grupos sin nivel: no entran en ninguna alerta hasta que se les elija uno.`}
        </p>
      )}

      <Card>
        <CardHeader>
          <CardTitle>Desempeño bajo sostenido</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4">
          {performanceRules.length === 0 ? (
            <EmptyState
              icon={BellRing}
              message="Todavía no hay ninguna condición: Aula+ no genera esta alerta hasta que el colegio elija una."
            />
          ) : (
            <ul className="grid gap-3">
              {performanceRules.map((rule) => (
                <li key={rule.id} className="rounded-md border p-4">
                  {editingId === rule.id ? (
                    <RuleForm
                      initial={rule}
                      subjects={subjects}
                      levels={levels}
                      submitLabel="Guardar cambios"
                      onSubmit={(values, levelIds) => onUpdate(rule.id, values, levelIds)}
                      onCancel={() => setEditingId(null)}
                    />
                  ) : (
                    <div className="flex flex-wrap items-center justify-between gap-3">
                      <div className="grid gap-1">
                        <p className="font-medium">{describeAlertRule(rule)}</p>
                        <p className="text-sm text-muted-foreground">{subjectName(rule.subject_id)}</p>
                        <p className="text-sm text-muted-foreground">
                          {levelsText(rule.education_level_ids, levels)}
                        </p>
                      </div>
                      {ruleActions(rule)}
                    </div>
                  )}
                </li>
              ))}
            </ul>
          )}

          {draft ? (
            <div className="rounded-md border p-4">
              <RuleForm
                initial={draft}
                subjects={subjects}
                levels={levels}
                submitLabel="Agregar condición"
                onSubmit={onCreate}
                onCancel={() => setDraft(null)}
              />
            </div>
          ) : (
            <div className="grid gap-2">
              <p className="text-sm text-muted-foreground">
                Elegí una condición de ejemplo y ajustala a lo que tu equipo puede seguir:
              </p>
              <div className="flex flex-wrap gap-2">
                {ALERT_RULE_PRESETS.map((preset) => (
                  <Button
                    key={describeAlertRule(preset)}
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setDraft(preset)}
                  >
                    {describeAlertRule(preset)}
                  </Button>
                ))}
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Señal de dificultad</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4">
          <p className="text-sm text-muted-foreground">
            Se genera cuando un alumno junta cierta cantidad de señales de dificultad en una misma
            categoría de observaciones, dentro de un período.
          </p>
          {difficultyRules.length === 0 ? (
            <EmptyState
              icon={BellRing}
              message="Todavía no hay ninguna condición: Aula+ no genera alertas de señal de dificultad hasta que el colegio la configure."
            />
          ) : (
            <ul className="grid gap-3">
              {difficultyRules.map((rule) => (
                <li key={rule.id} className="rounded-md border p-4">
                  {editingId === rule.id ? (
                    <DifficultyRuleForm
                      initial={rule}
                      levels={levels}
                      submitLabel="Guardar cambios"
                      onSubmit={(values, levelIds) => onUpdateDifficulty(rule.id, values, levelIds)}
                      onCancel={() => setEditingId(null)}
                    />
                  ) : (
                    <div className="flex flex-wrap items-center justify-between gap-3">
                      <div className="grid gap-1">
                        <p className="font-medium">{describeAlertRule(rule)}</p>
                        <p className="text-sm text-muted-foreground">
                          {levelsText(rule.education_level_ids, levels)}
                        </p>
                      </div>
                      {ruleActions(rule)}
                    </div>
                  )}
                </li>
              ))}
            </ul>
          )}
          {addingDifficulty ? (
            <div className="rounded-md border p-4">
              <DifficultyRuleForm
                levels={levels}
                submitLabel="Guardar condición"
                onSubmit={onCreateDifficulty}
                onCancel={() => setAddingDifficulty(false)}
              />
            </div>
          ) : (
            <div>
              <Button type="button" variant="outline" size="sm" onClick={() => setAddingDifficulty(true)}>
                Nueva condición de señal de dificultad
              </Button>
            </div>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>A quién le llega primero</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4">
          <p className="text-sm text-muted-foreground">
            «Docente de la materia» es solo quien dicta esa materia en el grupo del alumno. Dirección no
            recibe las alertas nuevas salvo que la elijas acá, junto con otros o como único destino.
          </p>
          {routing.map((entry) => (
            <RoutingRow
              key={entry.type}
              entry={entry}
              onSave={(recipients) => onSaveRouting(entry.type, recipients)}
            />
          ))}
        </CardContent>
      </Card>
    </div>
  )
}
