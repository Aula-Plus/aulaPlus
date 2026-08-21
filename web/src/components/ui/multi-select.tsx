import * as React from "react"
import { Popover } from "radix-ui"
import { Check, ChevronDown } from "lucide-react"

import { cn } from "@/lib/utils"

export interface MultiSelectOption {
  id: number
  label: string
}

export interface MultiSelectProps {
  id?: string
  options: MultiSelectOption[]
  selected: number[]
  onChange: (selected: number[]) => void
  placeholder?: string
}

export function MultiSelect({
  id,
  options,
  selected,
  onChange,
  placeholder = "Seleccionar…",
}: MultiSelectProps) {
  const [open, setOpen] = React.useState(false)

  function toggle(optionId: number) {
    if (selected.includes(optionId)) {
      onChange(selected.filter((value) => value !== optionId))
    } else {
      onChange([...selected, optionId])
    }
  }

  const selectedOptions = options.filter((option) => selected.includes(option.id))

  return (
    <Popover.Root open={open} onOpenChange={setOpen}>
      <Popover.Trigger asChild>
        <button
          id={id}
          type="button"
          data-testid="multi-select-trigger"
          className={cn(
            "flex min-h-9 w-full flex-wrap items-center gap-1 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
          )}
        >
          {selectedOptions.length === 0 && (
            <span className="text-muted-foreground">{placeholder}</span>
          )}
          {selectedOptions.map((option) => (
            <span
              key={option.id}
              className="rounded bg-accent px-2 py-0.5 text-xs text-accent-foreground"
            >
              {option.label}
            </span>
          ))}
          <ChevronDown className="ml-auto size-4 text-muted-foreground" />
        </button>
      </Popover.Trigger>
      <Popover.Portal>
        <Popover.Content
          align="start"
          className="z-50 w-(--radix-popover-trigger-width) rounded-md border bg-popover p-1 text-popover-foreground shadow-md"
        >
          {options.length === 0 && (
            <p className="px-2 py-1.5 text-sm text-muted-foreground">Sin opciones</p>
          )}
          {options.map((option) => {
            const isSelected = selected.includes(option.id)
            return (
              <button
                key={option.id}
                type="button"
                aria-pressed={isSelected}
                onClick={() => toggle(option.id)}
                className="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-sm hover:bg-accent hover:text-accent-foreground"
              >
                <span className="flex size-4 items-center justify-center rounded border border-input">
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
