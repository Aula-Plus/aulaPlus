# Subjects — Session 6: Frontend assessment subject picker + per-subject tracking UI

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a required subject picker to the assessment form (limited to subjects the teacher is assigned in the group) and surface per-subject statistics on the student tracking profile — per-subject stat cards, an overall-average card, and a subject selector that filters the performance chart.

**Architecture:** Extend `assessmentsApi`/`AssessmentInput` and the assessment form with `subject_id`. Extend `StudentTracking` type with `overall_average` + `by_subject` and render them on `StudentTrackingPage`. Add a subject selector to `StudentPerformanceChart` that passes `subject_id` to `fetchStudentPerformanceTimeline` (extended to accept it).

**Tech Stack:** React 19 + Vite + TS + Tailwind v4 + shadcn/ui, axios, react-hook-form + Zod, Vitest + Testing Library, recharts. Shared `ui/` components (StatCard, SectionCard, SingleSelect).

## Global Constraints

- Depends on **Sessions 3 & 4** backend being merged/deployed: `assessments` require `subject_id`; tracking returns `overall_average` + `by_subject`; the timeline accepts `?subject_id=`. Also depends on **Session 5** frontend (`Subject` type, `subjectsApi.fetchSubjects`).
- All commands from `web/`. Before finishing: `npm run lint && npm run typecheck && npm run test && npm run build` pass.
- User-facing text Spanish; code English.
- The chart must never break with no data: keep the existing "Sin evaluaciones en este rango." empty state; a subject filter that yields no results shows it too.
- Commit after each task. Trailer: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`

## File Structure

- Modify: `web/src/features/assessments/assessmentsApi.ts` — add `subject_id` to `AssessmentInput`.
- Modify: `web/src/features/assessments/AssessmentsPage.tsx` — subject `<SingleSelect>` (required), options from the group's assignable subjects; also show subject on each listed assessment.
- Modify: `web/src/types.ts` — add `subject_id`/`subject_name` to `Assessment`; add `overall_average` + `by_subject` to `StudentTracking`; extend `AssessmentSummary` with `subject_id`.
- Modify: `web/src/features/tracking/trackingApi.ts` — (only if the `StudentTracking` mapping is manual) surface the new fields.
- Modify: `web/src/features/tracking/StudentTrackingPage.tsx` — overall-average card + per-subject section.
- Modify: `web/src/features/tracking/performanceApi.ts` — add `subjectId` to `fetchStudentPerformanceTimeline`.
- Modify: `web/src/features/tracking/StudentPerformanceChart.tsx` — subject selector driving the fetch.
- Modify tests: `AssessmentsPage.test.tsx`, `StudentTrackingPage.test.tsx`, `StudentPerformanceChart.test.tsx`.

---

### Task 1: Assessment form gains a required subject picker

**Files:**
- Modify: `web/src/features/assessments/assessmentsApi.ts`
- Modify: `web/src/types.ts`
- Modify: `web/src/features/assessments/AssessmentsPage.tsx`
- Modify: `web/src/features/assessments/AssessmentsPage.test.tsx`

**Interfaces:**
- Consumes: `subjectsApi.fetchSubjects` (Session 5), `SingleSelect`.
- Produces: `AssessmentInput.subject_id: number`; `Assessment.subject_id`/`subject_name`; the create form sends `subject_id`.

- [ ] **Step 1: Extend the `Assessment` type in `web/src/types.ts`** — add fields to the interface (after `group_id`):

```ts
  group_id: number
  subject_id: number
  subject_name: string | null
```

And to `AssessmentSummary` (used by the tracking view) add:

```ts
  subject_id: number
```

- [ ] **Step 2: Extend `AssessmentInput`** in `assessmentsApi.ts`:

```ts
export interface AssessmentInput {
  type: AssessmentType
  subject_id: number
  administered_at: string
  purpose?: string | null
  duration_minutes?: number | null
}
```

- [ ] **Step 3: Write the failing test** — extend `AssessmentsPage.test.tsx` (read the file first; it mocks `assessmentsApi` and `trackingApi`). Add a `subjectsApi` mock returning one subject and assert the created payload carries `subject_id`. Sketch:

```tsx
vi.mock("@/features/subjects/subjectsApi")
// in beforeEach:
vi.mocked(subjectsApi.fetchSubjects).mockResolvedValue([
  { id: 3, name: "Matemática", short_code: null, color: null },
])

