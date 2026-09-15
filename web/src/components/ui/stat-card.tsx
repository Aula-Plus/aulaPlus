import type { LucideIcon } from "lucide-react"

import { Card, CardContent } from "@/components/ui/card"
import { cn } from "@/lib/utils"

/**
 * A single headline metric. The whole point of this product is early signal,
 * so a count that matters must not look like one that doesn't: a positive
 * `alert`/`warning` metric takes its state color, while a zero drops to muted.
 * Tones map to the same design-system state tokens as `Badge`.
 */
export type StatTone = "neutral" | "danger" | "warning" | "info" | "success"

const toneStyles: Record<StatTone, { chip: string; value: string }> = {
  neutral: { chip: "bg-muted text-muted-foreground", value: "text-foreground" },
  danger: { chip: "bg-destructive/10 text-destructive", value: "text-destructive" },
  warning: { chip: "bg-warning-background text-warning", value: "text-warning" },
  info: { chip: "bg-info-background text-info", value: "text-info" },
  success: { chip: "bg-success-background text-success", value: "text-success" },
}

interface StatCardProps {
  label: string
  value: number
  icon?: LucideIcon
  /** Tone applied only when the metric is "active" (see `emphasizeWhenZero`). */
  tone?: StatTone
  /** When false (default) a zero value stays neutral/muted instead of colored. */
  emphasizeWhenZero?: boolean
  /** Rendered right after the value, e.g. "%". Kept in the same node so the
   *  value reads as one token ("75%"). */
  suffix?: string
}

export function StatCard({
  label,
  value,
  icon: Icon,
  tone = "neutral",
  emphasizeWhenZero = false,
  suffix,
}: StatCardProps) {
  const active = value > 0 || emphasizeWhenZero
  const styles = toneStyles[active ? tone : "neutral"]

  return (
    <Card>
      <CardContent className="flex items-center gap-4">
        {Icon && (
          <span
            className={cn(
              "flex size-10 shrink-0 items-center justify-center rounded-lg",
              styles.chip,
            )}
          >
            <Icon className="size-5" aria-hidden="true" />
          </span>
        )}
        <div className="grid gap-0.5">
          <p
            className={cn(
              "text-2xl font-semibold tabular-nums leading-none",
              active ? styles.value : "text-muted-foreground",
            )}
          >
            {value}
            {suffix}
          </p>
          <p className="text-sm text-muted-foreground">{label}</p>
        </div>
      </CardContent>
    </Card>
  )
}
