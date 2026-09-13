# Sesión 8 (backend) — Categoría de ajuste y desactivación por instancia

**Depende de:** Sesión 1-4 (dominio, roles, auditoría) y de la Sesión 6
(`13-evaluaciones-resultados.md` — necesita `Assessment` con CRUD real para
que "instancia" sea una evaluación concreta, no una etiqueta libre).
**Bloquea a:** Sesión 10 (backend, `20-linea-tiempo-alumno.md` — línea de
tiempo de desempeño del alumno, que muestra estas marcas). También bloquea a
la Sesión 12 (frontend, este mismo módulo) y a la Sesión 14 (frontend, Perfil
de alumno).
**Contexto persistente:** ya cargado desde `CLAUDE.md`. Ver también
`aulaplus-documento-vivo/documento_aulaplus.html`, pantalla 4, decisiones
"G3 · Galia" y "E4 · Eitán".

## Objetivo

Dos extensiones puntuales de `Accommodation`, extraídas de una sesión más
grande ("Perfil de alumno") para poder revisarlas de forma independiente.

## 1. Categoría de ajuste (`Accommodation.category`)

El documento distingue tres categorías de ajuste, independientes de la
descripción libre que ya existe en `type`: un ajuste de **acceso** (cómo
llega la consigna/el entorno), de **contenido**, o de **criterio** (cambia
qué se puntúa — ej. "ortografía no puntúa en Idioma Español"). Hoy
`Accommodation` solo tiene `type` (string libre) — no alcanza para
distinguir estos tres casos de forma consultable (filtrar/agrupar por
categoría).

Migración nueva sobre `accommodations`:

```php
Schema::table('accommodations', function (Blueprint $table) {
    $table->string('category')->nullable();
});
```

Nuevo enum `App\Enums\AccommodationCategory: Access='access'|Content='content'|Criteria='criteria'`.
Agregar `category` al `#[Fillable]` y al cast del modelo.

No hay accommodations reales en producción todavía (piloto no arrancó): la
columna puede quedar `nullable` para no romper factories existentes que no la
seteen, pero las escrituras nuevas deben exigirla como campo requerido.

**Hallazgo (verificado contra el código real):** hoy **no existe ningún
endpoint para crear ni editar una `Accommodation`** — ni Controller ni
FormRequest. Lo único que existe sobre este modelo es
`AccommodationApprovalController` (`approve`/`reject`) y
`BarrierAccommodationController` (vincular una accommodation ya creada a una
barrera). La Sesión 1 dejó migración, modelo y `AccommodationPolicy`
(`create`/`update` ya definidos: `create` solo chequea que el usuario tenga
algún rol — `Role::values()`, sin distinción —, `update` solo chequea
`sharesSchool`, sin restricción de rol) pero nunca construyó el endpoint de
creación/edición. No es un atajo de esta sesión completar ese hueco — es
donde `category` tiene que exigirse, así que esta sesión agrega:

| Método | Ruta | Notas |
|---|---|---|
| POST | `/api/v1/students/{student}/accommodations` | Crea. `category` requerido (`access`\|`content`\|`criteria`). Autoriza con `AccommodationPolicy::create` (ya existe, no crear una nueva) |
| PATCH | `/api/v1/accommodations/{accommodation}` | Edita, incluye `category`. Autoriza con `AccommodationPolicy::update` (ya existe) |

Nuevo `StoreAccommodationRequest`/`UpdateAccommodationRequest` (mismo patrón
de nombres que `StoreStudentRequest`/`UpdateStudentRequest`), con `category`
en las reglas de validación (`required|in:access,content,criteria` en la
creación). No es parte del alcance de esta sesión endurecer
`AccommodationPolicy::create`/`update` más allá de esto — quedan con el mismo
criterio de rol/tenancy que ya tienen hoy.

## 2. Desactivar un ajuste por instancia (`AccommodationInstanceOverride`)

Nueva entidad (tenant-scoped con `school_id` propio, `Auditable`):

| columna | tipo | notas |
|---|---|---|
| school_id | FK | |
| accommodation_id | FK → accommodations | |
| assessment_id | FK → assessments | la "instancia" — ahora que `Assessment` tiene CRUD (Sesión 6), la constancia se ata a una evaluación real, no a una etiqueta libre |
| deactivated_by_id | FK → users | |
| reason | text | obligatorio — "constancia" de por qué se apagó |

Regla: la constancia se pega a la instancia (`assessment_id`), nunca al
alumno ni a la accommodation en general — la accommodation sigue vigente para
cualquier otra evaluación.

Endpoints:

- `POST /api/v1/accommodations/{accommodation}/instance-overrides` — body
  `{ assessment_id, reason }`. Solo el teacher dueño del `Assessment` — mismo
  criterio que `AssessmentPolicy::isOwner` (`$assessment->teacher_id ===
  $user->id`); ese método es `protected` en `AssessmentPolicy`, así que la
  Policy nueva replica la comparación inline, no invoca el método de otra
  clase — y solo si la accommodation pertenece a un alumno del grupo de esa
  evaluación (validar en el FormRequest, 422 si no: `Student` no tiene
  `group_id` propio, la pertenencia se chequea vía el pivot `group_student`,
  ej. `$assessment->group->students()->whereKey($accommodation->student_id)->exists()`).
- `GET /api/v1/assessments/{assessment}/instance-overrides` — listado,
  mismas reglas de visibilidad clínica que `Accommodation` (Gate
  `view-clinical-profile`).

`AccommodationInstanceOverridePolicy`: `create` — teacher dueño del
assessment; `view` — igual que `AccommodationPolicy::view` (comparte school).

## 3. Tests

- `Accommodation` requiere `category` en creación/edición (422 sin ella).
- `AccommodationInstanceOverride`: un teacher que no dicta el assessment no
  puede crearla (403); falla (422) si la accommodation no es de un alumno del
  grupo de esa evaluación.
- Tenant-isolation estándar para `AccommodationInstanceOverride`.

## 4. Criterios de aceptación

- [ ] Endpoint de creación/edición de `Accommodation` (no existía —
      `StoreAccommodationRequest`/`UpdateAccommodationRequest`, Controller,
      rutas) agregado, con `category` implementado y exigido en escritura.
- [ ] `AccommodationInstanceOverride` completo (migración, modelo, factory,
      Policy, FormRequest, Controller, tests).
- [ ] Todos los tests de la sección 3 en verde; `./vendor/bin/sail test`
      pasa completo (sin romper Sesiones 1-4, 6).
- [ ] `sail bin pint` limpio.
