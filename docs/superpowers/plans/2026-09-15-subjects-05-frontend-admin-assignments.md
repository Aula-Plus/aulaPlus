# Subjects — Session 5: Frontend subjects admin + teacher assignment UI

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give directors a Subjects ("Materias") admin screen (CRUD) and a UI to assign teacher-subject pairs to a group, both talking to the Session 1–2 endpoints.

**Architecture:** New `subjects` feature folder: a `subjectsApi.ts` data layer, a `SubjectsPage.tsx` CRUD screen (director-only), and a `GroupTeacherAssignments.tsx` panel. New `Subject` type + `subjectLabels` are added to `web/src/types.ts`. A `canManageSubjects` permission helper mirrors the backend director-only policy (UX only). New route `/materias` + a nav item, both gated to directors.

**Tech Stack:** React 19 + Vite + TypeScript + Tailwind v4 + shadcn/ui, axios (`src/lib/api.ts`), react-hook-form + Zod, Vitest + Testing Library. UI uses the shared `ui/` standard components (PageHeader, SectionCard, EmptyState, Button, SingleSelect, Input, ConfirmDialog).

## Global Constraints

- Depends on **Sessions 1 & 2** backend endpoints being merged/deployed:
  `GET/POST/PATCH/DELETE /api/v1/subjects`, `GET/POST/DELETE /api/v1/groups/{group}/teacher-assignments`.
- All commands run from `web/`. Before finishing: `npm run lint && npm run typecheck && npm run test && npm run build` all pass.
- User-facing text is **Spanish**; code identifiers/comments are **English**. Labels live in `web/src/types.ts` / component copy.
- Role gating in the UI is UX only — never the security boundary. Directors manage subjects (mirror `SubjectPolicy`).
- Responses from the resource endpoints are wrapped `{ data: ... }` (same convention as `assessmentsApi`/`groupsApi`); the assignment index returns `{ data: [...] }`.
- Commit after each task. Trailer: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`

## File Structure

- Modify: `web/src/types.ts` — add `Subject`, `GroupTeacherAssignment` interfaces.
- Modify: `web/src/lib/permissions.ts` — add `canManageSubjects(user)`.
- Create: `web/src/features/subjects/subjectsApi.ts` — data layer.
- Create: `web/src/features/subjects/SubjectsPage.tsx` — CRUD screen.
- Create: `web/src/features/subjects/SubjectsPage.test.tsx`
- Create: `web/src/features/subjects/GroupTeacherAssignments.tsx` — assignment panel (used inside the group screen).
- Create: `web/src/features/subjects/GroupTeacherAssignments.test.tsx`
- Modify: `web/src/App.tsx` — add `/materias` route.
- Modify: `web/src/components/nav-items.ts` — add "Materias" nav item (director-only).

---

### Task 1: Types + permission helper + API layer

**Files:**
- Modify: `web/src/types.ts`
- Modify: `web/src/lib/permissions.ts`
- Create: `web/src/features/subjects/subjectsApi.ts`

**Interfaces:**
- Produces:
  - `interface Subject { id: number; name: string; short_code: string | null; color: string | null }`
  - `interface GroupTeacherAssignment { teacher_id: number; teacher_name: string; subject_id: number; subject_name: string | null }`
  - `canManageSubjects(user: User | null): boolean` — director-only.
  - `subjectsApi`: `fetchSubjects()`, `createSubject(input)`, `updateSubject(id, input)`, `deleteSubject(id)`, `fetchGroupAssignments(groupId)`, `createGroupAssignment(groupId, {teacher_id, subject_id})`, `deleteGroupAssignment(groupId, {teacher_id, subject_id})`.

- [ ] **Step 1: Add types to `web/src/types.ts`** (place near the assessment types, ~line 160):

```ts
/**
 * A school subject ("materia"). Mirror of `SubjectResource` (backend Sesión 1).
 * School-owned catalog managed by directors.
 */
export interface Subject {
  id: number
  name: string
  short_code: string | null
  color: string | null
}

/**
 * One teacher-subject assignment row of a group (backend Sesión 2). Mirror of
 * `GroupTeacherAssignmentResource`.
 */
export interface GroupTeacherAssignment {
  teacher_id: number
  teacher_name: string
  subject_id: number
  subject_name: string | null
}
```

- [ ] **Step 2: Add the permission helper to `web/src/lib/permissions.ts`** (next to `canManageGroups`):

```ts
/**
 * Directors manage the school's subject catalog (mirror of SubjectPolicy).
 * UX-only gate — the backend enforces the real rule on every request.
 */
