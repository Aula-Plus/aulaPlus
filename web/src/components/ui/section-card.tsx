import type { ReactNode } from "react"

import { Card, CardContent, CardHeader } from "@/components/ui/card"
import { cn } from "@/lib/utils"

interface SectionCardProps {
  /** Rendered as an <h2> so the page keeps a real heading outline. */
  title: ReactNode
  /** Right-aligned action for the section (e.g. "Nueva adaptación"). */
  action?: ReactNode
  children: ReactNode
  className?: string
  /** Drop the content padding when the child manages its own (e.g. a table). */
  bare?: boolean
}

/**
 * The standard content container. Every section on a page uses this so the
 * page reads as one coherent set of cards instead of a mix of cards and bare
 * headings — the single biggest driver of the "flat, no focus" feel.
 */
export function SectionCard({ title, action, children, className, bare = false }: SectionCardProps) {
  return (
    <Card className={className}>
      <CardHeader className="flex flex-row items-center justify-between gap-2 space-y-0">
        <h2 className="text-base leading-none font-semibold">{title}</h2>
        {action}
      </CardHeader>
      <CardContent className={cn(bare && "px-0")}>{children}</CardContent>
    </Card>
  )
}
