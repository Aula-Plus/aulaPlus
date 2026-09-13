# Sesión 13 (backend) — Pruebas de sondeo

**Depende de:** Sesión 1 (dominio/multitenancy), Sesión 2 (roles/Policies), Sesión 3
(`Auditable`, patrón aprobar/rechazar de `Accommodation`).
**No depende de** la Sesión 5 (asistente de IA docente) ni de las Sesiones
6-12 (Evaluaciones/Perfil de alumno/Perfil de grupo) — este módulo es
independiente y quedó más atrás en la cola solo por prioridad de producto
(los perfiles de alumno/grupo son de uso diario; el sondeo se aplica un par
de veces al año), no por una dependencia técnica real.
**Bloquea a:** Sesión 17 (frontend, "Pruebas de sondeo",
`12-frontend-pruebas-de-sondeo.md`) y, más adelante, la sesión que muestre
resultados de sondeo dentro de Perfil de alumno/grupo (todavía no planificada).
**Contexto persistente:** ya cargado desde `CLAUDE.md` — ver también
`aulaplus-documento-vivo/documento_aulaplus.html`, pantallas 7 ("Pruebas de
sondeo") y 8 ("Diseño de la prueba de sondeo").

> **Nombre provisorio:** el propio documento de producto marca "pruebas de
> sondeo" como un nombre no definitivo. Usamos `ScreeningTest*` en el código
> (inglés, por convención del repo) porque es lo más neutro disponible — si el
> nombre de producto cambia, es un rename de Eloquent models/tablas, no un
> rediseño.

## Objetivo

Implementar el subsistema de pruebas de sondeo: catálogo de tipos por colegio,
diseño (cortes numéricos + significado de cada color) con el mismo patrón
borrador/aprobación que ya existe para `Accommodation`, y la aplicación del
sondeo a un grupo con carga de resultados por código anónimo.

**Fuera de alcance de esta sesión** (se hace en una sesión posterior, una vez
que este endpoint exista y esté estable):

- Mostrar los resultados de sondeo dentro de `GET /students/{student}/tracking`
  o `GET /groups/{group}/tracking`.
- Cualquier vínculo con PTP — ver la nota "PTP y enriquecimiento" en `CLAUDE.md`
  §Out of scope: queda explícitamente diferido.

## 1. Catálogo y diseño

Nueva entidad `ScreeningTestType` (tenant-scoped, `Auditable`):

| columna | tipo | notas |
|---|---|---|
| school_id | FK | |
| name | string | ej. "Comprensión lectora 2do ciclo" |
| active | boolean, default true | |
| created_by_id | FK → users | |

Un colegio puede tener varios tipos activos a la vez (ej. lectura y
matemática), cada uno con su propio diseño.

Nueva entidad `ScreeningTestDesign` (tenant-scoped, `Auditable`) — versión del
diseño de un tipo:

| columna | tipo | notas |
|---|---|---|
| school_id | FK | |
| screening_test_type_id | FK | |
| cutoff_low | decimal | puntaje ≤ este valor → rojo |
| cutoff_high | decimal | puntaje ≥ este valor → verde; entre `cutoff_low` y `cutoff_high` → amarillo |
| meaning_red | text | texto institucional, no lo escribe la IA (pantalla 8, decisión "Sin IA") |
| meaning_yellow | text | |
| meaning_green | text | |
| created_by_id | FK → users | psicopedagogía |
| approved | boolean, nullable | mismo patrón tri-estado que `Accommodation.approved`: `null` pendiente, `true`/`false` resuelto |
| approved_by_id | FK, nullable | dirección |

Reglas:

- Solo psicopedagogía puede crear/editar un diseño (queda con `approved =
  null`). Solo dirección puede aprobarlo o rechazarlo — reutilizar el patrón de
  `AccommodationApprovalController`, no inventar uno nuevo.
- Un `ScreeningTestType` puede tener varias filas de `ScreeningTestDesign` en
  el tiempo (histórico); el diseño vigente para calcular colores nuevos es el
  más reciente con `approved = true`. Un diseño rechazado o pendiente nunca se
  usa para calcular resultados.
- Cambiar el diseño **no recalcula** colores ya guardados en resultados
  existentes — el color se calcula y persiste en el momento de cargar el
  puntaje (ver sección 3), no se deriva en cada lectura.

Endpoints (`/api/v1`, todos `auth:sanctum`):

- `GET /screening-test-types` — listado del colegio (con su diseño vigente si
  existe).
- `POST /screening-test-types` — crear tipo (psicopedagogía).
- `POST /screening-test-types/{type}/designs` — crear/editar diseño, queda
  pendiente (psicopedagogía).
- `POST /screening-test-designs/{design}/approve` — aprobar (dirección).
- `POST /screening-test-designs/{design}/reject` — rechazar (dirección).

## 2. Aplicación a un grupo

Nueva entidad `ScreeningTestApplication` (tenant-scoped, `Auditable`):

| columna | tipo | notas |
|---|---|---|
| school_id | FK | |
| screening_test_type_id | FK | |
| group_id | FK | |
| applied_by_id | FK → users | psicopedagogía |
| application_date | date | |

Al crear una aplicación, generar automáticamente un `ScreeningTestResult` por
cada alumno activo del grupo (ver sección 3) con `code` asignado y `score`/
`color` en `null`.

`code`: string corto único **dentro de la aplicación** (no global). Generarlo
secuencial (`"01"`, `"02"`, ...) según el orden alfabético de `full_name` de
los alumnos del grupo en el momento de crear la aplicación — no derivarlo del
id ni del nombre del alumno de ninguna otra forma, y no reutilizar el mismo
esquema de código entre aplicaciones distintas (cada aplicación nueva vuelve a
numerar desde `"01"`).

Endpoints:

- `POST /api/v1/groups/{group}/screening-test-applications` —
  psicopedagogía únicamente. Falla si el `ScreeningTestType` no tiene un
  diseño aprobado vigente (no se puede aplicar un sondeo sin diseño
  aprobado).
- `GET /api/v1/groups/{group}/screening-test-applications` — listado.
- `GET /api/v1/screening-test-applications/{application}/roster` — la vista
  con el mapeo código → alumno (nombre completo), **solo visible para
  psicopedagogía** y pensada para imprimirse (el frontend le da el estilo de
  impresión; este endpoint solo devuelve los datos). Nunca se expone este
  mapeo en ningún otro endpoint del módulo.

## 3. Carga de resultados

- `PATCH /api/v1/screening-test-results/{result}` — carga `score`.
  Psicopedagogía únicamente. Al guardar:
  - Busca el diseño aprobado vigente del `screening_test_type_id` de la
    aplicación. Si no existe (se rechazó después de crear la aplicación),
    devolver 422 — no se puede cargar un puntaje sin diseño aprobado.
  - Calcula `color`: `score <= cutoff_low` → `red`; `score >= cutoff_high` →
    `green`; en el medio → `yellow`.
  - Guarda `score`, `color`, `loaded_by_id`, `loaded_at`.
- `GET /api/v1/screening-test-applications/{application}/results` — listado
  por código (sin nombre de alumno) para la carga en pantalla; solo
  psicopedagogía. El endpoint de roster (sección 2) es el único que cruza
  código con nombre.

## 4. Policies

Crear `ScreeningTestTypePolicy`, `ScreeningTestDesignPolicy`,
`ScreeningTestApplicationPolicy`, `ScreeningTestResultPolicy` — todas
school-wide, sin excepción para `teacher` (un docente no ve ni crea nada de
este módulo, ni siquiera agregado; ver decisión "Línea roja" de la pantalla 9
que aplica el mismo criterio a PTP y por consistencia también aplica acá).
`approve`/`reject` del diseño exclusivo de `director`, igual que
`AccommodationPolicy::approve`.

## 5. Tests

- Tenant-isolation: un `ScreeningTestType`/`Application`/`Result` de un
  colegio no es visible ni afectable desde otro colegio.
- Un `teacher` recibe 403 en cualquier endpoint de este módulo (crear tipo,
  ver roster, cargar resultado).
- No se puede crear una `ScreeningTestApplication` si el tipo no tiene diseño
  aprobado (422).
- El color calculado en `PATCH .../results/{result}` coincide con los
  cortes del diseño aprobado vigente, usando un caso conocido de cada banda
  (rojo/amarillo/verde) y un caso límite exacto en cada corte.
- Cambiar el diseño (nueva versión aprobada) no modifica el `color` de
  resultados ya cargados con el diseño anterior.
- El endpoint de roster requiere psicopedagogía; el endpoint de resultados
  por código no devuelve el nombre del alumno.

## 6. Criterios de aceptación

- [ ] Migraciones, modelos, Policies y endpoints de las secciones 1-3
      implementados.
- [ ] Todos los tests de la sección 5 en verde.
- [ ] `./vendor/bin/sail test` pasa en verde completo (sin romper nada de
      Sesiones 1-4).
- [ ] `sail bin pint` limpio.
- [ ] Resumen de la sesión agregado al checklist de
      `docs/prompts/06-plan-de-sesiones.md` (entrada "Sesión 13").