export function canManageSubjects(user: User | null): boolean {
  return isDirector(user)
}
```

- [ ] **Step 3: Write the API layer** (`web/src/features/subjects/subjectsApi.ts`):

```ts
import { api } from "@/lib/api"
import type { GroupTeacherAssignment, Subject } from "@/types"

/**
 * Data layer for the subjects ("materias") feature (backend Sesiones 1–2):
 * the school subject catalog CRUD plus per-group teacher-subject assignments.
 * Resource responses are `{ data: ... }` wrapped, matching groupsApi/assessmentsApi.
 */

export interface SubjectInput {
  name: string
  short_code?: string | null
  color?: string | null
}

export async function fetchSubjects(): Promise<Subject[]> {
  const { data } = await api.get<{ data: Subject[] }>("/api/v1/subjects")
  return data.data
}

export async function createSubject(input: SubjectInput): Promise<Subject> {
  const { data } = await api.post<{ data: Subject }>("/api/v1/subjects", input)
  return data.data
}

export async function updateSubject(id: number, input: Partial<SubjectInput>): Promise<Subject> {
  const { data } = await api.patch<{ data: Subject }>(`/api/v1/subjects/${id}`, input)
  return data.data
}

export async function deleteSubject(id: number): Promise<void> {
  await api.delete(`/api/v1/subjects/${id}`)
}

export async function fetchGroupAssignments(groupId: number): Promise<GroupTeacherAssignment[]> {
  const { data } = await api.get<{ data: GroupTeacherAssignment[] }>(
    `/api/v1/groups/${groupId}/teacher-assignments`,
  )
  return data.data
}

export async function createGroupAssignment(
  groupId: number,
  input: { teacher_id: number; subject_id: number },
): Promise<void> {
  await api.post(`/api/v1/groups/${groupId}/teacher-assignments`, input)
}

export async function deleteGroupAssignment(
  groupId: number,
  input: { teacher_id: number; subject_id: number },
): Promise<void> {
  // axios sends a DELETE body via the `data` option.
  await api.delete(`/api/v1/groups/${groupId}/teacher-assignments`, { data: input })
}
```

- [ ] **Step 4: Typecheck**

Run (from `web/`): `npm run typecheck`
Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add web/src/types.ts web/src/lib/permissions.ts web/src/features/subjects/subjectsApi.ts
git commit -m "feat(web): add Subject types, permission and API layer"
```

---

### Task 2: Subjects CRUD page

**Files:**
- Create: `web/src/features/subjects/SubjectsPage.tsx`
- Create: `web/src/features/subjects/SubjectsPage.test.tsx`
- Modify: `web/src/App.tsx`
- Modify: `web/src/components/nav-items.ts`

**Interfaces:**
- Consumes: `subjectsApi`, `canManageSubjects`, `useAuth`.
- Produces: route `/materias` rendering `SubjectsPage`; nav item "Materias" (director-only).

- [ ] **Step 1: Write the failing test** (`web/src/features/subjects/SubjectsPage.test.tsx`)

```tsx
import { render, screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { describe, expect, it, vi, beforeEach } from "vitest"
import { SubjectsPage } from "./SubjectsPage"
import * as subjectsApi from "./subjectsApi"

vi.mock("./subjectsApi")
vi.mock("@/features/auth/AuthContext", () => ({
  useAuth: () => ({ user: { id: 1, name: "Dir", email: "d@x.com", role: "director" } }),
}))

describe("SubjectsPage", () => {
  beforeEach(() => {
    vi.mocked(subjectsApi.fetchSubjects).mockResolvedValue([
      { id: 1, name: "Matemática", short_code: "MAT", color: null },
    ])
    vi.mocked(subjectsApi.createSubject).mockResolvedValue({
      id: 2, name: "Inglés", short_code: null, color: null,
    })
  })

  it("lists existing subjects", async () => {
    render(<SubjectsPage />)
    expect(await screen.findByText("Matemática")).toBeInTheDocument()
  })

  it("creates a subject", async () => {
    render(<SubjectsPage />)
    await screen.findByText("Matemática")

    await userEvent.type(screen.getByLabelText("Nombre"), "Inglés")
    await userEvent.click(screen.getByRole("button", { name: /crear materia/i }))

    await waitFor(() =>
      expect(subjectsApi.createSubject).toHaveBeenCalledWith(
        expect.objectContaining({ name: "Inglés" }),
      ),
    )
  })
})
```

- [ ] **Step 2: Run to verify failure**

