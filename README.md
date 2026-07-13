# Portal Docentes v2

Multi-tenant school-management platform for school **staff** (docentes,
directores, psicopedagogos). Alumnos are managed records, not platform users.

This is a monorepo with two independently deployable apps:

| Path   | App | Stack |
|--------|-----|-------|
| `api/` | Backend REST/JSON API | Laravel 13 · PHP 8.3+ · PostgreSQL · Sanctum · Spatie Permission · Pest |
| `web/` | Frontend SPA | React 19 · Vite · TypeScript · Tailwind v4 · shadcn/ui · Vitest |

> Architecture and security rules live in [`CLAUDE.md`](./CLAUDE.md) and
> [`docs/ARCHITECTURE.md`](./docs/ARCHITECTURE.md). Read them before adding features.

## Prerequisites

- **Docker Desktop** (the backend runs entirely in Docker via Laravel Sail)
- **Node.js 20.19+ or 22.12+** and npm (for the frontend)

No local PHP/Composer/PostgreSQL install is required.

## 1. Backend (`api/`)

The backend runs in Docker with Laravel Sail (PHP + PostgreSQL + Redis).

```bash
cd api

# One-time: create your local env file
cp .env.example .env

# Install PHP dependencies using the Sail helper image (no local PHP needed)
docker run --rm -v "$(pwd)":/app -w /app laravelsail/php83-composer:latest \
  composer install --ignore-platform-reqs

# Start the stack (PHP on :80, Postgres on :5432, Redis on :6379)
./vendor/bin/sail up -d

# Generate the app key, run migrations, seed the role catalogue
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
```

Create your first real user (no shared/demo accounts — interactive prompt asks
for school, name, email, role, password):

```bash
./vendor/bin/sail artisan app:create-user
```

The API is now at **http://localhost**. Health check: http://localhost/up.

### Useful backend commands (from `api/`)

```bash
./vendor/bin/sail test          # run the Pest suite (in-memory SQLite)
./vendor/bin/sail bin pint      # format code (run before committing)
./vendor/bin/sail artisan …     # any artisan command
./vendor/bin/sail down          # stop the stack
```

## 2. Frontend (`web/`)

```bash
cd web

# One-time: create your local env file (points at the API)
cp .env.example .env

npm install
npm run dev
```

The SPA runs at **http://localhost:5173** and talks to the API at
`VITE_API_URL` (default `http://localhost`). Log in with the user you created
above.

### Useful frontend commands (from `web/`)

```bash
npm run dev         # dev server (HMR)
npm run lint        # oxlint
npm run typecheck   # tsc --noEmit
npm run test        # Vitest
npm run build       # production build
```

## How auth works (local)

The SPA uses Sanctum **cookie** auth (not tokens):

1. The frontend calls `GET /sanctum/csrf-cookie` to obtain the `XSRF-TOKEN` cookie.
2. `POST /api/login` establishes a session; subsequent requests send the session
   cookie + `X-XSRF-TOKEN` header automatically (axios is configured for this in
   `web/src/lib/api.ts`).

For cookies to be shared, the SPA (`localhost:5173`) and API (`localhost`) must
both be on `localhost`, and the API's `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`
and `FRONTEND_URL` (which drives CORS) must include the SPA origin. The provided
`.env.example` files are already configured for this.

## CI

`.github/workflows/ci.yml` runs on every PR to `main`:

- **API:** `pint --test` + Pest
- **Web:** oxlint + typecheck + Vitest + build

## Deployment (later)

- Frontend: Vercel/Netlify (static Vite build).
- Backend: Railway/Render (Docker) or Laravel Cloud.
- Database: managed PostgreSQL (Railway/Render/Neon) — configured via env vars,
  never hardcoded.
