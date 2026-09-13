# Plan de sesiones — Aula+

Este documento no es un prompt de implementación — es la guía de cómo usar los 5 anteriores (`01` a `05`) con Claude Code, en corridas nocturnas.

## 1. Orden de ejecución (no es opcional)

```
01-modelo-dominio-multitenancy.md
        ↓
02-roles-permisos.md
        ↓
03-flujos-aprobacion-trazabilidad.md
        ↓
04-seguimiento-institucional.md
        ↓
05-asistente-ia-docente.md
```

Cada sesión asume que la(s) anterior(es) están mergeadas y sus tests pasan. No paralelizar — aunque `04` y `05` parezcan independientes entre sí, `05` usa endpoints y patrones que se construyen en `04` (el perfil de seguimiento como contexto del prompt), así que van en serie igual.

**Sesiones 6-13** se agregaron después, a partir de
`aulaplus-documento-vivo/documento_aulaplus.html` (ver `CLAUDE.md`
§"Functional spec sources"). Ninguna depende de la Sesión 5 (ya mergeada) —
pueden correr en cualquier momento después de la 4. **Perfil de grupo y
Perfil de alumno son el foco del producto** (uso diario, seguimiento de
alumnos/grupos) — todo lo demás en esta tanda existe para que esas dos
pantallas tengan datos reales, sin atajos. Sesiones deliberadamente chicas y
de una sola pieza cada una (en vez de una sesión grande por pantalla), a
pedido explícito:

```
Sesión 6  → 13-evaluaciones-resultados.md         (crea AssessmentResult)
Sesión 7  → 17-seguimiento-programado.md          (crea ScheduledFollowUp)
Sesión 8  → 18-ajustes-categoria-instancia.md     (Accommodation.category + override por instancia; depende de la 6)
Sesión 9  → 19-comentarios-alcance.md             (Comment.author_only + fix de comments_count)
Sesión 10 → 20-linea-tiempo-alumno.md             (agrega Sesión 6+8+9 → gráfico de alumno)
Sesión 11 → 21-perfil-de-grupo.md                 (agrega Sesión 4+6+7+8+9 → agregados de grupo)
Sesión 12 → 22-grupos-listado.md                  (columna agregada en el listado)
Sesión 13 → 11-pruebas-de-sondeo.md               (sin relación con las anteriores; última por prioridad de producto, no por dependencia)
```

Dependencias reales (no todas son estrictamente secuenciales): 6, 7 y 9 son
independientes entre sí y podrían correrse en cualquier orden relativo. 8
**no** es independiente del resto — depende de 6 (`18-ajustes-categoria-
instancia.md` necesita el CRUD real de `Assessment` para que la "instancia"
de `AccommodationInstanceOverride` sea una evaluación concreta, no una
etiqueta libre; ver el encabezado de ese archivo). 10 necesita 6+8+9
mergeadas. 11 necesita 4+6+7+8+9 mergeadas. 12 necesita 4+7. 13 (sondeo) es
independiente de todas las anteriores — sigue última solo por prioridad de
producto (los perfiles son de uso diario; el sondeo, ocasional).

## 2. Cómo correr cada sesión

Por sesión:

1. Confirmar que la rama de la sesión anterior está mergeada a `main` (o a la rama de integración que uses) y que `./vendor/bin/sail test` pasa en `main` antes de arrancar la siguiente.
2. Crear rama nueva: `feature/sesion-0N-<nombre-corto>`.
3. Iniciar la sesión de Claude Code con `/clear` (contexto limpio) — `CLAUDE.md` se carga solo por estar en la raíz del repo.
4. Pegar como prompt inicial el contenido completo del archivo `0N-....md` correspondiente.
5. Si vas a usar `schedule` para que corra de noche sin supervisión, agregá al final del prompt una instrucción explícita de cierre, por ejemplo:

   > "Cuando termines, corré la suite de tests completa, hacé commit de todo con mensajes descriptivos, y dejá un resumen en `docs/prompts/06-plan-de-sesiones.md` bajo la sesión correspondiente (sección 4 de este archivo). No hagas merge a main vos mismo — dejá la rama en un PR listo para revisión."

