import { useEffect, useId, useMemo, useState, type KeyboardEvent } from "react"
import { useLocation, useNavigate } from "react-router-dom"
import { Search } from "lucide-react"
import { useAuth } from "@/features/auth/AuthContext"
import { buildNavSections } from "@/components/nav-items"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { cn, formatShortDate } from "@/lib/utils"
import type { SearchResults } from "@/types"
import { SEARCH_MAX_LENGTH, SEARCH_MIN_LENGTH, foldForSearch, searchAula } from "./searchApi"

/** Wait this long after the last keystroke before asking the server. */
export const SEARCH_DEBOUNCE_MS = 250

function isMacPlatform(): boolean {
  if (typeof navigator === "undefined") return false
  return /mac|iphone|ipad/i.test(navigator.platform || navigator.userAgent)
}

/**
 * «Buscar en Aula+» (screen 01, P01-DEC-118 / PA-134, ClickUp 1dfd3mc56kr).
 *
 * The «Buscar» button lives outside the sidebar, above the content and next to
 * it, so it stays available when the sidebar is collapsed or closed; Ctrl+K
 * (⌘K on Mac) opens it from any screen. What each role finds is decided by
 * the server (students, groups, assessments) and, for menu sections, by the
 * same role-filtered navigation as the sidebar — UX only, never the security
 * boundary.
 */
export function GlobalSearch() {
  const [open, setOpen] = useState(false)
  const [isMac] = useState(isMacPlatform)

  useEffect(() => {
    const onKeyDown = (event: globalThis.KeyboardEvent) => {
      if ((event.ctrlKey || event.metaKey) && !event.altKey && event.key.toLowerCase() === "k") {
        event.preventDefault()
        setOpen(true)
      }
    }
    window.addEventListener("keydown", onKeyDown)
    return () => window.removeEventListener("keydown", onKeyDown)
  }, [])

  return (
    <>
      <Button
        type="button"
        variant="outline"
        className="gap-2 bg-background text-muted-foreground"
        aria-keyshortcuts="Control+K Meta+K"
        onClick={() => setOpen(true)}
      >
        <Search aria-hidden="true" />
        <span className="text-foreground">Buscar</span>
        <kbd
          className="hidden rounded border bg-muted px-1.5 font-sans text-xs text-muted-foreground sm:inline"
          aria-hidden="true"
        >
          {isMac ? "⌘K" : "Ctrl+K"}
        </kbd>
      </Button>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="top-[10vh] max-w-xl translate-y-0 gap-3">
          <SearchPanel onClose={() => setOpen(false)} />
        </DialogContent>
      </Dialog>
    </>
  )
}

interface SearchItem {
  key: string
  label: string
  detail: string | null
  to: string
  state?: unknown
}

interface SearchGroup {
  heading: string
  items: SearchItem[]
}

type Remote = { query: string; results: SearchResults | null; failed: boolean }

