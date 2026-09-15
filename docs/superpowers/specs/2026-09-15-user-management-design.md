# User management ("Usuarios") — design

**Date:** 2026-09-15
**Status:** Approved (brainstorming) — pending implementation plan
**Branch:** `feature/user-management`

## Problem

Staff users can only be created today via the `php artisan app:create-user`
console command. There is no API surface and no UI for a school director to
onboard or manage teaching/pedagogical staff. This spec adds a **director-only
"Usuarios" screen** and the backend behind it, with an **email-invitation**
onboarding flow.

## Decisions (from brainstorming)

- **Scope:** full-stack (backend API + frontend screen).
- **Who can manage:** `director` only. Enforced server-side by a new
  `UserPolicy` (not just hidden UI). `director` is the admin role.
- **Roles offered in the picker:** the 3 current roles only —
  `teacher` / `director` / `psychopedagogue`. The open `psychologist` / `at`
  question (CLAUDE.md) stays open; do not build 5 roles.
- **Onboarding:** email invitation. The director creates a user with no
  password; the user receives a link and sets their own password.
- **Delete vs. disable:** never delete. Users author comments, teach groups,
  approve accommodations — deletion would orphan real records. "Dar de baja" =
  disable (reversible).
- **Email immutability:** email is fixed at creation. Name and role are
  editable; email is not (avoids identity churn / re-verification complexity).
- **Teacher assignments:** stay on the existing `GroupTeacherAssignments`
  screen (under Materias). This screen links to it; it does not duplicate it.

## Lifecycle

A staff member is always in exactly one state:

| State         | `password`   | `disabled_at` | invitation `accepted_at` | Can log in |
|---------------|--------------|---------------|--------------------------|------------|
| **Pendiente** | `null`       | `null`        | `null`                   | No         |
| **Activo**    | set          | `null`        | set                      | Yes        |
| **Desactivado** | set (or null) | set         | any                      | No         |

Status is **derived** from these columns, not stored as a separate enum, so it
can never drift out of sync. The frontend receives a computed `status`
(`pending` / `active` / `disabled`) on each user resource.

## Backend

### Schema (one migration)

- `users.password` → **nullable** (a pending user has no password yet).
- `users.disabled_at` → nullable `timestamp`.
- New table `user_invitations`:
  - `id`
  - `user_id` → FK to `users`, indexed
  - `token` → **hashed** string (never stored or logged in plaintext)
  - `expires_at` → timestamp
  - `accepted_at` → nullable timestamp
  - `created_at` / `updated_at`

A user has at most one *active* (unexpired, unaccepted) invitation at a time;
resending replaces the token. Chosen over stateless signed URLs because we need
**revoke + single-use + expiry + resend**, which signed URLs cannot provide,
and over reusing `password_reset_tokens` because the semantics differ (an
invitation targets a not-yet-active account).

### `UserPolicy` (director-only, same-school)

`User` deliberately does **not** use `SchoolScope` (would recurse during auth
resolution), so same-school isolation is enforced **explicitly** in both the
policy and the controller query — never assumed.

- `viewAny`, `create`, `update`, `manage` (disable/enable/resend) →
  `director` **and** `target.school_id === actor.school_id`.
- Guardrails inside the disable path (defence in depth, checked server-side):
  - A director cannot disable **their own** account.
  - A director cannot disable the **last active director** in the school
    (no lockout).

### Authenticated endpoints (`/v1`, `auth:sanctum`, `UserPolicy`)

| Method & path                              | Purpose                                             |
|--------------------------------------------|-----------------------------------------------------|
| `GET  /v1/users`                           | List staff in current school (search, role, status) |
| `POST /v1/users`                           | Create pending user + invitation; queue email       |
| `GET  /v1/users/{user}`                    | Show one user                                       |
| `PATCH /v1/users/{user}`                   | Update **name + role** (email immutable)            |
| `POST /v1/users/{user}/resend-invitation`  | Regenerate token + resend (pending only)            |
| `POST /v1/users/{user}/disable`            | Deactivate (guardrails above)                       |
| `POST /v1/users/{user}/enable`             | Reactivate                                          |

The list query is scoped to `where('school_id', currentSchool)` explicitly.

### Public, token-gated endpoints (unauthenticated, throttled)

Deliberately public per security rule #4 — the invited user has no session
yet; the single-use token **is** the authorization gate. Not a `USING(true)`
regression: access is bounded by an unguessable, hashed, expiring, single-use
token.