it("sends subject_id when creating an assessment", async () => {
  // ...render, wait for form, choose the subject in the "Materia" select,
  // fill date, submit...
  await waitFor(() =>
    expect(assessmentsApi.createAssessment).toHaveBeenCalledWith(
      expect.any(Number),
      expect.objectContaining({ subject_id: 3 }),
    ),
  )
})
```

> Match the existing test's rendering/setup. Selecting a value in `SingleSelect` in tests follows the pattern in `single-select.test.tsx` / other feature tests — read one to copy the interaction.

- [ ] **Step 4: Run to verify failure**

Run: `npm run test -- AssessmentsPage`
Expected: FAIL (no subject field; payload lacks `subject_id`).

- [ ] **Step 5: Add the subject field to `AssessmentsPage.tsx`.**
  - Import `import * as subjectsApi from "@/features/subjects/subjectsApi"` and `import type { Subject } from "@/types"`.
  - Add `subject_id` to the zod schema: `subject_id: z.coerce.number().int().positive({ message: "Elegí una materia" })`.
  - Add `subjects` state; load in the existing `useEffect`: `subjectsApi.fetchSubjects().then(setSubjects).catch(() => undefined)`.

> **Which subjects to offer:** ideally only subjects the teacher teaches in this group. The backend validates that regardless (Session 3), so listing the full school catalog is safe but may show non-assignable options. Preferred: fetch the group's assignments (`subjectsApi.fetchGroupAssignments(groupId)`) and offer the distinct subjects assigned to the current teacher. Simpler acceptable fallback: offer `fetchSubjects()` and let the backend 422 guide the user. Pick the assignments-based list; fall back only if teacher identity in the group is not readily available on this page.

  - Add a `Controller` field (mirroring the `type` field) rendering a `SingleSelect` with `id="subject_id"`, label "Materia", options from `subjects.map((s) => ({ value: String(s.id), label: s.name }))`, `value={String(field.value ?? "")}`, `onChange={(v) => field.onChange(Number(v))}`.
  - Include `subject_id` in the `createAssessment` payload and in `reset(...)` defaults (reset to `undefined`/unselected).
  - On each listed assessment card, show the subject: `{assessment.subject_name}` next to the type.

- [ ] **Step 6: Run to verify pass**

Run: `npm run test -- AssessmentsPage`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add web/src/features/assessments/assessmentsApi.ts web/src/types.ts web/src/features/assessments/AssessmentsPage.tsx web/src/features/assessments/AssessmentsPage.test.tsx
git commit -m "feat(web): add required subject picker to the assessment form"
```

---

### Task 2: Per-subject stats on the student tracking profile

**Files:**
- Modify: `web/src/types.ts`
- Modify: `web/src/features/tracking/trackingApi.ts` (only if it maps the response manually)
- Modify: `web/src/features/tracking/StudentTrackingPage.tsx`
- Modify: `web/src/features/tracking/StudentTrackingPage.test.tsx`

**Interfaces:**
- Consumes: extended tracking response (`overall_average`, `by_subject`).
- Produces: `StudentTracking.overall_average: number | null`; `StudentTracking.by_subject: SubjectStat[]` where `SubjectStat = { subject_id: number; subject_name: string; average: number; assessment_count: number }`. UI: an overall-average StatCard + a "Desempeño por materia" section.

- [ ] **Step 1: Extend the `StudentTracking` type in `web/src/types.ts`.**

```ts
export interface SubjectStat {
  subject_id: number
  subject_name: string
  average: number
  assessment_count: number
}
```

Add to `interface StudentTracking`:

```ts
  overall_average: number | null
  by_subject: SubjectStat[]
```

- [ ] **Step 2: Confirm `trackingApi.fetchStudentTracking` passes the fields through.** If it returns `data.data` directly (typed as `StudentTracking`), no change is needed beyond the type. If it maps fields explicitly, add `overall_average` and `by_subject`. Read `trackingApi.ts` first.

- [ ] **Step 3: Write the failing test** — extend `StudentTrackingPage.test.tsx` (read it; it mocks `trackingApi`). Add `overall_average` and `by_subject` to the mocked tracking object and assert they render:

```tsx
// in the mocked tracking payload:
overall_average: 8,
by_subject: [
  { subject_id: 1, subject_name: "Matemática", average: 7, assessment_count: 2 },
],

it("shows per-subject performance", async () => {
  // render...
  expect(await screen.findByText("Matemática")).toBeInTheDocument()
  expect(screen.getByText(/Promedio general/i)).toBeInTheDocument()
})
```

> Add the two new keys to EVERY existing tracking mock in this test file, or TypeScript/`build` will fail on the now-required fields. If keeping them optional is preferred, type them as `overall_average?` / `by_subject?` and guard in the component — but the backend always sends them, so required + updating the mocks is cleaner.

- [ ] **Step 4: Run to verify failure**

Run: `npm run test -- StudentTrackingPage`
Expected: FAIL (fields not rendered / type errors in mocks until updated).

- [ ] **Step 5: Render the new data in `StudentTrackingPage.tsx`.** Read the file to match its layout/StatCard usage, then:
  - Add an overall-average `StatCard` (label "Promedio general", `value={tracking.overall_average ?? 0}`, tone `info`). Note `StatCard.value` is a `number`; if `overall_average` is null, render "—" via a small conditional or pass `0` with `emphasizeWhenZero={false}` — prefer a dedicated line showing "—" when null so a real 0 average is distinguishable from "no data". Simplest: `value={tracking.overall_average ?? 0}` and only render the card `emphasizeWhenZero` when not null.
  - Add a `SectionCard title="Desempeño por materia"`: if `by_subject` is empty, show `<p className="text-muted-foreground">Sin evaluaciones por materia.</p>`; otherwise a list of rows, each showing `subject_name`, its `average`, and `assessment_count` (e.g. `"{average} · {assessment_count} evaluaciones"`). Reuse `StatCard` per subject if the layout suits, or a simple bordered list like `GroupTeacherAssignments`.

