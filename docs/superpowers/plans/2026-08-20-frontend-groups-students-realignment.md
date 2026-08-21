# Frontend: Realinear Clases y Alumnos Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Realign `web/`'s Groups and Students screens to the domain model and authorization rules merged into `develop` from PR #34 (Sesión 1) and PR #35 (Sesión 2), and give the team 3 reusable UI primitives (Table, ConfirmDialog, MultiSelect) it didn't have before.

**Architecture:** No new libraries. Everything is built by hand on the `radix-ui` unified package and Tailwind (`cn()` in `lib/utils.ts`), following the existing `components/ui/*.tsx` convention. Work proceeds bottom-up: shared utility → shared UI primitives → Groups slice (types + api client + 2 pages) → Students slice (same) → final verification.

**Tech Stack:** React 19, Vite, TypeScript, Tailwind v4, `radix-ui` ^1.6.7, `lucide-react` ^1.31, `react-hook-form` ^7.85 + `@hookform/resolvers/zod`, `zod` ^4.4, Vitest ^4.1 + `@testing-library/react` ^16.3 + `@testing-library/user-event` ^14.6.

## Global Constraints

- All code identifiers, file names, and comments in English; all user-facing UI text in Spanish (`CLAUDE.md`).
- No Spanish in code/comments, ever (`CLAUDE.md`).
- Before considering any task's frontend changes done, the relevant `web/` checks must be runnable: `npm run lint`, `npm run typecheck`, `npm run test`, `npm run build` (`CLAUDE.md`; enforced fully in the final task).
- No UI for Accommodation, Barrier, AnnualPlan, Unit, Assessment, ClassSession, Calendar, CalendarEvent, CurricularFramework/Catalog/Item, or `User` admin — none of them have a backend controller/route yet.
- The backend Policies are the real authorization boundary. Every role-based UI gate added in this plan (`isDirector`, `canManage`, `canEditClinicalProfile`) is UX only — it must mirror, never replace, the corresponding Policy rule.
- Only the current school year's group membership is shown/edited (`group.school_year === getCurrentSchoolYear()`). Full multi-year history is out of scope.
- Clinical fields (`learning_profile`, `individual_profile`, `related_documents`) are edited as raw JSON text in this pass — no structured editor.
- Do not use the shadcn/ui CLI for the new components — build them by hand on `radix-ui` (explicit decision from brainstorming).

---

## Task 1: `getCurrentSchoolYear()` utility

**Files:**
- Create: `web/src/lib/schoolYear.ts`
- Test: `web/src/lib/schoolYear.test.ts`

**Interfaces:**
- Produces: `getCurrentSchoolYear(): number` — used by every later task that needs "the current school year" (Groups/Students pages and forms).

- [ ] **Step 1: Write the failing test**

```ts
// web/src/lib/schoolYear.test.ts
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { getCurrentSchoolYear } from "./schoolYear"

describe("getCurrentSchoolYear", () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it("returns the current calendar year", () => {
    vi.setSystemTime(new Date("2026-08-20T12:00:00Z"))
    expect(getCurrentSchoolYear()).toBe(2026)
  })

  it("returns a different year when the system date changes", () => {
    vi.setSystemTime(new Date("2027-01-05T00:00:00Z"))
    expect(getCurrentSchoolYear()).toBe(2027)
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run (from `web/`): `npm run test -- src/lib/schoolYear.test.ts`
Expected: FAIL — `Failed to resolve import "./schoolYear"` (file doesn't exist yet).

- [ ] **Step 3: Write minimal implementation**

```ts
// web/src/lib/schoolYear.ts
export function getCurrentSchoolYear(): number {
  return new Date().getFullYear()
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test -- src/lib/schoolYear.test.ts`
Expected: PASS — 2 tests.

- [ ] **Step 5: Commit**

```bash
git add web/src/lib/schoolYear.ts web/src/lib/schoolYear.test.ts
git commit -m "feat(web): add getCurrentSchoolYear utility"
```

---

## Task 2: Push component previews to DesignSync

This is a documentation/review step, not app code — no automated test. It uses the `DesignSync` tool directly (not the `npx shadcn` CLI — this repo builds its own components, DesignSync is only for visual review of static previews before the real components are wired up).

**Files:**
- Create: `web/design-previews/table.html`
- Create: `web/design-previews/confirm-dialog.html`
- Create: `web/design-previews/multi-select.html`

These live outside `src/`, so Vite never bundles them — they exist only to be pushed to the design-system project.

- [ ] **Step 1: Write the 3 preview files**

```html
<!-- web/design-previews/table.html -->
<!-- @dsCard group="Components" -->
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8" />
<title>Table — Aula+</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: oklch(1 0 0); color: oklch(0.145 0 0); padding: 24px; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  thead tr { border-bottom: 1px solid oklch(0.922 0 0); }
  th { text-align: left; padding: 10px 16px; font-weight: 500; color: oklch(0.556 0 0); }
  tbody tr { border-bottom: 1px solid oklch(0.922 0 0); }
  tbody tr:last-child { border-bottom: 0; }
  tbody tr:hover { background: oklch(0.97 0 0 / 0.5); }
  td { padding: 16px; vertical-align: middle; }
  a.edit { color: oklch(0.205 0 0); text-decoration: underline; text-underline-offset: 4px; }
</style>
</head>
<body>
  <table>
    <thead>
      <tr>
        <th>Nombre</th>
        <th>Nivel</th>
        <th>Año</th>
        <th>Docentes</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>3° A</td>
        <td>Primaria</td>
        <td>2026</td>
        <td>Ana Ruiz, Zoe Diaz</td>
        <td style="text-align:right"><a class="edit" href="#">Editar</a></td>
      </tr>
      <tr>
        <td>4° B</td>
        <td>Primaria</td>
        <td>2026</td>
        <td>—</td>
        <td style="text-align:right"><a class="edit" href="#">Editar</a></td>
      </tr>
    </tbody>
  </table>
</body>
</html>
```

```html
<!-- web/design-previews/confirm-dialog.html -->
<!-- @dsCard group="Components" -->
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8" />
<title>ConfirmDialog — Aula+</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 0; height: 100vh; position: relative; background: oklch(1 0 0); }
  .overlay { position: absolute; inset: 0; background: rgba(0,0,0,0.5); }
  .dialog { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 100%; max-width: 420px; background: oklch(1 0 0); border: 1px solid oklch(0.922 0 0); border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); padding: 24px; box-sizing: border-box; }
  h2 { margin: 0 0 8px; font-size: 18px; font-weight: 600; }
  p { margin: 0 0 20px; font-size: 14px; color: oklch(0.556 0 0); }
  .footer { display: flex; justify-content: flex-end; gap: 8px; }
  button { border-radius: 6px; padding: 8px 16px; font-size: 14px; font-weight: 500; cursor: pointer; border: 1px solid transparent; }
  .cancel { background: transparent; border-color: oklch(0.922 0 0); color: oklch(0.145 0 0); }
  .confirm { background: oklch(0.577 0.245 27.325); color: white; }
</style>
</head>
<body>
  <div class="overlay"></div>
  <div class="dialog">
    <h2>Eliminar clase</h2>
    <p>Esta acción no se puede deshacer.</p>
    <div class="footer">
      <button class="cancel">Cancelar</button>
      <button class="confirm">Eliminar</button>
    </div>
  </div>
</body>
</html>
```

```html
<!-- web/design-previews/multi-select.html -->
<!-- @dsCard group="Components" -->
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8" />
<title>MultiSelect — Aula+</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: oklch(1 0 0); color: oklch(0.145 0 0); padding: 40px; }
  .field { max-width: 320px; }
  .trigger { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; min-height: 36px; width: 100%; border: 1px solid oklch(0.922 0 0); border-radius: 6px; padding: 4px 12px; box-sizing: border-box; }
  .chip { background: oklch(0.97 0 0); color: oklch(0.205 0 0); border-radius: 4px; padding: 2px 8px; font-size: 12px; }
  .panel { margin-top: 4px; border: 1px solid oklch(0.922 0 0); border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); padding: 4px; }
  .option { display: flex; align-items: center; gap: 8px; padding: 6px 8px; font-size: 14px; border-radius: 4px; }
  .option:hover { background: oklch(0.97 0 0); }
  .box { width: 16px; height: 16px; border: 1px solid oklch(0.922 0 0); border-radius: 3px; display: flex; align-items: center; justify-content: center; font-size: 11px; }
  .box.checked { background: oklch(0.205 0 0); color: white; border-color: oklch(0.205 0 0); }
</style>
</head>
<body>
  <div class="field">
    <div class="trigger">
      <span class="chip">Ana Ruiz</span>
      <span class="chip">Zoe Diaz</span>
    </div>
    <div class="panel">
      <div class="option"><span class="box checked">✓</span> Ana Ruiz</div>
      <div class="option"><span class="box checked">✓</span> Zoe Diaz</div>
      <div class="option"><span class="box"></span> Marco Pi</div>
    </div>
  </div>