| Method & path                       | Purpose                                                        |
|-------------------------------------|---------------------------------------------------------------|
| `GET  /invitations/{token}`         | Validate; return `{ school_name, email }`; `410` if expired/used |
| `POST /invitations/{token}/accept`  | Set password → mark `accepted_at`, activate                   |

`accept` validates the password with the **same** rules as the artisan command:
`Password::min(8)->letters()->numbers()`. On success the invitation is consumed
(`accepted_at` set) and can never be reused. Throttle both routes.

### Login changes

The login flow must reject:
- users with `password === null` (pending) →
  message: *"La cuenta aún no fue activada. Revisá tu correo."*
- users with `disabled_at !== null` (disabled) →
  message: *"Esta cuenta está desactivada."*

Both checked server-side in the authentication controller/action. A `null`
password already can't match, but the explicit check produces the correct
message instead of a generic "credenciales inválidas".

### Mail

`InvitationNotification` (queued — `QUEUE_CONNECTION=database`), Spanish body,
link to `FRONTEND_URL/aceptar-invitacion?token=…`. The plaintext token appears
only in the email body — **never logged** (security rules #2). In dev,
`MAIL_MAILER=log` routes it to the log; production SMTP is env/ops config, no
code change.

### FormRequests

- `StoreUserRequest` — `name` required; `email` required/email/unique:users;
  `role` required/in the 3 role values.
- `UpdateUserRequest` — `name`, `role` (no email).
- `AcceptInvitationRequest` — `password` with the strength rules above
  (+ confirmation).

### Validation & security checklist

- All input validated in FormRequests (rule #5).
- No plaintext token/password logged (rule #2).
- Every mutating/admin endpoint authenticated + explicit authz (rule #3).
- No `USING(true)` / allow-all policy (rule #4) — public routes gated by token.
- Tenant isolation enforced explicitly for `User` (no scope).

## Frontend

### Permissions & nav

- `web/src/lib/permissions.ts`: add `canManageUsers(user)` = has role
  `director` (mirror of `UserPolicy`; UX only).
- `web/src/components/nav-items.tsx`: add **Usuarios** (`UserCog` icon) to the
  existing **Administración** section, gated by `canManageUsers`.

### Routes (`web/src/App.tsx`)

- `/usuarios` (protected) → `UsersListPage`, with nested `nueva` / `:id`
  routes → `UserFormPage` (mirrors the groups/students list+nested-form
  pattern).
- `/aceptar-invitacion` (**public**, outside `ProtectedLayout`) →
  `AcceptInvitationPage`.

### Screens

- **`features/users/UsersListPage.tsx`** — table: name, email, role badge,
  status badge (Pendiente / Activo / Desactivado), row actions (editar /
  reenviar invitación / desactivar · reactivar). Search box. "Invitar usuario"
  primary button. Uses the shared `ui/` components (Table, Badge, PageHeader,
  EmptyState) already established as the design standard.
- **`features/users/UserFormPage.tsx`** — create (name, email, role) / edit
  (name, role). On create, confirms *"Invitación enviada a {email}"*.
- **`features/auth/AcceptInvitationPage.tsx`** — public. Reads token from the
  query string, `GET /invitations/{token}` to show school + email, then
  password + confirm, `POST .../accept`, redirect to `/login` with a success
  message. Handles the expired/used (`410`) case with a clear empty/error
  state.

### Types (`web/src/types.ts`)

- Extend the user list item with `status: "pending" | "active" | "disabled"`
  and Spanish labels (`userStatusLabels`), alongside the existing `roleLabels`.

## Out of scope

- `psychologist` / `at` roles (open question stays open).
- `photo_url` upload (column exists; not this MVP).
- Teacher subject/group assignment editing (existing screen; linked, not
  duplicated).
- Self-service profile editing / password change for the logged-in user.
- SSO / external identity.

## Suggested build split (independent agents)

1. **Backend A** — schema migration; `UserPolicy`; users CRUD + disable/enable
   endpoints; `StoreUserRequest`/`UpdateUserRequest`; `UserResource` (with
   derived `status`); Pest tests.
2. **Backend B** — `user_invitations` table + token model; `InvitationNotification`
   (queued); public `GET/POST /invitations/{token}` + `AcceptInvitationRequest`;
   `resend-invitation` endpoint; login blocking (pending/disabled); Pest tests.
3. **Frontend** — `permissions`/`nav`; `UsersListPage`; `UserFormPage`;
   `AcceptInvitationPage`; route wiring; Vitest tests.

Backend A and B share the migration and the `User` model, so the migration and
model changes land first (A), then B builds on them; the frontend can proceed in
parallel against the documented API contract.
