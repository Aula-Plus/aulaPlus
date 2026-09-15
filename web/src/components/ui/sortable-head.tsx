import { ChevronDown, ChevronUp, ChevronsUpDown } from "lucide-react"

import { TableHead } from "@/components/ui/table"
import type { SortDirection } from "@/lib/useSort"
import { cn } from "@/lib/utils"

interface SortableHeadProps {
  label: string
  /** Whether this column is the one currently driving the sort. */
  active: boolean
  direction: SortDirection
  onSort: () => void
  /** Applied to the inner button (e.g. `pl-6` to match the row's left padding). */
  className?: string
}

/**
 * A sortable column header: the label plus an arrow icon that reflects sort
 * state — neutral (up/down) when the column is inactive, up for asc, down for
 * desc. Clicking toggles the sort via `onSort`.
 */
export function SortableHead({ label, active, direction, onSort, className }: SortableHeadProps) {
  const Icon = active ? (direction === "asc" ? ChevronUp : ChevronDown) : ChevronsUpDown
  return (
    <TableHead
      className="p-0"
      aria-sort={active ? (direction === "asc" ? "ascending" : "descending") : "none"}
    >
      <button
        type="button"
        onClick={onSort}
        className={cn(
          "flex h-10 w-full items-center gap-1 px-4 text-left font-medium hover:text-foreground",
          active ? "text-foreground" : "text-muted-foreground",
          className,
        )}
      >
        {label}
        <Icon
          className={cn("size-3.5 shrink-0", active ? "text-foreground" : "text-muted-foreground/50")}
          aria-hidden="true"
        />
      </button>
    </TableHead>
  )
}