</body>
</html>
```

- [ ] **Step 2: Create the DesignSync project**

Call `DesignSync` with `method: "create_project"`, `name: "Aula+ Design System"`. Keep the returned `projectId` for the next steps.

- [ ] **Step 3: Finalize the plan**

Call `DesignSync` with:
```json
{
  "method": "finalize_plan",
  "projectId": "<projectId from step 2>",
  "localDir": "web/design-previews",
  "writes": ["table.html", "confirm-dialog.html", "multi-select.html"]
}
```
Keep the returned `planId`.

- [ ] **Step 4: Write the files**

Call `DesignSync` with `method: "write_files"`, the `projectId`, the `planId`, and:
```json
{
  "files": [
    { "path": "table.html", "localPath": "table.html" },
    { "path": "confirm-dialog.html", "localPath": "confirm-dialog.html" },
    { "path": "multi-select.html", "localPath": "multi-select.html" }
  ]
}
```

- [ ] **Step 5: Ask the user to review**

Tell the user the 3 previews are up in the "Aula+ Design System" project on claude.ai/design and ask them to confirm the direction before Task 3 builds the real components. Note explicitly that these are hand-authored HTML snapshots, not a live render of the eventual TSX — if they diverge, the real component (built in Tasks 3–4) wins.

- [ ] **Step 6: Commit the preview source files**

```bash
git add web/design-previews/
git commit -m "docs(web): add DesignSync previews for table/dialog/multi-select"
```

---

## Task 3: `Dialog` primitives + `ConfirmDialog`

**Files:**
- Create: `web/src/components/ui/dialog.tsx`
- Create: `web/src/components/ui/confirm-dialog.tsx`
- Modify: `web/src/test/setup.ts`
- Test: `web/src/components/ui/confirm-dialog.test.tsx`

**Interfaces:**
- Produces (from `dialog.tsx`): `Dialog`, `DialogTrigger`, `DialogPortal`, `DialogClose`, `DialogOverlay`, `DialogContent`, `DialogHeader`, `DialogFooter`, `DialogTitle`, `DialogDescription` — thin wrappers over `radix-ui`'s `Dialog` namespace, same shape as this repo's other `components/ui/*.tsx` files.
- Produces (from `confirm-dialog.tsx`): `ConfirmDialog({ trigger: React.ReactNode, title: string, description: string, confirmLabel?: string, isConfirming?: boolean, onConfirm: () => void })` — used by Task 6 (`GroupFormPage`) and Task 8 (`StudentFormPage`) to replace `window.confirm()`.

- [ ] **Step 1: Add jsdom polyfills Radix's Popper/Dialog internals need**

Radix's `Dialog` (Task 3) and `Popover` (Task 4) use `ResizeObserver` and pointer-capture APIs that jsdom doesn't implement, which throws on mount without a stub. Add them once, in the shared test setup:

```ts
// web/src/test/setup.ts
import "@testing-library/jest-dom/vitest"

if (typeof globalThis.ResizeObserver === "undefined") {
  class ResizeObserverStub {
    observe() {}
    unobserve() {}
    disconnect() {}
  }
  globalThis.ResizeObserver = ResizeObserverStub as unknown as typeof ResizeObserver
}

if (typeof Element.prototype.hasPointerCapture === "undefined") {
  Element.prototype.hasPointerCapture = () => false
}

if (typeof Element.prototype.scrollIntoView === "undefined") {
  Element.prototype.scrollIntoView = () => {}
}
```

- [ ] **Step 2: Write the failing test for `ConfirmDialog`**

```tsx
// web/src/components/ui/confirm-dialog.test.tsx
import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { describe, expect, it, vi } from "vitest"
import { ConfirmDialog } from "./confirm-dialog"
import { Button } from "./button"

describe("ConfirmDialog", () => {
  it("calls onConfirm when the user confirms", async () => {
    const onConfirm = vi.fn()

    render(
      <ConfirmDialog
        trigger={<Button>Eliminar</Button>}
        title="¿Eliminar?"
        description="Esta acción no se puede deshacer."
        onConfirm={onConfirm}
      />,
    )

    await userEvent.click(screen.getByRole("button", { name: "Eliminar" }))
    await userEvent.click(await screen.findByRole("button", { name: "Confirmar" }))

    expect(onConfirm).toHaveBeenCalledOnce()
  })

  it("does not call onConfirm when the user cancels", async () => {
    const onConfirm = vi.fn()

    render(
      <ConfirmDialog
        trigger={<Button>Eliminar</Button>}
        title="¿Eliminar?"
        description="Esta acción no se puede deshacer."
        onConfirm={onConfirm}
      />,
    )

    await userEvent.click(screen.getByRole("button", { name: "Eliminar" }))
    await userEvent.click(await screen.findByRole("button", { name: "Cancelar" }))

    expect(onConfirm).not.toHaveBeenCalled()
    expect(screen.queryByText("¿Eliminar?")).not.toBeInTheDocument()
  })
})
```

- [ ] **Step 3: Run test to verify it fails**

Run: `npm run test -- src/components/ui/confirm-dialog.test.tsx`
Expected: FAIL — `Failed to resolve import "./confirm-dialog"`.

- [ ] **Step 4: Write the `Dialog` primitives**

```tsx
// web/src/components/ui/dialog.tsx
import * as React from "react"
import { Dialog as DialogPrimitive } from "radix-ui"
import { X } from "lucide-react"

import { cn } from "@/lib/utils"

const Dialog = DialogPrimitive.Root
const DialogTrigger = DialogPrimitive.Trigger
const DialogPortal = DialogPrimitive.Portal
const DialogClose = DialogPrimitive.Close

function DialogOverlay({
  className,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Overlay>) {
  return (
    <DialogPrimitive.Overlay
      data-slot="dialog-overlay"
      className={cn("fixed inset-0 z-50 bg-black/50", className)}
      {...props}
    />
  )
}

function DialogContent({
  className,
  children,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Content>) {
  return (
    <DialogPortal>
      <DialogOverlay />
      <DialogPrimitive.Content
        data-slot="dialog-content"
        className={cn(
          "fixed top-1/2 left-1/2 z-50 grid w-full max-w-lg -translate-x-1/2 -translate-y-1/2 gap-4 rounded-lg border bg-background p-6 shadow-lg",
          className
        )}
        {...props}
      >
        {children}
        <DialogPrimitive.Close className="absolute top-4 right-4 rounded-xs opacity-70 outline-none hover:opacity-100">
          <X className="size-4" />
          <span className="sr-only">Cerrar</span>
        </DialogPrimitive.Close>
      </DialogPrimitive.Content>
    </DialogPortal>
  )
}

function DialogHeader({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="dialog-header"
      className={cn("flex flex-col gap-2 text-center sm:text-left", className)}
      {...props}
    />
  )
}

function DialogFooter({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="dialog-footer"
      className={cn("flex flex-col-reverse gap-2 sm:flex-row sm:justify-end", className)}
      {...props}
    />
  )
}

function DialogTitle({
  className,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Title>) {
  return (
    <DialogPrimitive.Title
      data-slot="dialog-title"
      className={cn("text-lg font-semibold", className)}
      {...props}
    />
  )
}

function DialogDescription({
  className,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Description>) {
  return (
    <DialogPrimitive.Description
      data-slot="dialog-description"
      className={cn("text-sm text-muted-foreground", className)}
      {...props}
    />
  )
}

export {
  Dialog,
  DialogTrigger,
  DialogPortal,
  DialogClose,
  DialogOverlay,
  DialogContent,
  DialogHeader,
  DialogFooter,
  DialogTitle,
  DialogDescription,
}
```

- [ ] **Step 5: Write `ConfirmDialog`**

```tsx
// web/src/components/ui/confirm-dialog.tsx
import type { ReactNode } from "react"

import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog"

export interface ConfirmDialogProps {
  trigger: ReactNode
  title: string
  description: string
  confirmLabel?: string
  isConfirming?: boolean
  onConfirm: () => void
}

export function ConfirmDialog({
  trigger,
  title,
  description,
  confirmLabel = "Confirmar",
  isConfirming = false,
  onConfirm,
}: ConfirmDialogProps) {
  return (
    <Dialog>
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <DialogClose asChild>
            <Button type="button" variant="outline">
              Cancelar
            </Button>
          </DialogClose>
          <Button type="button" variant="destructive" disabled={isConfirming} onClick={onConfirm}>
            {isConfirming ? "Eliminando…" : confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `npm run test -- src/components/ui/confirm-dialog.test.tsx`
Expected: PASS — 2 tests.

- [ ] **Step 7: Commit**

```bash
git add web/src/components/ui/dialog.tsx web/src/components/ui/confirm-dialog.tsx \
  web/src/components/ui/confirm-dialog.test.tsx web/src/test/setup.ts
git commit -m "feat(web): add Dialog primitives and ConfirmDialog"
```

---

## Task 4: `MultiSelect`

**Files:**
- Create: `web/src/components/ui/multi-select.tsx`
- Test: `web/src/components/ui/multi-select.test.tsx`

**Interfaces:**
- Consumes: nothing from earlier tasks (only `radix-ui`'s `Popover`, `lucide-react`, `cn()`).
- Produces: `MultiSelectOption { id: number; label: string }`, `MultiSelect({ id?: string, options: MultiSelectOption[], selected: number[], onChange: (selected: number[]) => void, placeholder?: string })` — used by Task 6 (`GroupFormPage`, for `teacher_ids`).

- [ ] **Step 1: Write the failing test**

```tsx
// web/src/components/ui/multi-select.test.tsx
import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { describe, expect, it, vi } from "vitest"
import { MultiSelect } from "./multi-select"

const options = [
  { id: 1, label: "Ana Ruiz" },
  { id: 2, label: "Zoe Diaz" },
]

describe("MultiSelect", () => {
  it("shows a placeholder when nothing is selected", () => {
    render(<MultiSelect options={options} selected={[]} onChange={vi.fn()} />)

    expect(screen.getByText("Seleccionar…")).toBeInTheDocument()
  })

  it("shows a chip for each selected option", () => {
    render(<MultiSelect options={options} selected={[1]} onChange={vi.fn()} />)

    expect(screen.getByText("Ana Ruiz")).toBeInTheDocument()
    expect(screen.queryByText("Zoe Diaz")).not.toBeInTheDocument()
  })

  it("adds an option to the selection when it is clicked", async () => {
    const onChange = vi.fn()
    render(<MultiSelect options={options} selected={[]} onChange={onChange} />)

    await userEvent.click(screen.getByTestId("multi-select-trigger"))
    await userEvent.click(await screen.findByRole("button", { name: "Ana Ruiz" }))

    expect(onChange).toHaveBeenCalledWith([1])
  })

  it("removes an option from the selection when it is clicked again", async () => {
    const onChange = vi.fn()
    render(<MultiSelect options={options} selected={[1, 2]} onChange={onChange} />)

    await userEvent.click(screen.getByTestId("multi-select-trigger"))
    await userEvent.click(await screen.findByRole("button", { name: "Ana Ruiz" }))

    expect(onChange).toHaveBeenCalledWith([2])
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test -- src/components/ui/multi-select.test.tsx`
Expected: FAIL — `Failed to resolve import "./multi-select"`.

- [ ] **Step 3: Write the implementation**

```tsx
// web/src/components/ui/multi-select.tsx
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test -- src/components/ui/multi-select.test.tsx`
Expected: PASS — 4 tests.

- [ ] **Step 5: Commit**

```bash
git add web/src/components/ui/multi-select.tsx web/src/components/ui/multi-select.test.tsx
git commit -m "feat(web): add generic MultiSelect component"
```

---

## Task 5: Groups slice — types, API client, `Table`, `GroupsListPage`

**Files:**
- Create: `web/src/components/ui/table.tsx`
- Modify: `web/src/types.ts` (only the `Group` interface)
- Modify: `web/src/features/groups/groupsApi.ts`
- Modify: `web/src/features/groups/GroupsListPage.tsx`
- Test: `web/src/features/groups/GroupsListPage.test.tsx`

**Interfaces:**
- Produces (`table.tsx`): `Table`, `TableHeader`, `TableBody`, `TableRow`, `TableHead`, `TableCell` — used by this task's `GroupsListPage` and by Task 7's `StudentsListPage`.
- Produces (`types.ts`): `Group { id, name, level, school_year, group_profile, related_documents, teachers: {id,name}[] }`.
- Produces (`groupsApi.ts`): `GroupInput { name, level?, school_year, teacher_ids? }`, and existing `fetchGroups/fetchGroup/createGroup/updateGroup/deleteGroup/fetchTeachers` now returning/accepting the new `Group`/`GroupInput` shape.

- [ ] **Step 1: Update the failing test to the new shape**

```tsx
// web/src/features/groups/GroupsListPage.test.tsx
import { render, screen } from "@testing-library/react"
import { MemoryRouter } from "react-router-dom"
import { describe, expect, it, vi } from "vitest"
import { GroupsListPage } from "./GroupsListPage"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import * as groupsApi from "./groupsApi"

function renderList(role: "director" | "teacher") {
  const value: AuthContextValue = {
    user: { id: 1, name: "Ana", email: "ana@escuela.test", roles: [role] },
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
  }

  return render(
    <AuthContext value={value}>
      <MemoryRouter>
        <GroupsListPage />
      </MemoryRouter>
    </AuthContext>,
  )
}

describe("GroupsListPage", () => {
  it("renders groups returned by the API and shows the create link for a director", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([
      {
        id: 1,
        name: "3° A",
        level: "Primaria",
        school_year: 2026,
        group_profile: null,
        related_documents: null,
        teachers: [{ id: 5, name: "Ana Ruiz" }],
      },
    ])

    renderList("director")

    expect(await screen.findByText("3° A")).toBeInTheDocument()
    expect(screen.getByText("Ana Ruiz")).toBeInTheDocument()
    expect(screen.getByRole("link", { name: /nueva clase/i })).toBeInTheDocument()
  })

  it("shows a dash when a group has no teachers assigned", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([
      {
        id: 1,
        name: "3° A",
        level: null,
        school_year: 2026,
        group_profile: null,
        related_documents: null,
        teachers: [],
      },
    ])

    renderList("director")

    const row = (await screen.findByText("3° A")).closest("tr")
    expect(row).not.toBeNull()
    expect(row!.textContent).toContain("—")
  })

  it("hides the create link for a teacher", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])

    renderList("teacher")

    expect(await screen.findByText(/todavía no hay clases/i)).toBeInTheDocument()
    expect(screen.queryByRole("link", { name: /nueva clase/i })).not.toBeInTheDocument()
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test -- src/features/groups/GroupsListPage.test.tsx`
Expected: FAIL — assertions on `"Ana Ruiz"` / `school_year` fail because `GroupsListPage`/`types.ts`/`groupsApi.ts` still use the old shape.

- [ ] **Step 3: Update `types.ts`'s `Group` interface**

```ts
// web/src/types.ts — replace only the Group interface with:
export interface Group {
  id: number
  name: string
  level: string | null
  school_year: number
  group_profile: unknown | null
  related_documents: unknown | null
  teachers: { id: number; name: string }[]
}
```

- [ ] **Step 4: Update `groupsApi.ts`**

```ts
// web/src/features/groups/groupsApi.ts
import { api } from "@/lib/api"
import type { Group } from "@/types"

export interface GroupInput {
  name: string
  level?: string
  school_year: number
  teacher_ids?: number[]
}

export async function fetchGroups(): Promise<Group[]> {
  const { data } = await api.get<{ data: Group[] }>("/api/groups")
  return data.data
}

export async function fetchGroup(id: number): Promise<Group> {
  const { data } = await api.get<{ data: Group }>(`/api/groups/${id}`)
  return data.data
}

export async function createGroup(input: GroupInput): Promise<Group> {
  const { data } = await api.post<{ data: Group }>("/api/groups", input)
  return data.data
}

export async function updateGroup(id: number, input: GroupInput): Promise<Group> {
  const { data } = await api.put<{ data: Group }>(`/api/groups/${id}`, input)
  return data.data
}

export async function deleteGroup(id: number): Promise<void> {
  await api.delete(`/api/groups/${id}`)
}

export interface Teacher {
  id: number
  name: string
}

export async function fetchTeachers(): Promise<Teacher[]> {
  const { data } = await api.get<{ data: Teacher[] }>("/api/teachers")
  return data.data
}
```

- [ ] **Step 5: Create the `Table` primitive**

```tsx
// web/src/components/ui/table.tsx
import * as React from "react"

import { cn } from "@/lib/utils"

function Table({ className, ...props }: React.ComponentProps<"table">) {
  return (
    <div data-slot="table-container" className="relative w-full overflow-x-auto">
      <table data-slot="table" className={cn("w-full caption-bottom text-sm", className)} {...props} />
    </div>
  )
}

function TableHeader({ className, ...props }: React.ComponentProps<"thead">) {
  return <thead data-slot="table-header" className={cn("[&_tr]:border-b", className)} {...props} />
}

function TableBody({ className, ...props }: React.ComponentProps<"tbody">) {
  return (
    <tbody data-slot="table-body" className={cn("[&_tr:last-child]:border-0", className)} {...props} />
  )
}

function TableRow({ className, ...props }: React.ComponentProps<"tr">) {
  return (
    <tr
      data-slot="table-row"
      className={cn("border-b transition-colors hover:bg-muted/50", className)}
      {...props}
    />
  )
}

function TableHead({ className, ...props }: React.ComponentProps<"th">) {
  return (
    <th
      data-slot="table-head"
      className={cn("h-10 px-4 text-left align-middle font-medium text-muted-foreground", className)}
      {...props}
    />
  )
}

function TableCell({ className, ...props }: React.ComponentProps<"td">) {
  return (
    <td data-slot="table-cell" className={cn("p-4 align-middle", className)} {...props} />
  )
}

export { Table, TableHeader, TableBody, TableRow, TableHead, TableCell }
```

- [ ] **Step 6: Rewrite `GroupsListPage.tsx`**

```tsx
// web/src/features/groups/GroupsListPage.tsx
import { useEffect, useState } from "react"
import { Link } from "react-router-dom"
import { useAuth } from "@/features/auth/AuthContext"
import { Button } from "@/components/ui/button"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import * as groupsApi from "./groupsApi"
import type { Group } from "@/types"

export function GroupsListPage() {
  const { user } = useAuth()
  const isDirector = user?.roles.includes("director") ?? false
  const [groups, setGroups] = useState<Group[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let active = true
    groupsApi
      .fetchGroups()
      .then((data) => {
        if (active) setGroups(data)
      })
      .catch(() => {
        if (active) setError("No pudimos cargar las clases.")
      })
    return () => {
      active = false
    }
  }, [])

  return (
    <div className="grid gap-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-semibold">Clases</h1>
        {isDirector && (
          <Button asChild>
            <Link to="/clases/nueva">Nueva clase</Link>
          </Button>
        )}
      </div>

      {error && <p className="text-sm text-destructive">{error}</p>}
      {!groups && !error && <p className="text-muted-foreground">Cargando…</p>}
      {groups && groups.length === 0 && (
        <p className="text-muted-foreground">Todavía no hay clases.</p>
      )}

      {groups && groups.length > 0 && (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Nombre</TableHead>
              <TableHead>Nivel</TableHead>
              <TableHead>Año</TableHead>
              <TableHead>Docentes</TableHead>
              {isDirector && <TableHead />}
            </TableRow>
          </TableHeader>
          <TableBody>
            {groups.map((group) => (
              <TableRow key={group.id}>
                <TableCell>{group.name}</TableCell>
                <TableCell>{group.level ?? "—"}</TableCell>
                <TableCell>{group.school_year}</TableCell>
                <TableCell>
                  {group.teachers.length > 0
                    ? group.teachers.map((teacher) => teacher.name).join(", ")
                    : "—"}
                </TableCell>
                {isDirector && (
                  <TableCell className="text-right">
                    <Link
                      className="text-primary underline-offset-4 hover:underline"
                      to={`/clases/${group.id}`}
                    >
                      Editar
                    </Link>
                  </TableCell>
                )}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </div>
  )
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `npm run test -- src/features/groups/GroupsListPage.test.tsx`
Expected: PASS — 3 tests. (Other test files, e.g. `GroupFormPage.test.tsx`, are still on the old shape and will fail if run — that's expected until Task 6.)

- [ ] **Step 8: Commit**

```bash
git add web/src/components/ui/table.tsx web/src/types.ts \
  web/src/features/groups/groupsApi.ts web/src/features/groups/GroupsListPage.tsx \
  web/src/features/groups/GroupsListPage.test.tsx
git commit -m "feat(web): realign Group type, groupsApi, and GroupsListPage to the new domain model"
```

---

## Task 6: `GroupFormPage`

**Files:**
- Modify: `web/src/features/groups/GroupFormPage.tsx`
- Test: `web/src/features/groups/GroupFormPage.test.tsx`

**Interfaces:**
- Consumes: `MultiSelect`/`MultiSelectOption` (Task 4), `ConfirmDialog` (Task 3), `getCurrentSchoolYear()` (Task 1), `GroupInput`/`Teacher`/`groupsApi.*` (Task 5).

- [ ] **Step 1: Update the failing test to the new shape**

```tsx
// web/src/features/groups/GroupFormPage.test.tsx
import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { GroupFormPage } from "./GroupFormPage"
import * as groupsApi from "./groupsApi"

function renderCreate() {
  return render(
    <MemoryRouter initialEntries={["/clases/nueva"]}>
      <Routes>
        <Route path="/clases/nueva" element={<GroupFormPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

function renderEdit(id = "1") {
  return render(
    <MemoryRouter initialEntries={[`/clases/${id}`]}>
      <Routes>
        <Route path="/clases/:id" element={<GroupFormPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

describe("GroupFormPage", () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  beforeEach(() => {
    vi.spyOn(groupsApi, "fetchTeachers").mockResolvedValue([])
  })

  it("shows a validation error and does not submit when name is empty", async () => {
    const createGroup = vi.spyOn(groupsApi, "createGroup")

    renderCreate()

    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(await screen.findByText(/ingresá un nombre/i)).toBeInTheDocument()
    expect(createGroup).not.toHaveBeenCalled()
  })

  it("calls createGroup with the entered values on a valid submit", async () => {
    const createGroup = vi.spyOn(groupsApi, "createGroup").mockResolvedValue({
      id: 1,
      name: "3° A",
      level: "",
      school_year: 2026,
      group_profile: null,
      related_documents: null,
      teachers: [],
    })

    renderCreate()

    await userEvent.type(screen.getByLabelText(/nombre/i), "3° A")
    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(createGroup).toHaveBeenCalledWith({
      name: "3° A",
      level: "",
      school_year: expect.any(Number),
      teacher_ids: [],
    })
  })

  it("does not show a delete button when creating a new group", () => {
    renderCreate()

    expect(screen.queryByRole("button", { name: /eliminar clase/i })).not.toBeInTheDocument()
  })

  it("selects teachers via the multi-select and submits their ids", async () => {
    vi.spyOn(groupsApi, "fetchTeachers").mockResolvedValue([
      { id: 5, name: "Ana Ruiz" },
      { id: 9, name: "Zoe Diaz" },
    ])
    const createGroup = vi.spyOn(groupsApi, "createGroup").mockResolvedValue({
      id: 1,
      name: "3° A",
      level: "",
      school_year: 2026,
      group_profile: null,
      related_documents: null,
      teachers: [{ id: 5, name: "Ana Ruiz" }],
    })

    renderCreate()

    await userEvent.type(screen.getByLabelText(/nombre/i), "3° A")
    await userEvent.click(screen.getByLabelText(/docentes/i))
    await userEvent.click(await screen.findByRole("button", { name: "Ana Ruiz" }))
    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(createGroup).toHaveBeenCalledWith(
      expect.objectContaining({ name: "3° A", teacher_ids: [5] }),
    )
  })

  describe("editing an existing group", () => {
    beforeEach(() => {
      vi.spyOn(groupsApi, "fetchGroup").mockResolvedValue({
        id: 1,
        name: "3° A",
        level: "Primaria",
        school_year: 2026,
        group_profile: null,
        related_documents: null,
        teachers: [{ id: 5, name: "Ana Ruiz" }],
      })
      vi.spyOn(groupsApi, "fetchTeachers").mockResolvedValue([
        { id: 5, name: "Ana Ruiz" },
        { id: 9, name: "Zoe Diaz" },
      ])
    })

    it("preselects the group's current teachers", async () => {
      renderEdit()

      expect(await screen.findByText("Ana Ruiz")).toBeInTheDocument()
    })

    it("shows a delete button and calls deleteGroup after confirming", async () => {
      const deleteGroup = vi.spyOn(groupsApi, "deleteGroup").mockResolvedValue(undefined)

      renderEdit()

      const deleteButton = await screen.findByRole("button", { name: /eliminar clase/i })
      await userEvent.click(deleteButton)
      await userEvent.click(await screen.findByRole("button", { name: "Eliminar" }))

      expect(deleteGroup).toHaveBeenCalledWith(1)
    })

    it("does not call deleteGroup when the confirmation is cancelled", async () => {
      const deleteGroup = vi.spyOn(groupsApi, "deleteGroup")

      renderEdit()

      const deleteButton = await screen.findByRole("button", { name: /eliminar clase/i })
      await userEvent.click(deleteButton)
      await userEvent.click(await screen.findByRole("button", { name: "Cancelar" }))

      expect(deleteGroup).not.toHaveBeenCalled()
    })
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test -- src/features/groups/GroupFormPage.test.tsx`
Expected: FAIL — `GroupFormPage` still registers `year`/`teacher_id`, has no "Docentes" label, and deletes via `window.confirm`.

- [ ] **Step 3: Rewrite `GroupFormPage.tsx`**

```tsx
// web/src/features/groups/GroupFormPage.tsx
import { useEffect, useState } from "react"
import { Controller, useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { useNavigate, useParams } from "react-router-dom"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { MultiSelect } from "@/components/ui/multi-select"
import { ConfirmDialog } from "@/components/ui/confirm-dialog"
import { getCurrentSchoolYear } from "@/lib/schoolYear"
import * as groupsApi from "./groupsApi"
import type { Teacher } from "./groupsApi"

const groupSchema = z.object({
  name: z.string().min(1, "Ingresá un nombre"),
  level: z.string().optional(),
  school_year: z.string().min(1, "Ingresá el año lectivo"),
  teacher_ids: z.array(z.number()),
})

type GroupValues = z.infer<typeof groupSchema>

export function GroupFormPage() {
  const { id } = useParams()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const [formError, setFormError] = useState<string | null>(null)
  const [isDeleting, setIsDeleting] = useState(false)
  const [teachers, setTeachers] = useState<Teacher[]>([])

  const {
    register,
    handleSubmit,
    reset,
    control,
    formState: { errors, isSubmitting },
  } = useForm<GroupValues>({
    resolver: zodResolver(groupSchema),
    defaultValues: {
      name: "",
      level: "",
      school_year: String(getCurrentSchoolYear()),
      teacher_ids: [],
    },
  })

  useEffect(() => {
    groupsApi.fetchTeachers().then(setTeachers)
  }, [])

  useEffect(() => {
    if (!id) return
    groupsApi.fetchGroup(Number(id)).then((group) => {
      reset({
        name: group.name,
        level: group.level ?? "",
        school_year: String(group.school_year),
        teacher_ids: group.teachers.map((teacher) => teacher.id),
      })
    })
  }, [id, reset])

  async function onSubmit(values: GroupValues) {
    setFormError(null)
    try {
      const input = {
        name: values.name,
        level: values.level,
        school_year: Number(values.school_year),
        teacher_ids: values.teacher_ids,
      }
      if (isEdit) {
        await groupsApi.updateGroup(Number(id), input)
      } else {
        await groupsApi.createGroup(input)
      }
      navigate("/clases", { replace: true })
    } catch {
      setFormError("No pudimos guardar la clase.")
    }
  }

  async function onDelete() {
    if (!id) return
    setFormError(null)
    setIsDeleting(true)
    try {
      await groupsApi.deleteGroup(Number(id))
      navigate("/clases", { replace: true })
    } catch {
      setFormError("No pudimos eliminar la clase.")
      setIsDeleting(false)
    }
  }

  const teacherOptions = teachers.map((teacher) => ({ id: teacher.id, label: teacher.name }))

  return (
    <Card className="max-w-lg">
      <CardHeader>
        <CardTitle>{isEdit ? "Editar clase" : "Nueva clase"}</CardTitle>
      </CardHeader>
      <CardContent>
        <form onSubmit={handleSubmit(onSubmit)} className="grid gap-4" noValidate>
          <div className="grid gap-2">
            <Label htmlFor="name">Nombre</Label>
            <Input id="name" {...register("name")} />
            {errors.name && <p className="text-sm text-destructive">{errors.name.message}</p>}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="level">Nivel</Label>
            <Input id="level" {...register("level")} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="school_year">Año lectivo</Label>
            <Input id="school_year" type="number" {...register("school_year")} />
            {errors.school_year && (
              <p className="text-sm text-destructive">{errors.school_year.message}</p>
            )}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="teacher_ids">Docentes</Label>
            <Controller
              name="teacher_ids"
              control={control}
              render={({ field }) => (
                <MultiSelect
                  id="teacher_ids"
                  options={teacherOptions}
                  selected={field.value}
                  onChange={field.onChange}
                />
              )}
            />
          </div>
          {formError && (
            <p role="alert" className="text-sm text-destructive">
              {formError}
            </p>
          )}
          <div className="flex gap-2">
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? "Guardando…" : "Guardar"}
            </Button>
            {isEdit && (
              <ConfirmDialog
                trigger={
                  <Button type="button" variant="destructive" disabled={isDeleting}>
                    {isDeleting ? "Eliminando…" : "Eliminar clase"}
                  </Button>
                }
                title="Eliminar clase"
                description="Esta acción no se puede deshacer."
                confirmLabel="Eliminar"
                isConfirming={isDeleting}
                onConfirm={onDelete}
              />
            )}
          </div>
        </form>
      </CardContent>
    </Card>
  )
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test -- src/features/groups/GroupFormPage.test.tsx`
Expected: PASS — 7 tests.

- [ ] **Step 5: Commit**

```bash
git add web/src/features/groups/GroupFormPage.tsx web/src/features/groups/GroupFormPage.test.tsx
git commit -m "feat(web): realign GroupFormPage to teacher_ids/school_year and ConfirmDialog delete"
```

---

## Task 7: Students slice — types, API client, `StudentsListPage`

**Files:**
- Modify: `web/src/types.ts` (the `Student` interface; remove `StudentStatus`/`studentStatusLabels`)
- Modify: `web/src/features/students/studentsApi.ts`
- Modify: `web/src/features/students/StudentsListPage.tsx`
- Test: `web/src/features/students/StudentsListPage.test.tsx`

**Interfaces:**
- Consumes: `Table`/`TableHeader`/`TableBody`/`TableRow`/`TableHead`/`TableCell` (Task 5), `getCurrentSchoolYear()` (Task 1).
- Produces (`types.ts`): `Student { id, full_name, photo_url, birth_date, enrollment_year, has_therapeutic_companion, groups: {id,name,school_year}[], learning_profile?, tracking_notes?, individual_profile?, related_documents? }`.
- Produces (`studentsApi.ts`): `StudentInput { full_name, photo_url?, birth_date?, enrollment_year, has_therapeutic_companion?, group_id?, school_year?, learning_profile?, tracking_notes?, individual_profile?, related_documents? }`.

- [ ] **Step 1: Update the failing test to the new shape**

```tsx
// web/src/features/students/StudentsListPage.test.tsx
import { render, screen } from "@testing-library/react"
import { MemoryRouter } from "react-router-dom"
import { describe, expect, it, vi } from "vitest"
import { StudentsListPage } from "./StudentsListPage"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import * as studentsApi from "./studentsApi"

function renderList(role: "director" | "teacher" | "psychopedagogue") {
  const value: AuthContextValue = {
    user: { id: 1, name: "Ana", email: "ana@escuela.test", roles: [role] },
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
  }

  return render(
    <AuthContext value={value}>
      <MemoryRouter>
        <StudentsListPage />
      </MemoryRouter>
    </AuthContext>,
  )
}

describe("StudentsListPage", () => {
  it("renders students returned by the API and shows the create link for a director", async () => {
    const currentYear = new Date().getFullYear()
    vi.spyOn(studentsApi, "fetchStudents").mockResolvedValue([
      {
        id: 1,
        full_name: "Ana Gómez",
        photo_url: null,
        birth_date: null,
        enrollment_year: 2024,
        has_therapeutic_companion: false,
        groups: [{ id: 1, name: "3° A", school_year: currentYear }],
      },
    ])

    renderList("director")

    expect(await screen.findByText("Ana Gómez")).toBeInTheDocument()
    expect(screen.getByText("3° A")).toBeInTheDocument()
    expect(screen.getByRole("link", { name: /nuevo alumno/i })).toBeInTheDocument()
  })

  it("shows the create link for a psychopedagogue too", async () => {
    vi.spyOn(studentsApi, "fetchStudents").mockResolvedValue([])

    renderList("psychopedagogue")

    expect(await screen.findByText(/todavía no hay alumnos/i)).toBeInTheDocument()
    expect(screen.getByRole("link", { name: /nuevo alumno/i })).toBeInTheDocument()
  })

  it("shows a dash when the student has no group for the current school year", async () => {
    vi.spyOn(studentsApi, "fetchStudents").mockResolvedValue([
      {
        id: 1,
        full_name: "Ana Gómez",
        photo_url: null,
        birth_date: null,
        enrollment_year: 2024,
        has_therapeutic_companion: false,
        groups: [{ id: 1, name: "3° A", school_year: 2020 }],
      },
    ])

    renderList("director")

    const row = (await screen.findByText("Ana Gómez")).closest("tr")
    expect(row).not.toBeNull()
    expect(row!.textContent).toContain("—")
  })

  it("hides the create link for a teacher", async () => {
    vi.spyOn(studentsApi, "fetchStudents").mockResolvedValue([])

    renderList("teacher")

    expect(await screen.findByText(/todavía no hay alumnos/i)).toBeInTheDocument()
    expect(screen.queryByRole("link", { name: /nuevo alumno/i })).not.toBeInTheDocument()
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test -- src/features/students/StudentsListPage.test.tsx`
Expected: FAIL — old `Student` shape (`first_name`/`status`) and `isDirector`-only gate.

- [ ] **Step 3: Update `types.ts`'s `Student` interface (and drop `StudentStatus`)**

```ts
// web/src/types.ts — full file after this step:
export type Role = "teacher" | "director" | "psychopedagogue"

/** User-facing Spanish labels for roles (the app UI is in Spanish). */
export const roleLabels: Record<Role, string> = {
  teacher: "Docente",
  director: "Director",
  psychopedagogue: "Psicopedagogo",
}