Run: `npm run test -- SubjectsPage`
Expected: FAIL (module `./SubjectsPage` not found).

- [ ] **Step 3: Write `SubjectsPage.tsx`**

```tsx
import { useEffect, useState } from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { BookOpen } from "lucide-react"
import { useAuth } from "@/features/auth/AuthContext"
import { canManageSubjects } from "@/lib/permissions"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { ConfirmDialog } from "@/components/ui/confirm-dialog"
import { EmptyState } from "@/components/ui/empty-state"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { PageHeader } from "@/components/ui/page-header"
import type { Subject } from "@/types"
import * as subjectsApi from "./subjectsApi"

const subjectSchema = z.object({
  name: z.string().min(1, "Ingresá el nombre"),
  short_code: z.string().optional(),
})

type SubjectValues = z.infer<typeof subjectSchema>

/**
 * Subjects ("materias") admin screen, route `/materias`. Director-only CRUD of
 * the school's subject catalog (backend Sesión 1). Role gating here is UX only.
 */
export function SubjectsPage() {
  const { user } = useAuth()
  const canManage = canManageSubjects(user)

  const [subjects, setSubjects] = useState<Subject[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<SubjectValues>({
    resolver: zodResolver(subjectSchema),
    defaultValues: { name: "", short_code: "" },
  })

  function load() {
    return subjectsApi
      .fetchSubjects()
      .then(setSubjects)
      .catch(() => setError("No pudimos cargar las materias."))
  }

  useEffect(() => {
    load()
  }, [])

  async function onCreate(values: SubjectValues) {
    setFormError(null)
    try {
      await subjectsApi.createSubject({
        name: values.name.trim(),
        short_code: values.short_code?.trim() ? values.short_code.trim() : null,
      })
      reset({ name: "", short_code: "" })
      await load()
    } catch {
      setFormError("No pudimos crear la materia. ¿Ya existe una con ese nombre?")
    }
  }

  async function onDelete(id: number) {
    setError(null)
    try {
      await subjectsApi.deleteSubject(id)
      await load()
    } catch {
      setError("No pudimos eliminar la materia.")
    }
  }

  if (error) return <p className="text-sm text-destructive">{error}</p>
  if (!subjects) return <p className="text-muted-foreground">Cargando…</p>

  return (
    <div className="grid gap-6">
      <PageHeader title="Materias" />

      {canManage && (
        <Card>
          <CardHeader>
            <CardTitle>Nueva materia</CardTitle>
          </CardHeader>
          <CardContent>
            <form onSubmit={handleSubmit(onCreate)} className="grid gap-4 sm:grid-cols-2" noValidate>
              <div className="grid gap-2">
                <Label htmlFor="name">Nombre</Label>
                <Input id="name" {...register("name")} />
                {errors.name && <p className="text-sm text-destructive">{errors.name.message}</p>}
              </div>
              <div className="grid gap-2">
                <Label htmlFor="short_code">Código (opcional)</Label>
                <Input id="short_code" {...register("short_code")} />
              </div>
              {formError && (
                <p role="alert" className="text-sm text-destructive sm:col-span-2">
                  {formError}
                </p>
              )}
              <div className="sm:col-span-2">
                <Button type="submit" disabled={isSubmitting}>
                  {isSubmitting ? "Creando…" : "Crear materia"}
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>
      )}

      {subjects.length === 0 ? (
        <EmptyState icon={BookOpen} message="Todavía no hay materias." />
      ) : (
        <section className="grid gap-3">
          {subjects.map((subject) => (
            <Card key={subject.id}>
              <CardContent className="flex items-center justify-between py-4">
                <div>
                  <p className="font-medium">{subject.name}</p>
                  {subject.short_code && (
                    <p className="text-sm text-muted-foreground">{subject.short_code}</p>
                  )}
                </div>
                {canManage && (
                  <ConfirmDialog
                    trigger={
                      <Button type="button" variant="destructive" size="sm">
                        Eliminar
                      </Button>
                    }
                    title="Eliminar materia"
                    description="Esta acción no se puede deshacer."
                    confirmLabel="Eliminar"
                    onConfirm={() => onDelete(subject.id)}
                  />
                )}
              </CardContent>
            </Card>
          ))}
        </section>
      )}
    </div>
  )
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm run test -- SubjectsPage`
Expected: PASS.

- [ ] **Step 5: Add the route in `web/src/App.tsx`** — import and add:

```tsx
import { SubjectsPage } from "@/features/subjects/SubjectsPage"
```

```tsx
          <Route
            path="/materias"
            element={
              <ProtectedLayout>
                <SubjectsPage />
              </ProtectedLayout>
            }
          />
```