function SearchPanel({ onClose }: { onClose: () => void }) {
  const { user } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const inputId = useId()
  const listId = useId()
  const [query, setQuery] = useState("")
  const [debounced, setDebounced] = useState("")
  const [remote, setRemote] = useState<Remote | null>(null)
  const [activeKey, setActiveKey] = useState<string | null>(null)

  const term = query.trim()
  const searchable = term.length >= SEARCH_MIN_LENGTH

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(term), SEARCH_DEBOUNCE_MS)
    return () => window.clearTimeout(timer)
  }, [term])

  useEffect(() => {
    if (debounced.length < SEARCH_MIN_LENGTH) return
    const controller = new AbortController()
    searchAula(debounced, controller.signal)
      .then((results) => setRemote({ query: debounced, results, failed: false }))
      .catch(() => {
        if (!controller.signal.aborted) setRemote({ query: debounced, results: null, failed: true })
      })
    return () => controller.abort()
  }, [debounced])

  // Menu sections: the sidebar's own role-filtered list, matched locally.
  const sectionItems = useMemo<SearchItem[]>(() => {
    if (!searchable) return []
    const folded = foldForSearch(term)
    const seen = new Set<string>()
    return buildNavSections(user)
      .flatMap((section) => section.items)
      .filter((item) => {
        if (seen.has(item.to)) return false
        seen.add(item.to)
        return foldForSearch(item.label).includes(folded)
      })
      .map((item) => ({
        key: `section-${item.to}`,
        label: item.label,
        detail: null,
        to: item.to,
        state: item.passFrom ? { from: location.pathname } : undefined,
      }))
  }, [searchable, term, user, location.pathname])

  const current = remote !== null && remote.query === term && term === debounced ? remote : null
  const loading = searchable && current === null
  const failed = current?.failed ?? false
  const results = current?.results ?? null

  const groups = useMemo<SearchGroup[]>(() => {
    const list: SearchGroup[] = [
      {
        heading: "Alumnos",
        items: (results?.students ?? []).map((student) => ({
          key: `student-${student.id}`,
          label: student.full_name,
          detail: student.group ? student.group.name : "Sin grupo",
          to: `/alumnos/${student.id}/seguimiento`,
        })),
      },
      {
        heading: "Grupos",
        items: (results?.groups ?? []).map((group) => ({
          key: `group-${group.id}`,
          label: group.name,
          detail: [group.level ?? "Sin nivel", group.school_year].filter(Boolean).join(" · "),
          to: `/clases/${group.id}/seguimiento`,
        })),
      },
      { heading: "Secciones", items: sectionItems },
      {
        heading: "Evaluaciones",
        items: (results?.assessments ?? []).map((assessment) => ({
          key: `assessment-${assessment.id}`,
          label: assessment.title,
          detail: [assessment.subject, assessment.group.name, assessment.date ? formatShortDate(assessment.date) : null]
            .filter(Boolean)
            .join(" · "),
          to: `/clases/${assessment.group.id}/evaluaciones`,
        })),
      },
    ]
    return list.filter((group) => group.items.length > 0)
  }, [results, sectionItems])

  const items = useMemo(() => groups.flatMap((group) => group.items), [groups])
  const activeIndex = Math.max(
    0,
    items.findIndex((item) => item.key === activeKey),
  )
  const active = items[activeIndex] ?? null

  const go = (item: SearchItem) => {
    onClose()
    navigate(item.to, item.state ? { state: item.state } : undefined)
  }

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (items.length === 0) return
    const move = (index: number) => {
      event.preventDefault()
      setActiveKey(items[(index + items.length) % items.length].key)
    }
    if (event.key === "ArrowDown") move(activeIndex + 1)
    else if (event.key === "ArrowUp") move(activeIndex - 1)
    else if (event.key === "Home" && event.ctrlKey) move(0)
    else if (event.key === "End" && event.ctrlKey) move(items.length - 1)
    else if (event.key === "Enter" && active) {
      event.preventDefault()
      go(active)
    }
  }

  const optionId = (key: string) => `${listId}-${key}`

  let status: string
  if (!searchable) status = `Escribí al menos ${SEARCH_MIN_LENGTH} letras para buscar.`
  else if (failed) status = "No se pudo buscar. Probá de nuevo en unos segundos."
  else if (loading) status = "Buscando…"
  else if (items.length === 0) status = "No hay coincidencias en lo que podés consultar."
  else status = items.length === 1 ? "1 resultado." : `${items.length} resultados.`

  return (
    <>
      <DialogHeader>
        <DialogTitle>Buscar en Aula+</DialogTitle>
        <DialogDescription>
          Buscá por nombre o título. Solo aparece lo que podés abrir.
        </DialogDescription>
      </DialogHeader>
      <div className="space-y-1.5">
        <Label htmlFor={inputId}>Alumno, grupo, sección o evaluación</Label>
        <Input
          id={inputId}
          type="search"
          role="combobox"
          autoComplete="off"
          maxLength={SEARCH_MAX_LENGTH}
          aria-autocomplete="list"
          aria-controls={listId}
          aria-expanded={items.length > 0}
          aria-activedescendant={active ? optionId(active.key) : undefined}
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          onKeyDown={onKeyDown}
        />
      </div>
      <p
        role="status"
        aria-live="polite"
        className={cn("text-sm", failed ? "text-destructive" : "text-muted-foreground", items.length > 0 && "sr-only")}
      >
        {status}
      </p>
      <div id={listId} role="listbox" aria-label="Resultados" className="space-y-3">
        {groups.map((group) => {
          const headingId = `${listId}-${group.heading}`
          return (
            <div key={group.heading} role="group" aria-labelledby={headingId}>
              <div
                id={headingId}
                role="presentation"
                className="px-2 pb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
              >
                {group.heading}
              </div>
              {group.items.map((item) => {
                const selected = active?.key === item.key
                return (
                  <div
                    key={item.key}
                    id={optionId(item.key)}
                    role="option"
                    aria-selected={selected}
                    className={cn(
                      "flex cursor-pointer flex-col rounded-md px-2 py-1.5 text-sm",
                      selected ? "bg-accent text-accent-foreground" : "hover:bg-muted",
                    )}
                    // Keep focus in the field so the keyboard keeps working.
                    onMouseDown={(event) => event.preventDefault()}
                    onMouseMove={() => setActiveKey(item.key)}
                    onClick={() => go(item)}
                  >
                    <span className="font-medium">{item.label}</span>
                    {item.detail && <span className="text-xs text-muted-foreground">{item.detail}</span>}
                  </div>
                )
              })}
            </div>
          )
        })}
      </div>
      {searchable && loading && items.length > 0 && (
        <p className="text-xs text-muted-foreground" aria-hidden="true">
          Buscando…
        </p>
      )}
    </>
  )
}
