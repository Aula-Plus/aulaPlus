import * as React from "react"
import { Popover } from "radix-ui"
import { Check, ChevronDown } from "lucide-react"

import { cn } from "@/lib/utils"

export interface SingleSelectOption {
  value: string
  label: string
}

export interface SingleSelectProps {
  id?: string
  options: SingleSelectOption[]
  value: string
  onChange: (value: string) => void
  placeholder?: string
  searchPlaceholder?: string
  className?: string
  disabled?: boolean
}

export function SingleSelect({
  id,
  options,
  value,
  onChange,
  placeholder = "Seleccionar…",
  searchPlaceholder = "Buscar…",
  className,
  disabled,
}: SingleSelectProps) {
  const [open, setOpen] = React.useState(false)
  const [query, setQuery] = React.useState("")

  const selectedOption = options.find((option) => option.value === value)

  const filteredOptions = React.useMemo(() => {
    const normalized = query.trim().toLowerCase()
    if (!normalized) return options
    return options.filter((option) => option.label.toLowerCase().includes(normalized))
  }, [options, query])

  function select(optionValue: string) {
    onChange(optionValue)
    setOpen(false)
    setQuery("")
  }

  return (
    <Popover.Root
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        if (!next) setQuery("")
      }}
    >
      <Popover.Trigger asChild>
        <button
          id={id}
          type="button"
          disabled={disabled}
          data-testid="single-select-trigger"
          className={cn(
            "flex min-h-9 w-full items-center gap-1 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50",
            className
          )}
        >
          {selectedOption ? (
            <span className="truncate">{selectedOption.label}</span>
          ) : (
            <span className="truncate text-muted-foreground">{placeholder}</span>
          )}
          <ChevronDown className="ml-auto size-4 shrink-0 text-muted-foreground" />
        </button>
      </Popover.Trigger>
      <Popover.Portal>
        <Popover.Content
          align="start"
          className="z-50 w-(--radix-popover-trigger-width) rounded-md border bg-popover p-1 text-popover-foreground shadow-md"
        >
          <input
            type="text"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder={searchPlaceholder}
            className="mb-1 w-full border-b border-input bg-transparent px-2 py-1.5 text-sm outline-none placeholder:text-muted-foreground"
          />
          {options.length === 0 && (
            <p className="px-2 py-1.5 text-sm text-muted-foreground">Sin opciones</p>
          )}
          {options.length > 0 && filteredOptions.length === 0 && (
            <p className="px-2 py-1.5 text-sm text-muted-foreground">Sin resultados</p>
          )}
          {filteredOptions.map((option) => {
            const isSelected = option.value === value
            return (
              <button
                key={option.value}
                type="button"
                aria-pressed={isSelected}
                onClick={() => select(option.value)}
                className="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-sm hover:bg-accent hover:text-accent-foreground"
              >
                <span className="flex size-4 shrink-0 items-center justify-center">
                  {isSelected && <Check className="size-3" />}
                </span>
                {option.label}
              </button>
            )
          })}
        </Popover.Content>
      </Popover.Portal>
    </Popover.Root>
  )
}
