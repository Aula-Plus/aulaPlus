# Sesión 8 (backend) — Categoría de ajuste y desactivación por instancia

**Depende de:** Sesión 1-4 (dominio, roles, auditoría) y de la Sesión 6
(`13-evaluaciones-resultados.md` — necesita `Assessment` con CRUD real para
que "instancia" sea una evaluación concreta, no una etiqueta libre).
**Bloquea a:** Sesión 10 (línea de tiempo de desempeño del alumno, que
muestra estas marcas) y la UI de Perfil de alumno.
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
seteen, pero el FormRequest de creación/edición de `Accommodation` (donde sea
que viva — verificar contra el código real, esta sesión completa lo que haga
falta) debe exigirla como campo requerido en las escrituras nuevas.

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
  `{ assessment_id, reason }`. Solo el teacher dueño del `Assessment`
  (`AssessmentPolicy::isOwner`, no cualquier teacher del colegio) y solo si
  la accommodation pertenece a un alumno del grupo de esa evaluación
  (validar en el FormRequest, 422 si no).
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

- [ ] `Accommodation.category` implementado y exigido en escritura.
- [ ] `AccommodationInstanceOverride` completo (migración, modelo, factory,
      Policy, FormRequest, Controller, tests).
- [ ] Todos los tests de la sección 3 en verde; `./vendor/bin/sail test`
      pasa completo (sin romper Sesiones 1-4, 6).
- [ ] `sail bin pint` limpio.
