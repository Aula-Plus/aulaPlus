# Portal Docentes v2 — Arquitectura

> Documento vivo. Refleja el estado actual del scaffolding (auth + tenancy +
> roles + CI) y las decisiones tomadas. Las reglas de seguridad son requisitos,
> no preferencias — ver también [`../CLAUDE.md`](../CLAUDE.md).

## Contexto

Rewrite completo de una app de gestión escolar para **docentes, directores y
psicopedagogos**. Los **alumnos NO son usuarios**: son registros gestionados por
el staff. Producto **multi-tenant**: varias escuelas usan la misma app, aisladas
entre sí.

La versión anterior (vibe coding, React + Supabase directo) tuvo una auditoría
con hallazgos graves: auth falsa compartida, RLS `USING (true)`, edge functions
privilegiadas sin auth, API keys logueadas, sin tenant real de esquema. Este
rewrite existe para no repetir esos errores. **No hay usuarios en producción
todavía** — se prioriza una base técnica correcta sobre velocidad.

## Stack

| Capa | Tecnología |
|---|---|
| Frontend | React 19 + Vite + TypeScript + Tailwind v4 + shadcn/ui (SPA, sin SSR) |
| Backend | Laravel 13 (PHP 8.3+) como API REST/JSON — único backend con acceso a la DB |
| Base de datos | PostgreSQL (proveedor externo en deploy — por env, nunca hardcodeado) |
| Auth | Laravel Sanctum (SPA vía cookies) — auth real por persona |
| Roles | Spatie laravel-permission (`teacher`, `director`, `psychopedagogue`) |
| Validación backend | Form Requests (autoridad real) |
| Validación frontend | Zod + react-hook-form (solo UX) |
| Storage | Laravel Storage driver S3-compatible (R2) — discos privados + URLs firmadas |
| IA (futuro) | HTTP Client → OpenAI en Jobs + Queues; API key solo en backend |
| Tests backend | Pest |
| Tests frontend | Vitest + Testing Library |
| E2E (futuro) | Playwright (login, crear evaluación, ver reporte) |
| CI | GitHub Actions: lint + typecheck + tests por PR |
| Hosting | Front: Vercel/Netlify · Back: Railway/Render/Laravel Cloud · DB: managed PG |
| Local dev | Laravel Sail (Docker) para el backend |

## Estructura del repo

```
/
├── api/            → Laravel (backend)
├── web/            → React + Vite (frontend)
├── .github/workflows/ci.yml  → CI para ambas apps
├── docs/ARCHITECTURE.md      → este documento
└── CLAUDE.md       → reglas de arquitectura y seguridad
```

## Arquitectura por capas

```
React (SPA)
   │  axios → API REST (JSON, cookie de Sanctum)
   ▼
Laravel — capa de aplicación
   │  Controller → FormRequest (valida) → Policy (autoriza)
   │  → Service/Action (lógica) → Job (async: IA)
   ▼
Eloquent (Global Scope de school_id automático)
   ▼
PostgreSQL (cada tabla de negocio con school_id)
```

**Regla no negociable:** la autorización se decide en el servidor (Policies),
nunca confiando en el cliente. El front puede ocultar botones por rol (UX), pero
eso nunca es la barrera de seguridad.

## Tenancy y roles

- `schools` es el tenant raíz. `users.school_id` obligatorio; un usuario
  pertenece a **una sola** escuela (sin multi-escuela por usuario en v1).
- Cada tabla de negocio tiene `school_id`, aplicado por el trait
  `App\Models\Concerns\BelongsToSchool`, que:
  - agrega el global scope `App\Models\Scopes\SchoolScope` (filtra lecturas), y
  - autocompleta `school_id` al crear (desde el tenant actual).
- El "tenant actual" lo resuelve `App\Support\Tenancy` (del usuario autenticado;
  en consola/Jobs/tests se fija con `Tenancy::useSchool()` / `forSchool()`).
- `User` **no** usa el scope (recursaría al resolver la auth); el aislamiento de
  filas de usuarios se controla explícitamente en queries/policies.
- Roles (`App\Enums\Role`) — identificadores en inglés, labels en español en el
  frontend (`web/src/types.ts`):
  - **Docente** (`teacher`): sus propios grupos/planificaciones/evaluaciones.
  - **Psicopedagogo** (`psychopedagogue`): visibilidad completa de la escuela.
  - **Director** (`director`): visibilidad completa + administración de la escuela.
- Las Policies chequean **tenant + rol** (defensa en profundidad sobre el scope).
  Ver `GroupPolicy` / `StudentPolicy` como patrón de referencia.
- Alumnos = registros (tabla `students`), sin login.

## Autenticación (SPA + Sanctum)

- Login por cookies (no tokens). Flujo: `GET /sanctum/csrf-cookie` →
  `POST /api/login` → sesión; `GET /api/me` para bootstrap; `POST /api/logout`.
- Middleware `statefulApi()` en `bootstrap/app.php`; dominios en
  `SANCTUM_STATEFUL_DOMAINS`; CORS en `config/cors.php` desde `FRONTEND_URL`.
- Primer usuario: `php artisan app:create-user` (sin cuentas hardcodeadas).

## Reglas de seguridad (derivadas de la auditoría)

1. Sin credenciales/API keys/cuentas demo en el código; todo por env, nunca
   commiteado (`.env` en `.gitignore` desde el primer commit).
2. Nunca loguear API keys ni tokens completos.
3. Ningún endpoint privilegiado sin auth + chequeo de autorización explícito.
4. Ninguna policy/scope "permitir todo" (`true`) salvo recurso público deliberado.
5. Todo input se valida en el backend (Form Requests).
6. HTML de IA se sanitiza con librería real (DOMPurify, `web/src/lib/sanitize.ts`),
   nunca regex casero.
7. Buckets/discos privados por defecto; acceso vía URLs firmadas temporales.
8. CORS restringido a los dominios reales, nunca `*` en producción.
9. Separación real de ambientes por env; sin hosts/URLs de deploy hardcodeados.
10. Sin endpoints de debug que expongan información interna.

## Estado actual (scaffolding)

**Implementado**
- Laravel + Sail (pgsql + redis), Sanctum SPA, Spatie Permission.
- Tenancy: `schools`, `users.school_id`, `groups`, `students`; trait + scope +
  `Tenancy`.
- Roles seeder + Policies de ejemplo (group/student).
- Endpoints de auth (`login`/`logout`/`me`) + `LoginRequest`.
- Comando `app:create-user`.
- Tests Pest (auth, tenancy, policies) — verde.
- SPA: cliente axios con CSRF, `AuthProvider`, login + dashboard, ruta protegida;
  tests Vitest — verde.
- CI para ambas apps.

**Pendiente (no implementar hasta pedirlo)**
- Generación de evaluaciones/planificaciones/boletines con IA (Jobs + OpenAI).
- Comunicaciones, materiales, seguimiento pedagógico.
- Storage R2 + URLs firmadas (config lista en `.env`, sin uso todavía).
- Sentry, Playwright E2E.