- [ ] **Step 6: Add the nav item in `web/src/components/nav-items.ts`** — import `canManageSubjects` and `BookOpen`, and add to the "Administración" section items:

```ts
        ...(canManageSubjects(user)
          ? [{ to: "/materias", label: "Materias", icon: BookOpen }]
          : []),
```

Add `BookOpen` to the `lucide-react` import and `canManageSubjects` to the permissions import.

- [ ] **Step 7: Commit**

```bash
git add web/src/features/subjects/SubjectsPage.tsx web/src/features/subjects/SubjectsPage.test.tsx web/src/App.tsx web/src/components/nav-items.ts
git commit -m "feat(web): add Subjects admin page, route and nav item"
```

---

### Task 3: Group teacher-subject assignment panel

**Files:**
- Create: `web/src/features/subjects/GroupTeacherAssignments.tsx`
- Create: `web/src/features/subjects/GroupTeacherAssignments.test.tsx`

**Interfaces:**
- Consumes: `subjectsApi` (assignments + `fetchSubjects`), `trackingApi`/teachers source, `SingleSelect`.
- Produces: `<GroupTeacherAssignments groupId={number} canManage={boolean} />` — lists assignments, lets a director add (teacher + subject) and remove a pair.

> **Teacher options source:** the SPA already exposes `GET /teachers` via `TeacherOptionsController` (see `web/src/features/...` — search for an existing `fetchTeachers`/teacher-options call). Reuse it. If no frontend helper exists yet, add `fetchTeachers()` to `subjectsApi.ts` hitting `GET /teachers` returning `{ data: {id, name}[] }`. Read the backend `TeacherOptionsController` response shape first and mirror it.

- [ ] **Step 1: Write the failing test** (`GroupTeacherAssignments.test.tsx`)

```tsx
import { render, screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { describe, expect, it, vi, beforeEach } from "vitest"
import { GroupTeacherAssignments } from "./GroupTeacherAssignments"
import * as subjectsApi from "./subjectsApi"

vi.mock("./subjectsApi")

describe("GroupTeacherAssignments", () => {
  beforeEach(() => {
    vi.mocked(subjectsApi.fetchGroupAssignments).mockResolvedValue([
      { teacher_id: 5, teacher_name: "Ana", subject_id: 1, subject_name: "Matemática" },
    ])
    vi.mocked(subjectsApi.fetchSubjects).mockResolvedValue([
      { id: 1, name: "Matemática", short_code: null, color: null },
    ])
    vi.mocked(subjectsApi.fetchTeachers).mockResolvedValue([{ id: 5, name: "Ana" }])
    vi.mocked(subjectsApi.createGroupAssignment).mockResolvedValue()
  })

  it("lists current assignments", async () => {
    render(<GroupTeacherAssignments groupId={10} canManage />)
    expect(await screen.findByText(/Ana/)).toBeInTheDocument()
    expect(screen.getByText(/Matemática/)).toBeInTheDocument()
  })
})
```

> Add `fetchTeachers` to `subjectsApi.ts` if it does not already exist (see the source note above). If a shared teachers API already exists elsewhere, mock that module instead and adjust imports.

- [ ] **Step 2: Run to verify failure**

Run: `npm run test -- GroupTeacherAssignments`
Expected: FAIL (component missing).

- [ ] **Step 3: Write `GroupTeacherAssignments.tsx`**