6. A la mañana siguiente: revisar el diff, correr los tests localmente vos también (no confiar ciegamente en que "pasaron" según el resumen de la sesión), y recién ahí mergear.

## 3. Recomendación de cadencia

No metas más de una sesión por noche las primeras veces, aunque el contenido de una sesión parezca correr rápido — `01` en particular es grande (19 entidades + multi-tenancy) y conviene que la revises con calma antes de construir las siguientes cuatro sesiones encima de una base que todavía no verificaste vos mismo.

Si `01` te queda muy grande para una sola corrida nocturna, se puede partir en dos:
- `01a`: solo migraciones + modelos + relaciones (secciones 1-3 del archivo).
- `01b`: solo factories, seeders y tests de aislamiento (secciones 4-5).

El resto de las sesiones (`02` a `05`) están dimensionadas para entrar en una corrida nocturna cada una.

## 4. Checklist de progreso

Marcar acá a medida que cada sesión se completa y mergea. Cada sesión debería agregar 3-5 líneas de resumen (qué se hizo, qué quedó pendiente/asumido) antes de cerrar, siguiendo los pasos de "Working in this repo" de `CLAUDE.md` (lint/typecheck/test/build según corresponda, PR limpio para CI).

- [ ] **Sesión 1** — Modelo de dominio (sobre el skeleton de tenancy existente)
  Resumen:
- [ ] **Sesión 2** — Roles y permisos
  Resumen: Implementadas las 13 Policies de la matriz de doc02 §2 (User, Student,
  Group, AnnualPlan/Unit/ClassSession/Assessment, Accommodation, Barrier,
  TechnicalReport, Calendar/CalendarEvent, catálogo curricular), con helpers
  `User::teachesGroup`/`teachesStudent` reutilizados en todas. `StudentResource`
  filtra el perfil clínico a nivel de campo vía `Gate::allows('view-clinical-profile', ...)`.
  Asunciones documentadas en el PR: ownership de `ClassSession` inferido vía
  liderazgo de Group (no tiene `teacher_id` propio); `Unit` hereda tenant y
  ownership vía su `AnnualPlan`. PR apilado sobre `feature/sesion-01-modelo-dominio`
  (Sesión 1 aún no mergeada a `develop`). 92/92 tests en verde, Pint limpio.
- [ ] **Sesión 3** — Flujos de aprobación y trazabilidad
  Resumen:
- [ ] **Sesión 4** — Seguimiento institucional
  Resumen:
- [ ] **Sesión 5** — Asistente de IA docente
  Resumen: Implementado el asistente de IA "propone, no decide": entidad
  `AIProposal` (tenant-scoped, Auditable, autoría explícita vía
  `requested_by_id`), enums `AIProposalType`/`AIProposalStatus`,
  `AIProposalPolicy` (reusa `teachesGroup` + roles school-wide; solo el
  solicitante aplica/descarta). `BuildProposalContext` arma el contexto
  minimizando PII (resumen agregado y anonimizado del grupo, alumno objetivo
  como `Student #1`, nunca `full_name`); `GenerateAIProposalJob` (ShouldQueue,
  cliente HTTP nativo contra Anthropic, timeout 60s, 2 reintentos de red,
  marca `error` sin crashear ante JSON inválido/fuera de schema, envuelto en
  `Tenancy::forSchool`); `ApplyProposal` crea AnnualPlan/Unit/ClassSession/
  Assessment en transacción + `usage_events` `ai_proposal.applied`. Endpoints
  v1 (`generate` 202 con `throttle:ai-proposal-generate` 20/h por school,
  `show`, `apply`, `discard`) con `GenerateAIProposalRequest` (un solo
  FormRequest, ruleset por tipo, valida pertenencia group/school de todo id
  referenciado). Decisiones asumidas y documentadas en código: schema de
  salida por tipo para los 3 tipos que el spec no detallaba; fallbacks para
  columnas NOT NULL de Sesión 1 al aplicar (p.ej. `class_sessions.date`,
  `units.position`, `annual_plans.language`) ya que el schema de sesión del
  modelo no las trae; `discard` gateado igual que `apply` por simetría;
  heurística v1 de subárbol curricular (backbone + match por subject/focus).
  `ANTHROPIC_API_KEY`/`ANTHROPIC_MODEL` en `.env.example` sin modelo
  hardcodeado. Resultado: 22 tests nuevos en verde (168 en total, +22), Pint
  limpio (168 tests, 3 corridas consecutivas en verde). Verificado en este
  entorno con `php artisan test` sobre SQLite en memoria (Sail/Docker no
  disponible acá). Único cambio fuera de Sesión 5: un fix de determinismo de
  una línea en `StudentHistoryTest` (Sesión 3), que era flaky ~1/3 de las
  corridas porque `AccommodationFactory` elige `focus_area` al azar y el
  `update` del test a veces quedaba no-op (sin log `updated`); se fija el
  `focus_area` inicial a un valor distinto del que setea el `update`. Es
  test-only, no toca código de producción de Sesiones 1-4.
