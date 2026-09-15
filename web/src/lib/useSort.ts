import { useState } from "react"

export type SortDirection = "asc" | "desc"

export interface SortState<K extends string> {
  key: K
  direction: SortDirection
}

/**
 * Per-column value extractor. The returned type decides the comparison:
 * numbers compare numerically, strings compare with a locale-aware collator
 * ("es", numeric) so "3° A" / "10° A" sort naturally. `null` always sorts last,
 * regardless of direction (an unset value is neither the smallest nor largest).
 */
export type SortAccessors<T, K extends string> = Record<K, (item: T) => string | number | null>

/**
 * Client-side column sorting for the list tables. State is `{ key, direction }`;
 * clicking a header toggles asc→desc→asc and switches the active key. Returns a
 * `sortItems` helper that produces a new sorted array (never mutates the input).
 */
export function useSort<T, K extends string>(accessors: SortAccessors<T, K>, initial: SortState<K>) {
  const [sort, setSort] = useState<SortState<K>>(initial)

  function toggle(key: K) {
    setSort((prev) =>
      prev.key === key
        ? { key, direction: prev.direction === "asc" ? "desc" : "asc" }
        : { key, direction: "asc" },
    )
  }

  function sortItems(items: T[]): T[] {
    const accessor = accessors[sort.key]
    const factor = sort.direction === "asc" ? 1 : -1
    return [...items].sort((a, b) => {
      const av = accessor(a)
      const bv = accessor(b)
      if (av === null && bv === null) return 0
      if (av === null) return 1
      if (bv === null) return -1
      const base =
        typeof av === "number" && typeof bv === "number"
          ? av - bv
          : String(av).localeCompare(String(bv), "es", { numeric: true, sensitivity: "base" })
      return factor * base
    })
  }

  return { sort, toggle, sortItems }
}
