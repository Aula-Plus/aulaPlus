import * as React from "react"

import { cn } from "@/lib/utils"

/**
 * Semantic status pill. Tones map to the design-system state tokens
 * (`--warning`, `--success`, `--info`, `--destructive`) defined in
 * `index.css` — never raw Tailwind palette colors — so every "pendiente /
 * aprobada / rechazada / alerta" chip across the app reads the same.
 */
export type BadgeTone = "neutral" | "success" | "warning" | "danger" | "info"

const toneClass: Record<BadgeTone, string> = {
  neutral: "bg-muted text-muted-foreground",
  success: "bg-success-background text-success",
  warning: "bg-warning-background text-warning",
  danger: "bg-destructive/10 text-destructive",
  info: "bg-info-background text-info",
}

export function Badge({
  tone = "neutral",
  className,
  ...props
}: React.ComponentProps<"span"> & { tone?: BadgeTone }) {
  return (
    <span
      data-slot="badge"
      className={cn(
        "inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium",
        toneClass[tone],
        className,
      )}
      {...props}
    />
  )
}
