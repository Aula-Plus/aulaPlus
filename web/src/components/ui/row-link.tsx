import type { ReactNode } from "react"
import { Link } from "react-router-dom"

import { cn } from "@/lib/utils"

/**
 * A quiet secondary action inside a table row. Table rows had 3-4 equal-weight
 * terracotta links with no primary; the standard is one primary action (an
 * outline Button) plus these muted links, so the hierarchy reads at a glance.
 */
export function RowLink({
  to,
  children,
  className,
}: {
  to: string
  children: ReactNode
  className?: string
}) {
  return (
    <Link
      to={to}
      className={cn(
        "px-1 text-sm text-muted-foreground underline-offset-4 transition-colors hover:text-foreground hover:underline",
        className,
      )}
    >
      {children}
    </Link>
  )
}
