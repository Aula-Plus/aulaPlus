import { useState } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { accommodationCategoryLabels, type AccommodationCategory } from "@/types"
import type { ProposalFields } from "./reportsApi"

const CATEGORIES: AccommodationCategory[] = ["access", "content", "criteria"]

/** An adjustment the AI missed: it enters as a pending proposal, reviewed like the others. */
export function AddProposalForm({ onAdd }: { onAdd: (input: ProposalFields) => Promise<void> }) {
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [fields, setFields] = useState<ProposalFields>({
    type: "",
    category: "access",
    description: "",
    focus_area: "",
  })

  if (!open) {
    return (
      <Button type="button" size="sm" variant="outline" onClick={() => setOpen(true)}>
        Agregar un ajuste que faltó
      </Button>
    )
  }

  async function submit() {
    setBusy(true)
    setError(null)
    try {
      await onAdd(fields)
      setFields({ type: "", category: "access", description: "", focus_area: "" })
      setOpen(false)
    } catch {
      setError("No pudimos agregar el ajuste. Revisá los campos.")
    } finally {
      setBusy(false)
    }
  }

  return (
    <form
      className="grid gap-3 rounded-md border p-4"
      onSubmit={(event) => {
        event.preventDefault()
        void submit()
      }}
    >
      <div className="grid gap-1">
        <Label htmlFor="new-proposal-type">Nombre del ajuste</Label>
        <Input
          id="new-proposal-type"
          value={fields.type}
          onChange={(event) => setFields({ ...fields, type: event.target.value })}
        />
      </div>
      <div className="grid gap-1">
        <Label htmlFor="new-proposal-category">Categoría</Label>
        <select
          id="new-proposal-category"
          className="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
          value={fields.category}
          onChange={(event) => setFields({ ...fields, category: event.target.value as AccommodationCategory })}
        >
          {CATEGORIES.map((category) => (
            <option key={category} value={category}>
              {accommodationCategoryLabels[category]}
            </option>
          ))}
        </select>
      </div>
      <div className="grid gap-1">
        <Label htmlFor="new-proposal-area">Área de enfoque</Label>
        <Input
          id="new-proposal-area"
          value={fields.focus_area}
          onChange={(event) => setFields({ ...fields, focus_area: event.target.value })}
        />
      </div>
      <div className="grid gap-1">
        <Label htmlFor="new-proposal-description">Qué hacer en clase</Label>
        <Textarea
          id="new-proposal-description"
          value={fields.description}
          onChange={(event) => setFields({ ...fields, description: event.target.value })}
        />
      </div>
      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
      <div className="flex gap-2">
        <Button
          type="submit"
          size="sm"
          disabled={busy || !fields.type.trim() || !fields.description.trim() || !fields.focus_area.trim()}
        >
          Agregar
        </Button>
        <Button type="button" size="sm" variant="outline" onClick={() => setOpen(false)}>
          Cancelar
        </Button>
      </div>
    </form>
  )
}