```tsx
import { useEffect, useState } from "react"
import { SectionCard } from "@/components/ui/section-card"
import { SingleSelect } from "@/components/ui/single-select"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import type { GroupTeacherAssignment, Subject } from "@/types"
import * as subjectsApi from "./subjectsApi"

interface TeacherOption {
  id: number
  name: string
}

interface Props {
  groupId: number
  canManage: boolean
}

/**
 * Director-managed teacher-subject assignments for a group (backend Sesión 2).
 * Each row is "teacher teaches subject in this group". Adding is idempotent on
 * the backend (unique on group+teacher+subject).
 */
export function GroupTeacherAssignments({ groupId, canManage }: Props) {
  const [assignments, setAssignments] = useState<GroupTeacherAssignment[] | null>(null)
  const [subjects, setSubjects] = useState<Subject[]>([])
  const [teachers, setTeachers] = useState<TeacherOption[]>([])
  const [teacherId, setTeacherId] = useState<string>("")
  const [subjectId, setSubjectId] = useState<string>("")
  const [error, setError] = useState<string | null>(null)

  function loadAssignments() {
    return subjectsApi
      .fetchGroupAssignments(groupId)
      .then(setAssignments)
      .catch(() => setError("No pudimos cargar las asignaciones."))
  }

  useEffect(() => {
    loadAssignments()
    subjectsApi.fetchSubjects().then(setSubjects).catch(() => undefined)
    subjectsApi.fetchTeachers().then(setTeachers).catch(() => undefined)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [groupId])

  async function onAdd() {
    if (!teacherId || !subjectId) return
    setError(null)
    try {
      await subjectsApi.createGroupAssignment(groupId, {
        teacher_id: Number(teacherId),
        subject_id: Number(subjectId),
      })
      setTeacherId("")
      setSubjectId("")
      await loadAssignments()
    } catch {
      setError("No pudimos crear la asignación.")
    }
  }

  async function onRemove(a: GroupTeacherAssignment) {
    setError(null)
    try {
      await subjectsApi.deleteGroupAssignment(groupId, {
        teacher_id: a.teacher_id,
        subject_id: a.subject_id,
      })
      await loadAssignments()
    } catch {
      setError("No pudimos eliminar la asignación.")
    }
  }

  return (
    <SectionCard title="Docentes y materias">
      {error && <p className="text-sm text-destructive">{error}</p>}

      {canManage && (
        <div className="mb-4 flex flex-wrap items-end gap-3">
          <div className="grid gap-1.5">
            <Label htmlFor="assign-teacher">Docente</Label>
            <SingleSelect
              id="assign-teacher"
              options={teachers.map((t) => ({ value: String(t.id), label: t.name }))}
              value={teacherId}
              onChange={setTeacherId}
            />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="assign-subject">Materia</Label>
            <SingleSelect
              id="assign-subject"
              options={subjects.map((s) => ({ value: String(s.id), label: s.name }))}
              value={subjectId}
              onChange={setSubjectId}
            />
          </div>
          <Button type="button" onClick={onAdd} disabled={!teacherId || !subjectId}>
            Asignar
          </Button>
        </div>
      )}

      {assignments === null ? (
        <p className="text-muted-foreground">Cargando…</p>
      ) : assignments.length === 0 ? (
        <p className="text-muted-foreground">Sin asignaciones todavía.</p>
      ) : (
        <ul className="grid gap-2">
          {assignments.map((a) => (
            <li
              key={`${a.teacher_id}-${a.subject_id}`}
              className="flex items-center justify-between rounded-md border px-3 py-2 text-sm"
            >
              <span>
                {a.teacher_name} — {a.subject_name ?? "—"}
              </span>
              {canManage && (
                <Button type="button" variant="ghost" size="sm" onClick={() => onRemove(a)}>
                  Quitar
                </Button>
              )}
            </li>
          ))}
        </ul>
      )}
    </SectionCard>
  )
}
```

> `SingleSelect`'s exact prop names (`options`/`value`/`onChange`) are taken from `AssessmentsPage.tsx`. Confirm against `web/src/components/ui/single-select.tsx` and adjust if the component expects e.g. `onValueChange`.

- [ ] **Step 4: Run to verify pass**

Run: `npm run test -- GroupTeacherAssignments`
Expected: PASS.

- [ ] **Step 5: Mount the panel where a group is edited.** In `web/src/features/groups/GroupFormPage.tsx` (the director's group screen), render `<GroupTeacherAssignments groupId={groupId} canManage={canManageGroups(user)} />` below the group form, but only when editing an existing group (a real `groupId`, not the "nueva" route). Read that page first to place it correctly and use its existing `groupId`/`user`.

- [ ] **Step 6: Lint + typecheck + test + build**

Run: `npm run lint && npm run typecheck && npm run test && npm run build`
Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add web/src/features/subjects/GroupTeacherAssignments.tsx web/src/features/subjects/GroupTeacherAssignments.test.tsx web/src/features/subjects/subjectsApi.ts web/src/features/groups/GroupFormPage.tsx
git commit -m "feat(web): add group teacher-subject assignment panel"
```

---

## Self-Review Checklist

- [ ] `/materias` route renders `SubjectsPage`; the "Materias" nav item shows only for directors.
- [ ] Director can create, list and delete subjects; a duplicate name surfaces the friendly error.
- [ ] The assignment panel lists rows, adds a (teacher, subject) pair, and removes a single pair.
- [ ] `SingleSelect` prop names verified against the real component.
- [ ] `fetchTeachers` source verified against the backend `/teachers` response shape.
- [ ] `npm run lint && npm run typecheck && npm run test && npm run build` all pass.