export interface School {
  id: number
  name: string
}

export interface User {
  id: number
  name: string
  email: string
  roles: Role[]
  school?: School
}

export interface Group {
  id: number
  name: string
  level: string | null
  school_year: number
  group_profile: unknown | null
  related_documents: unknown | null
  teachers: { id: number; name: string }[]
}

export interface Student {
  id: number
  full_name: string
  photo_url: string | null
  birth_date: string | null
  enrollment_year: number
  has_therapeutic_companion: boolean
  groups: { id: number; name: string; school_year: number }[]
  // Absent from the JSON (not null) when the viewer lacks
  // view-clinical-profile on this student.
  learning_profile?: unknown
  tracking_notes?: string
  individual_profile?: unknown
  related_documents?: unknown
}
```

- [ ] **Step 4: Update `studentsApi.ts`**

```ts
// web/src/features/students/studentsApi.ts
import { api } from "@/lib/api"
import type { Student } from "@/types"

export interface StudentInput {
  full_name: string
  photo_url?: string
  birth_date?: string
  enrollment_year: number
  has_therapeutic_companion?: boolean
  group_id?: number | null
  school_year?: number
  learning_profile?: unknown
  tracking_notes?: string
  individual_profile?: unknown
  related_documents?: unknown
}

export async function fetchStudents(): Promise<Student[]> {
  const { data } = await api.get<{ data: Student[] }>("/api/students")
  return data.data
}

