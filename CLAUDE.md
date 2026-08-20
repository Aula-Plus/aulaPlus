# Portal Docentes v2 (Aula+) — Repo guardrails

## Product context

Aula+ (internal repo name: Portal Docentes v2) is a multi-tenant school-management
platform for K-12 private schools in Uruguay (350+ students). Two pillars:

1. **Institutional tracking dashboard** — centralizes pedagogical data per
   student/group (pulled from staff input and, later, school information
   systems) and surfaces trends and early alerts.
2. **AI teaching assistant** — helps teachers plan annual curricula, units,
   classes, and assessments, aligned with Universal Design for Learning (UDL)
   principles. It always proposes; the teacher decides.

Customers: school leadership (purchase decision). Users: teaching and
pedagogical staff — teachers, directors, psychopedagogues, and, pending the
open question below, psychologists and therapeutic companions. **Students are
records, not users — they never log in.**

This is a security-conscious rewrite of a previous version whose audit found
fake shared auth, `USING (true)` RLS, unauthenticated privileged endpoints, and
logged API keys. The rules below exist to not repeat those mistakes — they are
**not** style preferences.

## Layout

```
api/   → Laravel backend (REST/JSON API). The ONLY thing that touches the DB.
web/   → React + Vite + TS SPA. Never talks to the DB directly.
docs/  → ARCHITECTURE.md — living source of truth.
```

Two independently deployable apps. See `docs/ARCHITECTURE.md` for the full brief.

## Stack

- **Backend:** Laravel 13 (PHP 8.3+), PostgreSQL, Sanctum (SPA cookie auth),
  Spatie laravel-permission, Form Requests for validation, Policies for authz,
  Jobs/Queues for async (AI later). Tests: Pest. Local env: Laravel Sail (Docker).
- **Frontend:** React 19 + Vite + TypeScript + Tailwind v4 + shadcn/ui. Data
  layer: axios (`src/lib/api.ts`). Forms: react-hook-form + Zod (UX only). Tests:
  Vitest + Testing Library.

## Architecture (layers)

```
React SPA ──fetch (cookie auth)──▶ Controller → FormRequest (validate)
                                            → Policy (authorize)
                                            → Service/Action (logic) → Job (async)
                                   Eloquent (+ school_id global scope)
                                   PostgreSQL (every business table has school_id)
```

**Non-negotiable:** authorization is decided in server code (Policies), never by
trusting the client. The frontend may hide UI by role for UX — that is never the
security boundary.

## Multi-tenancy

- `schools` is the tenant root. `users.school_id` is mandatory; a user belongs to
  **one** school.
- Every business table has `school_id`, enforced automatically by the
  `App\Models\Concerns\BelongsToSchool` trait (adds `App\Models\Scopes\SchoolScope`
  + auto-fills `school_id` on create). New domain models just `use BelongsToSchool`.
- The "current school" is resolved by `App\Support\Tenancy` (from the authed user;
  console/Jobs/tests set it via `Tenancy::useSchool()`).
- `User` deliberately does **not** use the scope (would recurse during auth
  resolution); user-row isolation is enforced in queries/policies instead.

## Roles

`App\Enums\Role`: `teacher` (own groups), `psychopedagogue` (school-wide),
`director` (school-wide + admin). Tenant isolation and role rules are **both**
checked in Policies (defence in depth over the scope). Role identifiers are
English; user-facing Spanish labels live in the frontend (`web/src/types.ts`).

> **Open question:** the product brief also calls for `psychologist` and `at`
> (therapeutic companion, assigned per student) roles, each with narrower access
> than psychopedagogue. They're not in the current 3-role skeleton. Confirm
> whether that's deliberate scope-cutting for this phase or something to add now
> — building Policies and the tracking module on top of a 3-role assumption is
> expensive to unwind later if it's actually 5.

## Security rules (hard requirements)

1. No hardcoded credentials, API keys, or shared/"demo" accounts in code. Secrets
   go in env vars, never committed. First user: `php artisan app:create-user`.
2. Never log API keys or full tokens, even in dev.
3. No privileged endpoint (AI calls, mutations, admin) without authentication AND
   an explicit authorization check in code.
4. No policy/scope may be "allow all" (`true`) unless a resource is deliberately,
   explicitly public.
5. All user input is validated in the backend (Form Requests), regardless of
   frontend validation.
6. AI-generated HTML rendered in the frontend must be sanitized with a real
   library (`src/lib/sanitize.ts` → DOMPurify), never a homemade regex.
7. File storage buckets/disks are private by default; access only via temporary
   signed URLs.
8. CORS is restricted to real app domains (`config/cors.php` ← `FRONTEND_URL`),
   never `*` in production.
9. Real environment separation (local/staging/prod) via per-env vars — never
   hardcode hosts or deploy URLs.
10. No debug endpoints that leak internals, not even "temporarily".
11. Records tied to a student's learning/clinical profile (accommodations,
    learning barriers, technical/psychopedagogical reports) hold sensitive data
    about minors: enforce field-level access by role — not just per-endpoint —
    never log their content in full, and keep PII out of any third-party API
    call (the AI assistant included: send aggregated/anonymized context, never
    student names).

## Language convention

- **All code in English:** identifiers, DB tables/columns, models, methods,
  variables, and comments. No Spanish in code (no `grupos`, `docente`, `nombre`…).
- **All user-facing UI text in Spanish:** the product is a Spanish app. Labels,
  buttons, messages shown to users are Spanish, and live in the frontend
  (map code values → Spanish labels, e.g. `roleLabels` in `web/src/types.ts`).
- Domain terms without an obvious 1:1 English translation (e.g. *Contemplación*,
  *Barrera*, *Ítem Curricular*, *Programa Anual*) should be decided **once** and
  recorded as a glossary in `docs/ARCHITECTURE.md`, not re-decided per PR.

## Out of scope for now

Not implemented yet, and not to be built even after the auth/tenancy/roles
skeleton is done, until explicitly asked:

- SIGED integration (external school-management system) — will be a decoupled
  connector, not a hardcoded integration, when it's built.
- Billing / plans / per-module entitlements.
- Report-card ("Boletín") and progress-indicator entities — undecided at the
  product level.
- Project/assignment entity and its relation to students — undecided
  (multi-submission, multi-grade design not settled).
- AI evaluations, lesson/curriculum planning, and communications features in
  general — the current scope is the auth/tenancy/roles/CI skeleton.

## Working in this repo

- Backend commands run through Sail: `./vendor/bin/sail artisan …`,
  `./vendor/bin/sail test`, `./vendor/bin/sail composer …` (from `api/`).
- Before committing backend changes: `sail bin pint` (format) + `sail test`.
- Before committing frontend changes (from `web/`): `npm run lint`,
  `npm run typecheck`, `npm run test`, `npm run build`.
- CI (`.github/workflows/ci.yml`) runs all of the above on every PR to `main`.
- Do **not** implement business features (AI evaluaciones, planificaciones,
  comunicaciones) until asked — the current scope is the auth/tenancy/roles/CI
  skeleton.
