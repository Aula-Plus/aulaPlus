import type { ReactNode } from "react"
import type { LucideIcon } from "lucide-react"

import { cn } from "@/lib/utils"

interface EmptyStateProps {
  icon?: LucideIcon
  /** One line of plain direction — an empty screen is an invitation to act. */
  message: ReactNode
  /** Optional CTA (a button/link) that starts the relevant action. */
  action?: ReactNode
  className?: string
}

export function EmptyState({ icon: Icon, message, action, className }: EmptyStateProps) {
  return (
    <div
      className={cn(
        "flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed px-4 py-8 text-center",
        className,
      )}
    >
      {Icon && <Icon className="size-6 text-muted-foreground" aria-hidden="true" />}
      <p className="text-sm text-muted-foreground">{message}</p>
      {action}
    </div>
  )
}