- [ ] **Sesión 6** — Evaluaciones y resultados (`13-evaluaciones-resultados.md`)
  Resumen: Cerrado el hueco de Sesión 1: `Assessment` ahora tiene CRUD completo
  (`GET`/`POST /groups/{group}/assessments`, `PATCH`/`DELETE /assessments/{assessment}`)
  con nueva columna `administered_at` (date, not null, migración aparte) sumada
  al `#[Fillable]`/cast/factory. El chequeo de grupo puntual (`teachesGroup`) va
  en `StoreAssessmentRequest`, sin tocar la `AssessmentPolicy` de Sesión 2.
  Entidad nueva `AssessmentResult` (`BelongsToSchool` + `Auditable` +
  `TracksAuthorship`, único `(assessment_id, student_id)`) con migración, modelo,
  factory, `AssessmentResultPolicy` (view school-wide / create-update solo el
  teacher dueño), `StoreAssessmentResultsRequest` (upsert en batch, valida
  pertenencia al grupo vía pivot `group_student`), Controller y Resources.
  `POST /assessments/{assessment}/results` hace upsert por `(assessment, student)`;
  `GET /students/{student}/results` devuelve el timeline ordenado por
  `administered_at` (lo que consumirá el gráfico de Perfil de alumno). Asumido:
  rango de `score` `0–999.99` (el spec no fija escala) y fallback
  `administered_at = today()` en `ApplyProposal` de Sesión 5 (borrador de IA aún
  no tomado; editable por PATCH) para no romper esa acción con la nueva columna
  NOT NULL. 24 tests nuevos; suite completa 191/191 en verde (2 corridas
  consecutivas), Pint limpio. Verificado con `php artisan test` sobre SQLite en
  memoria (sin Sail/Docker en este entorno).
- [x] **Sesión 7** — Seguimiento programado (`17-seguimiento-programado.md`)
  Resumen: Entidad `ScheduledFollowUp` completa (tenant-scoped vía
  `BelongsToSchool`, `Auditable` con `description`/`resolution_note` excluidos
  del diff — regla de seguridad 11): migración, modelo, factory, Resource,
  `ScheduledFollowUpPolicy` nueva (no reusa `AccommodationPolicy`: exige
  relación real con el alumno — `teachesStudent || rol school-wide`, y mismo
  colegio; `resolve` = misma regla que `create`, no es aprobación de cuatro
  ojos), dos FormRequests (`Store`/`Resolve`) y controlador con 3 endpoints v1
  (`POST`/`GET /students/{student}/scheduled-follow-ups`, `POST
  /scheduled-follow-ups/{followUp}/resolve`). `is_overdue` se calcula
  server-side en el Resource (`!resolved && due_date <= today` en la zona
  horaria de la app, `config('app.timezone')` = UTC), nunca en el cliente. El
  `index` devuelve todos por defecto y sólo filtra si viene `?resolved`
  (decisión del cliente, no del backend). 11 tests nuevos (rol/tenant, los tres
  casos de `is_overdue`, aislamiento cross-school, redacción del audit diff);
  suite completa 179/179 en verde y Pint limpio. Verificado en este entorno con
  `php artisan test` sobre SQLite en memoria (sin Docker/Sail); requirió generar
  `APP_KEY` local (`.env`, gitignored) para las pruebas de auth preexistentes.
  Sin vínculo estructural a `Accommodation`/`Barrier` todavía, según la decisión
  de alcance del spec.