export async function fetchStudent(id: number): Promise<Student> {
  const { data } = await api.get<{ data: Student }>(`/api/students/${id}`)
  return data.data
}

export async function createStudent(input: StudentInput): Promise<Student> {
  const { data } = await api.post<{ data: Student }>("/api/students", input)
  return data.data
}

export async function updateStudent(id: number, input: StudentInput): Promise<Student> {
  const { data } = await api.put<{ data: Student }>(`/api/students/${id}`, input)
  return data.data
}

export async function deleteStudent(id: number): Promise<void> {
  await api.delete(`/api/students/${id}`)
}
```

- [ ] **Step 5: Rewrite `StudentsListPage.tsx`**

```tsx
// web/src/features/students/StudentsListPage.tsx
import { useEffect, useState } from "react"
import { Link } from "react-router-dom"
import { useAuth } from "@/features/auth/AuthContext"
import { Button } from "@/components/ui/button"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { getCurrentSchoolYear } from "@/lib/schoolYear"
import * as studentsApi from "./studentsApi"
import type { Student } from "@/types"

export function StudentsListPage() {
  const { user } = useAuth()
  const canManage =
    user?.roles.some((role) => role === "director" || role === "psychopedagogue") ?? false
  const [students, setStudents] = useState<Student[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let active = true
    studentsApi
      .fetchStudents()
      .then((data) => {
        if (active) setStudents(data)
      })
      .catch(() => {
        if (active) setError("No pudimos cargar los alumnos.")
      })
    return () => {
      active = false
    }
  }, [])

  const currentYear = getCurrentSchoolYear()

  return (
    <div className="grid gap-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-semibold">Alumnos</h1>
        {canManage && (
          <Button asChild>
            <Link to="/alumnos/nuevo">Nuevo alumno</Link>
          </Button>
        )}
      </div>

      {error && <p className="text-sm text-destructive">{error}</p>}
      {!students && !error && <p className="text-muted-foreground">Cargando…</p>}
      {students && students.length === 0 && (
        <p className="text-muted-foreground">Todavía no hay alumnos cargados.</p>
      )}

      {students && students.length > 0 && (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Nombre</TableHead>
              <TableHead>Clase</TableHead>
              {canManage && <TableHead />}
            </TableRow>
          </TableHeader>
          <TableBody>
            {students.map((student) => {
              const currentGroup = student.groups.find(
                (group) => group.school_year === currentYear,
              )
              return (
                <TableRow key={student.id}>
                  <TableCell>{student.full_name}</TableCell>
                  <TableCell>{currentGroup?.name ?? "—"}</TableCell>
                  {canManage && (
                    <TableCell className="text-right">
                      <Link
                        className="text-primary underline-offset-4 hover:underline"
                        to={`/alumnos/${student.id}`}
                      >
                        Editar
                      </Link>
                    </TableCell>
                  )}
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      )}
    </div>
  )
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `npm run test -- src/features/students/StudentsListPage.test.tsx`
Expected: PASS — 4 tests.

- [ ] **Step 7: Commit**

```bash
git add web/src/types.ts web/src/features/students/studentsApi.ts \
  web/src/features/students/StudentsListPage.tsx web/src/features/students/StudentsListPage.test.tsx
git commit -m "feat(web): realign Student type, studentsApi, and StudentsListPage to the new domain model"
```

---

## Task 8: `StudentFormPage` — basic fields

**Files:**
- Modify: `web/src/features/students/StudentFormPage.tsx`
- Test: `web/src/features/students/StudentFormPage.test.tsx`

**Interfaces:**
- Consumes: `ConfirmDialog` (Task 3), `getCurrentSchoolYear()` (Task 1), `Student`/`Group` types and `studentsApi`/`groupsApi.fetchGroups` (Task 7 / Task 5).
- Produces: nothing new — this task's output is consumed only by Task 9, which edits the same file.

- [ ] **Step 1: Write the failing test (basic fields only — Task 9 adds the clinical-fields cases)**

```tsx
// web/src/features/students/StudentFormPage.test.tsx
import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { describe, expect, it, vi } from "vitest"
import { StudentFormPage } from "./StudentFormPage"
import * as studentsApi from "./studentsApi"
import * as groupsApi from "@/features/groups/groupsApi"

function renderCreate() {
  return render(
    <MemoryRouter initialEntries={["/alumnos/nuevo"]}>
      <Routes>
        <Route path="/alumnos/nuevo" element={<StudentFormPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

describe("StudentFormPage", () => {
  it("shows validation errors and does not submit when required fields are empty", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])
    const createStudent = vi.spyOn(studentsApi, "createStudent")

    renderCreate()

    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(await screen.findByText(/ingresá un nombre/i)).toBeInTheDocument()
    expect(createStudent).not.toHaveBeenCalled()
  })

  it("calls createStudent with the entered values on a valid submit", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])
    const createStudent = vi.spyOn(studentsApi, "createStudent").mockResolvedValue({
      id: 1,
      full_name: "Ana Gómez",
      photo_url: null,
      birth_date: null,
      enrollment_year: 2026,
      has_therapeutic_companion: false,
      groups: [],
    })

    renderCreate()

    await userEvent.type(screen.getByLabelText(/nombre completo/i), "Ana Gómez")
    await userEvent.type(screen.getByLabelText(/año de ingreso/i), "2026")
    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(createStudent).toHaveBeenCalledWith(
      expect.objectContaining({ full_name: "Ana Gómez", enrollment_year: 2026 }),
    )
  })

  it("only lists groups for the current school year", async () => {
    const currentYear = new Date().getFullYear()
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([
      {
        id: 1,
        name: "3° A (actual)",
        level: null,
        school_year: currentYear,
        group_profile: null,
        related_documents: null,
        teachers: [],
      },
      {
        id: 2,
        name: "2° A (pasado)",
        level: null,
        school_year: currentYear - 1,
        group_profile: null,
        related_documents: null,
        teachers: [],
      },
    ])

    renderCreate()

    expect(await screen.findByRole("option", { name: "3° A (actual)" })).toBeInTheDocument()
    expect(screen.queryByRole("option", { name: "2° A (pasado)" })).not.toBeInTheDocument()
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test -- src/features/students/StudentFormPage.test.tsx`
Expected: FAIL — old form registers `first_name`/`last_name`/`status`, no `enrollment_year`.

- [ ] **Step 3: Rewrite `StudentFormPage.tsx` (basic fields)**

```tsx
// web/src/features/students/StudentFormPage.tsx
import { useEffect, useState } from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { useNavigate, useParams } from "react-router-dom"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select } from "@/components/ui/select"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { ConfirmDialog } from "@/components/ui/confirm-dialog"
import { getCurrentSchoolYear } from "@/lib/schoolYear"
import type { Group } from "@/types"
import * as studentsApi from "./studentsApi"
import * as groupsApi from "@/features/groups/groupsApi"

const studentSchema = z.object({
  full_name: z.string().min(1, "Ingresá un nombre"),
  photo_url: z.string().optional(),
  birth_date: z.string().optional(),
  enrollment_year: z.string().min(1, "Ingresá el año de ingreso"),
  has_therapeutic_companion: z.boolean(),
  group_id: z.string().optional(),
})

type StudentValues = z.infer<typeof studentSchema>

export function StudentFormPage() {
  const { id } = useParams()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const [formError, setFormError] = useState<string | null>(null)
  const [isDeleting, setIsDeleting] = useState(false)
  const [groups, setGroups] = useState<Group[]>([])
  const currentYear = getCurrentSchoolYear()

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<StudentValues>({
    resolver: zodResolver(studentSchema),
    defaultValues: {
      full_name: "",
      photo_url: "",
      birth_date: "",
      enrollment_year: "",
      has_therapeutic_companion: false,
      group_id: "",
    },
  })

  useEffect(() => {
    groupsApi.fetchGroups().then((allGroups) => {
      setGroups(allGroups.filter((group) => group.school_year === currentYear))
    })
  }, [currentYear])

  useEffect(() => {
    if (!id) return
    studentsApi.fetchStudent(Number(id)).then((student) => {
      const currentGroup = student.groups.find((group) => group.school_year === currentYear)
      reset({
        full_name: student.full_name,
        photo_url: student.photo_url ?? "",
        birth_date: student.birth_date ?? "",
        enrollment_year: String(student.enrollment_year),
        has_therapeutic_companion: student.has_therapeutic_companion,
        group_id: currentGroup ? String(currentGroup.id) : "",
      })
    })
  }, [id, reset, currentYear])

  async function onSubmit(values: StudentValues) {
    setFormError(null)
    try {
      const input = {
        full_name: values.full_name,
        photo_url: values.photo_url || undefined,
        birth_date: values.birth_date || undefined,
        enrollment_year: Number(values.enrollment_year),
        has_therapeutic_companion: values.has_therapeutic_companion,
        group_id: values.group_id ? Number(values.group_id) : null,
        school_year: values.group_id ? currentYear : undefined,
      }
      if (isEdit) {
        await studentsApi.updateStudent(Number(id), input)
      } else {
        await studentsApi.createStudent(input)
      }
      navigate("/alumnos", { replace: true })
    } catch {
      setFormError("No pudimos guardar el alumno.")
    }
  }

  async function onDelete() {
    if (!id) return
    setFormError(null)
    setIsDeleting(true)
    try {
      await studentsApi.deleteStudent(Number(id))
      navigate("/alumnos", { replace: true })
    } catch {
      setFormError("No pudimos eliminar el alumno.")
      setIsDeleting(false)
    }
  }

  return (
    <Card className="max-w-lg">
      <CardHeader>
        <CardTitle>{isEdit ? "Editar alumno" : "Nuevo alumno"}</CardTitle>
      </CardHeader>
      <CardContent>
        <form onSubmit={handleSubmit(onSubmit)} className="grid gap-4" noValidate>
          <div className="grid gap-2">
            <Label htmlFor="full_name">Nombre completo</Label>
            <Input id="full_name" {...register("full_name")} />
            {errors.full_name && (
              <p className="text-sm text-destructive">{errors.full_name.message}</p>
            )}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="photo_url">URL de foto</Label>
            <Input id="photo_url" {...register("photo_url")} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="birth_date">Fecha de nacimiento</Label>
            <Input id="birth_date" type="date" {...register("birth_date")} />
          </div>
          <div className="grid gap-2">
            <Label htmlFor="enrollment_year">Año de ingreso</Label>
            <Input id="enrollment_year" type="number" {...register("enrollment_year")} />
            {errors.enrollment_year && (
              <p className="text-sm text-destructive">{errors.enrollment_year.message}</p>
            )}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="group_id">Clase ({currentYear})</Label>
            <Select id="group_id" {...register("group_id")}>
              <option value="">Sin clase asignada</option>
              {groups.map((group) => (
                <option key={group.id} value={group.id}>
                  {group.name}
                </option>
              ))}
            </Select>
          </div>
          <div className="flex items-center gap-2">
            <input
              id="has_therapeutic_companion"
              type="checkbox"
              className="size-4"
              {...register("has_therapeutic_companion")}
            />
            <Label htmlFor="has_therapeutic_companion">Tiene acompañante terapéutico</Label>
          </div>
          {formError && (
            <p role="alert" className="text-sm text-destructive">
              {formError}
            </p>
          )}
          <div className="flex gap-2">
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? "Guardando…" : "Guardar"}
            </Button>
            {isEdit && (
              <ConfirmDialog
                trigger={
                  <Button type="button" variant="destructive" disabled={isDeleting}>
                    {isDeleting ? "Eliminando…" : "Eliminar alumno"}
                  </Button>
                }
                title="Eliminar alumno"
                description="Esta acción no se puede deshacer."
                confirmLabel="Eliminar"
                isConfirming={isDeleting}
                onConfirm={onDelete}
              />
            )}
          </div>
        </form>
      </CardContent>
    </Card>
  )
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test -- src/features/students/StudentFormPage.test.tsx`
Expected: PASS — 3 tests.

- [ ] **Step 5: Commit**

```bash
git add web/src/features/students/StudentFormPage.tsx web/src/features/students/StudentFormPage.test.tsx
git commit -m "feat(web): realign StudentFormPage basic fields to the new domain model"
```

---

## Task 9: `StudentFormPage` — clinical profile section

**Files:**
- Modify: `web/src/features/students/StudentFormPage.tsx`
- Test: `web/src/features/students/StudentFormPage.test.tsx`

**Interfaces:**
- Consumes: `useAuth()` (existing, from `@/features/auth/AuthContext`), `Textarea` (existing, from `@/components/ui/textarea`), plus everything from Task 8.

- [ ] **Step 1: Extend the test file with the clinical-section cases (failing)**

Replace the whole file with:

```tsx
// web/src/features/students/StudentFormPage.test.tsx
import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { MemoryRouter, Route, Routes } from "react-router-dom"
import { describe, expect, it, vi } from "vitest"
import { StudentFormPage } from "./StudentFormPage"
import { AuthContext, type AuthContextValue } from "@/features/auth/AuthContext"
import * as studentsApi from "./studentsApi"
import * as groupsApi from "@/features/groups/groupsApi"
import type { Role } from "@/types"

function renderCreate(role: Role = "director") {
  const value: AuthContextValue = {
    user: { id: 1, name: "Ana", email: "ana@escuela.test", roles: [role] },
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
  }

  return render(
    <AuthContext value={value}>
      <MemoryRouter initialEntries={["/alumnos/nuevo"]}>
        <Routes>
          <Route path="/alumnos/nuevo" element={<StudentFormPage />} />
        </Routes>
      </MemoryRouter>
    </AuthContext>,
  )
}

function renderEdit(role: Role = "director", id = "1") {
  const value: AuthContextValue = {
    user: { id: 1, name: "Ana", email: "ana@escuela.test", roles: [role] },
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
  }

  return render(
    <AuthContext value={value}>
      <MemoryRouter initialEntries={[`/alumnos/${id}`]}>
        <Routes>
          <Route path="/alumnos/:id" element={<StudentFormPage />} />
        </Routes>
      </MemoryRouter>
    </AuthContext>,
  )
}

describe("StudentFormPage", () => {
  it("shows validation errors and does not submit when required fields are empty", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])
    const createStudent = vi.spyOn(studentsApi, "createStudent")

    renderCreate()

    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(await screen.findByText(/ingresá un nombre/i)).toBeInTheDocument()
    expect(createStudent).not.toHaveBeenCalled()
  })

  it("calls createStudent with the entered values on a valid submit", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])
    const createStudent = vi.spyOn(studentsApi, "createStudent").mockResolvedValue({
      id: 1,
      full_name: "Ana Gómez",
      photo_url: null,
      birth_date: null,
      enrollment_year: 2026,
      has_therapeutic_companion: false,
      groups: [],
    })

    renderCreate()

    await userEvent.type(screen.getByLabelText(/nombre completo/i), "Ana Gómez")
    await userEvent.type(screen.getByLabelText(/año de ingreso/i), "2026")
    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(createStudent).toHaveBeenCalledWith(
      expect.objectContaining({ full_name: "Ana Gómez", enrollment_year: 2026 }),
    )
  })

  it("only lists groups for the current school year", async () => {
    const currentYear = new Date().getFullYear()
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([
      {
        id: 1,
        name: "3° A (actual)",
        level: null,
        school_year: currentYear,
        group_profile: null,
        related_documents: null,
        teachers: [],
      },
      {
        id: 2,
        name: "2° A (pasado)",
        level: null,
        school_year: currentYear - 1,
        group_profile: null,
        related_documents: null,
        teachers: [],
      },
    ])

    renderCreate()

    expect(await screen.findByRole("option", { name: "3° A (actual)" })).toBeInTheDocument()
    expect(screen.queryByRole("option", { name: "2° A (pasado)" })).not.toBeInTheDocument()
  })

  it("shows the clinical profile section for a director", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])

    renderCreate("director")

    expect(await screen.findByLabelText(/perfil de aprendizaje/i)).toBeInTheDocument()
  })

  it("shows the clinical profile section for a psychopedagogue", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])

    renderCreate("psychopedagogue")

    expect(await screen.findByLabelText(/perfil de aprendizaje/i)).toBeInTheDocument()
  })

  it("hides the clinical profile section for a teacher", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])

    renderCreate("teacher")

    await screen.findByLabelText(/nombre completo/i)
    expect(screen.queryByLabelText(/perfil de aprendizaje/i)).not.toBeInTheDocument()
  })

  it("rejects invalid JSON in a clinical field without submitting", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])
    const createStudent = vi.spyOn(studentsApi, "createStudent")

    renderCreate("director")

    await userEvent.type(screen.getByLabelText(/nombre completo/i), "Ana Gómez")
    await userEvent.type(screen.getByLabelText(/año de ingreso/i), "2026")
    await userEvent.type(screen.getByLabelText(/perfil de aprendizaje/i), "no es json")
    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(await screen.findByText(/ingresá un json válido/i)).toBeInTheDocument()
    expect(createStudent).not.toHaveBeenCalled()
  })

  it("sends parsed JSON clinical fields on a valid submit", async () => {
    vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])
    const createStudent = vi.spyOn(studentsApi, "createStudent").mockResolvedValue({
      id: 1,
      full_name: "Ana Gómez",
      photo_url: null,
      birth_date: null,
      enrollment_year: 2026,
      has_therapeutic_companion: false,
      groups: [],
    })

    renderCreate("director")

    await userEvent.type(screen.getByLabelText(/nombre completo/i), "Ana Gómez")
    await userEvent.type(screen.getByLabelText(/año de ingreso/i), "2026")
    await userEvent.type(screen.getByLabelText(/perfil de aprendizaje/i), '{{"style":"visual"}}')
    await userEvent.click(screen.getByRole("button", { name: /guardar/i }))

    expect(createStudent).toHaveBeenCalledWith(
      expect.objectContaining({ learning_profile: { style: "visual" } }),
    )
  })

  describe("editing an existing student", () => {
    it("preselects the student's current-year group and prefills the clinical profile", async () => {
      const currentYear = new Date().getFullYear()
      vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([
        {
          id: 1,
          name: "3° A",
          level: null,
          school_year: currentYear,
          group_profile: null,
          related_documents: null,
          teachers: [],
        },
      ])
      vi.spyOn(studentsApi, "fetchStudent").mockResolvedValue({
        id: 1,
        full_name: "Ana Gómez",
        photo_url: null,
        birth_date: null,
        enrollment_year: 2024,
        has_therapeutic_companion: false,
        groups: [{ id: 1, name: "3° A", school_year: currentYear }],
        learning_profile: { style: "visual" },
        tracking_notes: "Progresa bien.",
        individual_profile: null,
        related_documents: null,
      })

      renderEdit("director")

      const select = (await screen.findByLabelText(/clase/i)) as HTMLSelectElement
      expect(select.value).toBe("1")
      expect(await screen.findByLabelText(/notas de seguimiento/i)).toHaveValue("Progresa bien.")
    })

    it("shows a delete button and calls deleteStudent after confirming", async () => {
      vi.spyOn(groupsApi, "fetchGroups").mockResolvedValue([])
      vi.spyOn(studentsApi, "fetchStudent").mockResolvedValue({
        id: 1,
        full_name: "Ana Gómez",
        photo_url: null,
        birth_date: null,
        enrollment_year: 2024,
        has_therapeutic_companion: false,
        groups: [],
      })
      const deleteStudent = vi.spyOn(studentsApi, "deleteStudent").mockResolvedValue(undefined)

      renderEdit("director")

      const deleteButton = await screen.findByRole("button", { name: /eliminar alumno/i })
      await userEvent.click(deleteButton)
      await userEvent.click(await screen.findByRole("button", { name: "Eliminar" }))

      expect(deleteStudent).toHaveBeenCalledWith(1)
    })
  })
})
```

- [ ] **Step 2: Run test to verify the new cases fail**

Run: `npm run test -- src/features/students/StudentFormPage.test.tsx`
Expected: the 6 pre-existing cases still PASS (same component, same `renderCreate()` default role `"director"`); the new clinical-profile cases FAIL — no such fields exist yet, and `renderCreate`/`renderEdit` now wrap in `AuthContext`, which the current component doesn't need but doesn't break either.

- [ ] **Step 3: Add the clinical-profile section to `StudentFormPage.tsx`**

Apply these changes to the file from Task 8:

Add imports:
```tsx
import { Textarea } from "@/components/ui/textarea"
import { useAuth } from "@/features/auth/AuthContext"
```

Add the JSON-object validator and extend the schema:
```tsx
function isValidJsonObjectOrEmpty(value: string | undefined): boolean {
  if (!value) return true
  try {
    const parsed = JSON.parse(value)
    return typeof parsed === "object" && parsed !== null
  } catch {
    return false
  }
}

const studentSchema = z.object({
  full_name: z.string().min(1, "Ingresá un nombre"),
  photo_url: z.string().optional(),
  birth_date: z.string().optional(),
  enrollment_year: z.string().min(1, "Ingresá el año de ingreso"),
  has_therapeutic_companion: z.boolean(),
  group_id: z.string().optional(),
  learning_profile: z.string().optional().refine(isValidJsonObjectOrEmpty, "Ingresá un JSON válido"),
  tracking_notes: z.string().optional(),
  individual_profile: z
    .string()
    .optional()
    .refine(isValidJsonObjectOrEmpty, "Ingresá un JSON válido"),
  related_documents: z
    .string()
    .optional()
    .refine(isValidJsonObjectOrEmpty, "Ingresá un JSON válido"),
})
```

Inside the component, read the role and compute the gate:
```tsx
const { user } = useAuth()
const canEditClinicalProfile =
  user?.roles.some((role) => role === "director" || role === "psychopedagogue") ?? false
```

Extend the `defaultValues` object with:
```tsx
learning_profile: "",
tracking_notes: "",
individual_profile: "",
related_documents: "",
```

Extend the edit-fetch `reset()` call with:
```tsx
learning_profile:
  student.learning_profile !== undefined
    ? JSON.stringify(student.learning_profile, null, 2)
    : "",
tracking_notes: student.tracking_notes ?? "",
individual_profile:
  student.individual_profile !== undefined
    ? JSON.stringify(student.individual_profile, null, 2)
    : "",
related_documents:
  student.related_documents !== undefined
    ? JSON.stringify(student.related_documents, null, 2)
    : "",
```

In `onSubmit`, build the clinical fields conditionally and spread them into `input`:
```tsx
async function onSubmit(values: StudentValues) {
  setFormError(null)
  try {
    const clinicalFields = canEditClinicalProfile
      ? {
          learning_profile: values.learning_profile ? JSON.parse(values.learning_profile) : null,
          tracking_notes: values.tracking_notes || null,
          individual_profile: values.individual_profile
            ? JSON.parse(values.individual_profile)
            : null,
          related_documents: values.related_documents
            ? JSON.parse(values.related_documents)
            : null,
        }
      : {}

    const input = {
      full_name: values.full_name,
      photo_url: values.photo_url || undefined,
      birth_date: values.birth_date || undefined,
      enrollment_year: Number(values.enrollment_year),
      has_therapeutic_companion: values.has_therapeutic_companion,
      group_id: values.group_id ? Number(values.group_id) : null,
      school_year: values.group_id ? currentYear : undefined,
      ...clinicalFields,
    }
    if (isEdit) {
      await studentsApi.updateStudent(Number(id), input)
    } else {
      await studentsApi.createStudent(input)
    }
    navigate("/alumnos", { replace: true })
  } catch {
    setFormError("No pudimos guardar el alumno.")
  }
}
```

Add the section in the JSX, right after the `has_therapeutic_companion` checkbox block and before `{formError && ...}`:
```tsx
{canEditClinicalProfile && (
  <>
    <div className="grid gap-2">
      <Label htmlFor="tracking_notes">Notas de seguimiento</Label>
      <Textarea id="tracking_notes" {...register("tracking_notes")} />
    </div>
    <div className="grid gap-2">
      <Label htmlFor="learning_profile">Perfil de aprendizaje (JSON)</Label>
      <Textarea id="learning_profile" {...register("learning_profile")} />
      {errors.learning_profile && (
        <p className="text-sm text-destructive">{errors.learning_profile.message}</p>
      )}
    </div>
    <div className="grid gap-2">
      <Label htmlFor="individual_profile">Perfil individual (JSON)</Label>
      <Textarea id="individual_profile" {...register("individual_profile")} />
      {errors.individual_profile && (
        <p className="text-sm text-destructive">{errors.individual_profile.message}</p>
      )}
    </div>
    <div className="grid gap-2">
      <Label htmlFor="related_documents">Documentos relacionados (JSON)</Label>
      <Textarea id="related_documents" {...register("related_documents")} />
      {errors.related_documents && (
        <p className="text-sm text-destructive">{errors.related_documents.message}</p>
      )}
    </div>
  </>
)}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test -- src/features/students/StudentFormPage.test.tsx`
Expected: PASS — 9 tests.

- [ ] **Step 5: Commit**

```bash
git add web/src/features/students/StudentFormPage.tsx web/src/features/students/StudentFormPage.test.tsx
git commit -m "feat(web): add role-gated clinical profile section to StudentFormPage"
```

---

## Task 10: Final verification

**Files:** none (verification only).

- [ ] **Step 1: Run lint**

Run (from `web/`): `npm run lint`
Expected: no errors. If `oxlint` flags anything in the files touched by this plan, fix it before continuing.

- [ ] **Step 2: Run typecheck**

Run: `npm run typecheck`
Expected: no errors. This is the first point where the *whole* project (including files from every earlier task) type-checks together — if any earlier task left a stray reference to the old `Group`/`Student` shape, it surfaces here.

- [ ] **Step 3: Run the full test suite**

Run: `npm run test`
Expected: every test file passes, including `AppLayout.test.tsx` and `LoginPage.test.tsx` (untouched by this plan, should be unaffected).

- [ ] **Step 4: Run the production build**

Run: `npm run build`
Expected: builds cleanly (`tsc -b && vite build`), no errors.

- [ ] **Step 5: Fix and re-run if anything failed**

If any of Steps 1–4 fail, fix the specific issue in the relevant file (don't touch files outside this plan's scope) and re-run that step until it passes. Do not skip ahead.

- [ ] **Step 6: Final commit (only if Step 5 required fixes)**

```bash
git add web/
git commit -m "fix(web): address lint/typecheck/build issues from the realignment"
```
