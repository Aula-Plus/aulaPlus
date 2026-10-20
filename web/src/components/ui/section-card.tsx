import type { ReactNode } from "react"

import { Card, CardContent, CardHeader } from "@/components/ui/card"
import { cn } from "@/lib/utils"

interface SectionCardProps {
  /** Rendered as an <h2> so the page keeps a real heading outline. */
  title: ReactNode
  /**
   * Heading level of `title`: 3 when the card is a subsection under a section
   * that already has its own <h2> (e.g. «Mis seguimientos» on /alertas).
   */
  headingLevel?: 2 | 3
  /** Right-aligned action for the section (e.g. "Nuevo ajuste"). */
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
export function SectionCard({
  title,
  headingLevel = 2,
  action,
  children,
  className,
  bare = false,
}: SectionCardProps) {
  const Heading = headingLevel === 3 ? "h3" : "h2"
  return (
    <Card className={className}>
      <CardHeader className="flex flex-row items-start justify-between gap-2 space-y-0">
        <Heading className="text-base leading-none font-semibold">{title}</Heading>
        {action}
      </CardHeader>
      <CardContent className={cn(bare && "px-0")}>{children}</CardContent>
    </Card>
  )
}
