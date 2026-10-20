import type { AlertRule } from "@/types"

type RuleShape = Pick<AlertRule, "condition" | "threshold" | "consecutive_count" | "period_days"> &
  Partial<Pick<AlertRule, "signal_count">>

function formatNumber(value: number): string {
  return value.toLocaleString("es-UY", { maximumFractionDigits: 2 })
}

/** Spanish sentence for a condition, e.g. «3 notas seguidas por debajo de 5». */
export function describeAlertRule(rule: RuleShape): string {
  if (rule.condition === "difficulty_signals") {
    return `${rule.signal_count ?? "?"} señales de dificultad en una misma categoría en ${rule.period_days ?? "?"} días`
  }
  const threshold = rule.threshold === null ? "?" : formatNumber(rule.threshold)
  if (rule.condition === "consecutive_below") {
    return `${rule.consecutive_count ?? "?"} notas seguidas por debajo de ${threshold}`
  }
  return `Promedio de los últimos ${rule.period_days ?? "?"} días por debajo de ${threshold}`
}

/**
 * The example conditions offered on the settings screen so the school picks
 * and adjusts one instead of facing an empty form (ClickUp 86e3jpzcv). They
 * are starting points only: Aula+ never activates any of them on its own.
 * There is deliberately no example for «Señal de dificultad» (ClickUp
 * 86e3qkwpn): product will define a suggested value later, so its fields
 * start empty.
 */
export const ALERT_RULE_PRESETS: RuleShape[] = [
  { condition: "consecutive_below", threshold: 5, consecutive_count: 3, period_days: null },
  { condition: "average_below", threshold: 6, consecutive_count: null, period_days: 90 },
  { condition: "consecutive_below", threshold: 6, consecutive_count: 2, period_days: null },
]
