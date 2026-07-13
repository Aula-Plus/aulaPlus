# Portal Docentes v2 — Repo guardrails

Monorepo for a multi-tenant school-management platform for **staff** (docentes,
directores, psicopedagogos). **Alumnos are records, not users** — they never log
in. This is a security-conscious rewrite of a previous version whose audit found
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
- Roles (`App\Enums\Role`): `teacher` (own groups), `psychopedagogue` (school-wide),
  `director` (school-wide + admin). Tenant isolation and role rules are **both**
  checked in Policies (defence in depth over the scope). Role identifiers are
  English; user-facing Spanish labels live in the frontend (`web/src/types.ts`).

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

## Language convention

- **All code in English:** identifiers, DB tables/columns, models, methods,
  variables, and comments. No Spanish in code (no `grupos`, `docente`, `nombre`…).
- **All user-facing UI text in Spanish:** the product is a Spanish app. Labels,
  buttons, messages shown to users are Spanish, and live in the frontend
  (map code values → Spanish labels, e.g. `roleLabels` in `web/src/types.ts`).

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