- [ ] **Step 6: Run to verify pass**

Run: `npm run test -- StudentTrackingPage`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add web/src/types.ts web/src/features/tracking/trackingApi.ts web/src/features/tracking/StudentTrackingPage.tsx web/src/features/tracking/StudentTrackingPage.test.tsx
git commit -m "feat(web): show overall and per-subject stats on student tracking"
```

---

### Task 3: Subject selector on the performance chart

**Files:**
- Modify: `web/src/features/tracking/performanceApi.ts`
- Modify: `web/src/features/tracking/StudentPerformanceChart.tsx`
- Modify: `web/src/features/tracking/StudentPerformanceChart.test.tsx`

**Interfaces:**
- Consumes: extended timeline endpoint (`?subject_id=`), `subjectsApi.fetchSubjects` (or `by_subject` from the tracking prop — see note).
- Produces: `fetchStudentPerformanceTimeline(studentId, { from, to, subjectId })`; chart shows a subject `<SingleSelect>` with an "Todas las materias" default.

- [ ] **Step 1: Extend `fetchStudentPerformanceTimeline`** in `performanceApi.ts` — add an optional `subjectId`:

```ts
export async function fetchStudentPerformanceTimeline(
  studentId: number,
  range?: { from?: string; to?: string; subjectId?: number },
): Promise<StudentPerformanceTimeline> {
  const params: Record<string, string> = {}
  if (range?.from) params.from = range.from
  if (range?.to) params.to = range.to
  if (range?.subjectId) params.subject_id = String(range.subjectId)

  const { data } = await api.get<StudentPerformanceTimeline>(
    `/api/v1/students/${studentId}/performance-timeline`,
    Object.keys(params).length > 0 ? { params } : undefined,
  )
  return data
}
```

- [ ] **Step 2: Write the failing test** — extend `StudentPerformanceChart.test.tsx` (read it; it mocks `performanceApi`). Add a case asserting that choosing a subject re-fetches with `subjectId`:

```tsx
it("refetches with subjectId when a subject is selected", async () => {
  // render with a subjects list available (mock subjectsApi.fetchSubjects or
  // pass subjects via prop — see Step 3), pick "Matemática", then:
  await waitFor(() =>
    expect(performanceApi.fetchStudentPerformanceTimeline).toHaveBeenCalledWith(
      expect.any(Number),
      expect.objectContaining({ subjectId: 1 }),
    ),
  )
})
```

- [ ] **Step 3: Add the selector to `StudentPerformanceChart.tsx`.**

> **Subject options source:** the chart currently takes only `studentId`. To avoid an extra fetch, prefer passing the subject list in as a prop from the parent (`StudentTrackingPage` already has `by_subject`): add an optional prop `subjects?: { id: number; name: string }[]` and have the parent pass `tracking.by_subject.map(s => ({ id: s.subject_id, name: s.subject_name }))`. Fallback: fetch `subjectsApi.fetchSubjects()` inside the chart. Prefer the prop — it lists exactly the subjects the student has data in.

  - Add `subjectId` state (`""` = all). Include it in the `useEffect` dependency array and pass `{ from, to, subjectId: subjectId ? Number(subjectId) : undefined }` to the fetch.
  - In the `SectionCard` `action`, add a `SingleSelect` (label "Materia") whose options are `[{ value: "", label: "Todas las materias" }, ...subjects.map(...)]`, `value={subjectId}`, `onChange={setSubjectId}`.
  - Keep the existing empty-state behavior: when the filtered `results` is empty, "Sin evaluaciones en este rango." still renders.

  Update the props interface and the parent call site (`StudentTrackingPage.tsx`) accordingly.

- [ ] **Step 4: Run to verify pass**

Run: `npm run test -- StudentPerformanceChart`
Expected: PASS.

- [ ] **Step 5: Lint + typecheck + full test + build**

Run: `npm run lint && npm run typecheck && npm run test && npm run build`
Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add web/src/features/tracking/performanceApi.ts web/src/features/tracking/StudentPerformanceChart.tsx web/src/features/tracking/StudentPerformanceChart.test.tsx web/src/features/tracking/StudentTrackingPage.tsx
git commit -m "feat(web): add subject filter to the student performance chart"
```

---

## Self-Review Checklist

- [ ] Assessment form has a required "Materia" select; submitting without one is blocked client-side and the payload carries `subject_id`.
- [ ] Listed assessments show their subject name.
- [ ] Student tracking shows an overall-average card (distinguishing null from 0) and a "Desempeño por materia" section (empty state when none).
- [ ] Performance chart has a "Todas las materias" default selector that refetches with `subject_id`; empty filtered result still shows the empty state.
- [ ] All new required type fields are reflected in every test mock.
- [ ] `npm run lint && npm run typecheck && npm run test && npm run build` all pass.
