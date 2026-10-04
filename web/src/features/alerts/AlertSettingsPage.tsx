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
import { fetchSubjects } from "@/features/subjects/subjectsApi"
import {
  alertRecipientLabels,
  alertTypeLabels,
  type AlertRecipient,
  type AlertRoutingEntry,
  type AlertRule,
  type AlertType,
  type Subject,
} from "@/types"
import * as alertSettingsApi from "./alertSettingsApi"
import { ALERT_RULE_PRESETS, describeAlertRule } from "./alertRuleText"

const RECIPIENTS: AlertRecipient[] = ["teacher", "psychopedagogue", "director"]

/** On this screen the performance type reads as the alert's product name. */
const routingTypeLabels: Partial<Record<AlertType, string>> = {
  performance: "Desempeño bajo sostenido",
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
}

function toFormValues(rule: RuleDraft): RuleFormInput {
  return {
    condition: rule.condition,
    threshold: rule.threshold,
    consecutive_count: rule.consecutive_count ?? 3,
    period_days: rule.period_days ?? 90,
    subject_id: rule.subject_id ? String(rule.subject_id) : "",
  }
}

function RuleForm({
  initial,
  subjects,
  submitLabel,
  onSubmit,
  onCancel,
}: {
  initial: RuleDraft
  subjects: Subject[]
  submitLabel: string
  onSubmit: (values: RuleValues) => Promise<void>
  onCancel: () => void
}) {
  const [formError, setFormError] = useState<string | null>(null)
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
    try {
      await onSubmit(values)
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
          <option value="consecutive_below">Notas seguidas por debajo de un valor</option>
          <option value="average_below">Promedio del período por debajo de un valor</option>
        </select>
      </div>
      {condition === "consecutive_below" ? (
        <div className="grid gap-2">
          <Label htmlFor="alert-rule-count">Cantidad de notas seguidas</Label>
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

function toInput(values: RuleValues) {
  return {
    condition: values.condition,
    threshold: values.threshold,
    consecutive_count: values.condition === "consecutive_below" ? values.consecutive_count : null,
    period_days: values.condition === "average_below" ? values.period_days : null,
    subject_id: values.subject_id ? Number(values.subject_id) : null,
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

  const changed =
    selected.length !== entry.recipients.length ||
    selected.some((recipient) => !entry.recipients.includes(recipient))

  function toggle(recipient: AlertRecipient) {
    setSelected((current) =>
      current.includes(recipient)
        ? current.filter((value) => value !== recipient)
        : RECIPIENTS.filter((value) => value === recipient || current.includes(value)),
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

  return (
    <fieldset className="grid gap-3 rounded-md border p-4">
      <legend className="px-1 text-sm font-medium">
        {label} {entry.is_default && <span className="text-muted-foreground">(por defecto)</span>}
      </legend>
      <div className="flex flex-wrap gap-4">
        {RECIPIENTS.map((recipient) => {
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
      {selected.length === 0 && (
        <p className="text-sm text-destructive">Elegí al menos a quién le llega.</p>
      )}
      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
      {changed && (
        <div>
          <Button type="button" size="sm" disabled={saving || selected.length === 0} onClick={save}>
            {saving ? "Guardando…" : "Guardar"}
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
  const [error, setError] = useState<string | null>(null)
  const [draft, setDraft] = useState<RuleDraft | null>(null)
  const [editingId, setEditingId] = useState<number | null>(null)

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
  }, [canManage])

  if (!canManage) {
    return <p className="text-sm text-muted-foreground">No tenés permiso para ver esta pantalla.</p>
  }
  if (error) return <p className="text-sm text-destructive">{error}</p>
  if (!rules) return <p className="text-muted-foreground">Cargando…</p>

  const subjectName = (id: number | null) =>
    id === null ? "Todas las materias" : (subjects.find((subject) => subject.id === id)?.name ?? "Materia")

  async function onCreate(values: RuleValues) {
    await alertSettingsApi.createAlertRule(toInput(values))
    setDraft(null)
    await load()
  }

  async function onUpdate(id: number, values: RuleValues) {
    await alertSettingsApi.updateAlertRule(id, toInput(values))
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
        description="Qué condición dispara una alerta de desempeño bajo sostenido y a quién le llega primero. La decide el colegio, no Aula+."
      />

      <Card>
        <CardHeader>
          <CardTitle>Desempeño bajo sostenido</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4">
          {rules.length === 0 ? (
            <EmptyState
              icon={BellRing}
              message="Todavía no hay ninguna condición: Aula+ no genera esta alerta hasta que el colegio elija una."
            />
          ) : (
            <ul className="grid gap-3">
              {rules.map((rule) => (
                <li key={rule.id} className="rounded-md border p-4">
                  {editingId === rule.id ? (
                    <RuleForm
                      initial={rule}
                      subjects={subjects}
                      submitLabel="Guardar cambios"
                      onSubmit={(values) => onUpdate(rule.id, values)}
                      onCancel={() => setEditingId(null)}
                    />
                  ) : (
                    <div className="flex flex-wrap items-center justify-between gap-3">
                      <div className="grid gap-1">
                        <p className="font-medium">{describeAlertRule(rule)}</p>
                        <p className="text-sm text-muted-foreground">{subjectName(rule.subject_id)}</p>
                      </div>
                      <div className="flex flex-wrap items-center gap-2">
                        <Badge tone={rule.active ? "success" : "neutral"}>
                          {rule.active ? "Activa" : "Pausada"}
                        </Badge>
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
          <CardTitle>A quién le llega primero</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4">
          <p className="text-sm text-muted-foreground">
            «Docente de la materia» es solo quien dicta esa materia en el grupo del alumno. Dirección no
            recibe las alertas nuevas salvo que lo elijas acá.
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
