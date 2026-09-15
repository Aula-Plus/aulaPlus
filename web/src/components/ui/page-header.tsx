import type { ReactNode } from "react"
import { Link } from "react-router-dom"
import { ArrowLeft } from "lucide-react"

import { cn } from "@/lib/utils"

interface PageHeaderProps {
  /** Route for the "back" affordance. When present, `backLabel` is required. */
  backTo?: string
  backLabel?: string
  title: ReactNode
  /** Optional supporting line under the title (school, group, dates…). */
  description?: ReactNode
  /** Right-aligned actions or secondary links (buttons, "Ver historial…"). */
  children?: ReactNode
  className?: string
}

/**
 * The standard page title block. The back link is intentionally quiet
 * (muted → foreground on hover) so it never competes with the primary color;
 * the title is the anchor, and page-level actions sit to its right.
 */
export function PageHeader({
  backTo,
  backLabel,
  title,
  description,
  children,
  className,
}: PageHeaderProps) {
  return (
    <div className={cn("grid gap-2", className)}>
      {backTo && (
        <Link
          to={backTo}
          className="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground transition-colors hover:text-foreground"
        >
          <ArrowLeft className="size-4" aria-hidden="true" />
          {backLabel}
        </Link>
      )}
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="grid gap-1">
          <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
          {description && <p className="text-sm text-muted-foreground">{description}</p>}
        </div>
        {children && <div className="flex flex-wrap items-center gap-3">{children}</div>}
      </div>
    </div>
  )
}