- [x] **Sesión 8** — Categoría de ajuste y desactivación por instancia (`18-ajustes-categoria-instancia.md`)
  Resumen: Nuevo enum `AccommodationCategory` (access/content/criteria) + columna
  `category` en `accommodations` (nullable en DB, requerida en escritura). Se
  agregó el endpoint de creación/edición de `Accommodation` que faltaba desde
  Sesión 1 (`StoreAccommodationRequest`/`UpdateAccommodationRequest`,
  `AccommodationController`, rutas POST `/students/{student}/accommodations` y
  PATCH `/accommodations/{accommodation}`), reutilizando `AccommodationPolicy`
  sin endurecerla. Nueva entidad `AccommodationInstanceOverride` (tenant-scoped,
  Auditable, `reason` fuera del diff) con migración, modelo, factory, Policy,
  FormRequest, Controller, Resource y rutas: crear solo el teacher dueño del
  assessment (403 si no; 422 si la accommodation no es de un alumno del grupo),
  listado por assessment con visibilidad del dueño + roles school-wide. Rama
  parte de `develop` + merge de Sesión 6. 206/206 tests en verde, Pint limpio.
- [ ] **Sesión 9** — Alcance de comentarios (`19-comentarios-alcance.md`)
  Resumen: Agregado el cuarto alcance "solo quien escribe" a `Comment` vía
  columna `author_only` (migración nueva, boolean default false, cast a
  boolean). Cuando `author_only=true` el comentario es visible únicamente para
  `author_id`, más restrictivo que cualquier rol; `visible_to` se fuerza a
  `null` (mutuamente excluyentes) en `prepareForValidation` de ambos
  FormRequests (`StoreStudentCommentRequest`/`StoreGroupCommentRequest`, campo
  `author_only` opcional `boolean`). `Comment::scopeVisibleToRole` (DB) e
  `isVisibleToRoles(array)` → renombrado a `isVisibleTo(User)` (necesita saber
  *quién* pregunta, no solo sus roles), ambos actualizados y sincronizados;
  único call site (`StudentTrackingResource`, Sesión 4) migrado al nuevo
  método. Corregido el bug de la Sesión 4: `GroupTrackingResource` ahora omite
  por completo la clave `trend.comments_count` (no `0`/`null`) para quien no es
  school-wide, vía `$this->when($user->hasAnyRole(Role::schoolWideValues()))`
  — el conteo sigue incluyendo comentarios privados (el filtro por período no
  se tocó), solo se restringe quién recibe la clave. Añadidos helpers
  `CommentFactory::authorOnly()` y `author_only` en `CommentResource`. Tests
  nuevos (author_only invisible para todo otro rol incl. dirección/psico;
  regresión de la regla por rol para `author_only=false`; teacher no recibe
  `comments_count`; school-wide recibe el total con privados incluidos).
  Suite completa 173/173 en verde y Pint limpio, corridos con `php artisan
  test`/`./vendor/bin/pint` directamente (sin Sail/Docker en este entorno
  cloud; SQLite en memoria). Nota: en `develop` los backends de Sesiones 6-8
  aún no están mergeados, pero Sesión 9 solo depende de Sesión 4 (ya presente),
  así que no hubo bloqueo.
- [ ] **Sesión 10** — Línea de tiempo de desempeño del alumno (`20-linea-tiempo-alumno.md`)
  Resumen:
- [ ] **Sesión 11** — Perfil de grupo (`21-perfil-de-grupo.md`)
  Resumen:
- [ ] **Sesión 12** — Columna agregada en Grupos (`22-grupos-listado.md`)
  Resumen: Agregado `active_tracking_count` al listado `GET /api/groups` (ruta
  base, sin prefijo `v1`): conteo agregado de alumnos con seguimiento activo
  por grupo (Accommodation efectiva, Barrier activa, Alert sin resolver o
  ScheduledFollowUp sin resolver — unión de alumnos, nunca suma de conteos).
  Se calcula en `GroupController::index` con cuatro consultas agregadas (una
  por tabla, join a `group_student`), sin N+1; la condición `isEffective()` se
  replica en SQL (`active AND (!requires_external_approval OR approved)`) en
  vez de fila por fila. Sólo agregado: nunca nombres/PII. `GroupResource` lo
  incluye condicionalmente (via `when`), sólo en el listado; `show/store/
  update` lo omiten en vez de exponer un 0 engañoso, y respeta las global
  scopes (SchoolScope + soft-deletes de Accommodation/Barrier). 9 tests nuevos
  (unión cuenta 1, grupo sin seguimiento da 0, columna para `teacher`, las 4
  fuentes + estados inactivos/resueltos, accommodation pendiente de aprobación
  no cuenta, no expone nombres/detalle clínico, aislamiento cross-school, y
  conteo de queries constante ante más grupos = sin N+1). Suite completa
  188/188 en verde y Pint limpio. Verificado en este entorno con `php artisan
  test` sobre SQLite en memoria (sin Docker/Sail); requirió `APP_KEY` local
  (`.env`, gitignored). Rama apilada: mergeó `feature/sesion-07-seguimiento-
  programado` (dependencia, aún no en `develop`) antes de implementar.
- [ ] **Sesión 13** — Pruebas de sondeo (`11-pruebas-de-sondeo.md`)
  Resumen: Implementado el subsistema de pruebas de sondeo (`ScreeningTest*`),
  independiente de las demás sesiones. Cuatro entidades tenant-scoped
  (`BelongsToSchool` + `Auditable`): `ScreeningTestType` (catálogo por colegio),
  `ScreeningTestDesign` (cortes + significados, patrón tri-estado
  `approved` null/true/false igual que `Accommodation`, aprobación/rechazo
  director-only reutilizando el patrón de `AccommodationApprovalController`),
  `ScreeningTestApplication` y `ScreeningTestResult`. Al crear una aplicación se
  generan resultados con `code` secuencial (`"01"`, `"02"`…) por orden
  alfabético de `full_name` de los alumnos activos del grupo (soft-deletes
  cubren "alumno dado de baja"; renumera desde `"01"` por aplicación). El
  `color` se calcula (`ScreeningColor::fromScore`, corte inclusivo en ambos
  extremos, rojo gana el empate) y se **persiste** al cargar el puntaje contra
  el diseño aprobado vigente — nunca se recalcula en lectura, así que aprobar un
  diseño nuevo no altera colores ya cargados. 4 Policies school-wide sin
  excepción para `teacher`; roster (código→alumno) y resultados-por-código son
  **solo psicopedagogía** (el director no ve el mapeo), crear tipo/diseño/
  aplicación y cargar puntaje también psicopedagogía, aprobar/rechazar diseño
  solo dirección. Validación en FormRequests (incl. `cutoff_high >= cutoff_low`
  y `screening_test_type_id` acotado al colegio del usuario); precondiciones de
  negocio (sin diseño aprobado, diseño ya resuelto) responden 422. El
  `ScreeningTestResultResource` nunca expone `student_id`/nombre. Verificado en
  este entorno con `php artisan test` sobre SQLite en memoria (no hay Docker/
  Sail acá): 197 tests en verde (+29 nuevos), Pint limpio. Sin cambios fuera del
  módulo. Pendiente/diferido por spec: mostrar resultados dentro de
  `GET /students|groups/{..}/tracking` y cualquier vínculo con PTP.

## 5. Explícitamente fuera de este plan

No hay sesiones para (ver también la sección "Out of scope for now" de `CLAUDE.md`):

- Integración con SIGED.
- Billing / planes / entitlements por módulo.
- Boletín, Indicador de Progreso, Proyecto.
- Frontend (React + Vite) — este archivo cubre solo el backend/API; ver
  `docs/prompts/10-plan-de-sesiones-frontend.md` para el set de prompts de
  frontend, ya en curso.

Cuando quieras encarar alguno de estos frentes, generamos el set de markdowns correspondiente siguiendo el mismo formato.
