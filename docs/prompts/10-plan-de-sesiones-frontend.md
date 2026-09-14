# Plan de sesiones — Frontend Aula+

Este documento no es un prompt de implementación — es la guía de cómo usar los tres anteriores (`07` a `09`) con Claude Code, en corridas nocturnas. Mismo formato que `06-plan-de-sesiones.md`, que cubre las cinco sesiones de backend.

## 0. Por qué recién ahora

`06-plan-de-sesiones.md` §5 decía explícitamente que el frontend necesitaba su propio set de prompts una vez que la API estuviera estable, para no construir UI contra un contrato que todavía se mueve. Al momento de escribir esto, las Sesiones 1 a 4 del backend (`01`-`04`) ya están mergeadas a `develop` con tests en verde — el contrato de las tres sesiones de este documento (roles/permisos, flujos de aprobación, seguimiento institucional) es estable. La Sesión 5 de backend (asistente de IA docente, `05-asistente-ia-docente.md`) **también ya está mergeada** (ver `06-plan-de-sesiones.md`), pero su UI no está entre las sesiones de este archivo todavía — ver §5.

## 1. Orden de ejecución (no es opcional, y no coincide con el orden del backend)

```
07-frontend-roles-permisos.md
        ↓
08-frontend-seguimiento-institucional.md
        ↓
09-frontend-flujos-aprobacion-trazabilidad.md
```

En el backend, la sesión de "flujos de aprobación" (`03`) se hizo antes que "seguimiento institucional" (`04`). En el frontend el orden se invierte a propósito: la Sesión 8 construye la primera pantalla donde `Accommodation`/`Barrier` son visibles en la UI; sin eso, los botones de aprobar/rechazar/validar de la Sesión 9 no tendrían dónde vivir. Ver `08-frontend-seguimiento-institucional.md` §0 para el detalle. No lo reordenes de nuevo.

**Sesiones 10-17** se agregaron después, contraparte frontend de las
Sesiones 6-13 de backend (`06-plan-de-sesiones.md`) derivadas de
`aulaplus-documento-vivo/documento_aulaplus.html`. Mismo criterio de
granularidad chica: Perfil de grupo y Perfil de alumno son el foco del
producto, todo lo demás existe para que esas dos pantallas tengan datos
reales.

```
Sesión 10 → 14-frontend-evaluaciones-resultados.md
Sesión 11 → 23-frontend-seguimiento-programado.md
Sesión 12 → 24-frontend-ajustes-categoria-instancia.md
Sesión 13 → 25-frontend-comentarios-alcance.md
Sesión 14 → 26-frontend-perfil-de-alumno.md      (gráfico de desempeño, introduce Recharts)
Sesión 15 → 27-frontend-perfil-de-grupo.md
Sesión 16 → 28-frontend-grupos-listado.md
Sesión 17 → 12-frontend-pruebas-de-sondeo.md     (última por prioridad de producto)
```

## 2. Cómo correr cada sesión

Por sesión:

1. Confirmar que la rama de la sesión anterior está mergeada a `develop` (o a la rama de integración que uses) y que, desde `web/`, `npm run lint && npm run typecheck && npm run test && npm run build` pasan en `develop` antes de arrancar la siguiente.
2. Crear rama nueva: `feature/frontend-sesion-0N-<nombre-corto>`.
3. Iniciar la sesión de Claude Code con `/clear` (contexto limpio) — `CLAUDE.md` se carga solo por estar en la raíz del repo.
4. Pegar como prompt inicial el contenido completo del archivo `0N-frontend-....md` correspondiente.
5. Si vas a usar `schedule` para que corra de noche sin supervisión, agregá al final del prompt una instrucción explícita de cierre, igual que en `06-plan-de-sesiones.md`:

   > "Cuando termines, corré `npm run lint`, `npm run typecheck`, `npm run test` y `npm run build` desde `web/`, hacé commit de todo con mensajes descriptivos, y dejá un resumen en `docs/prompts/10-plan-de-sesiones-frontend.md` bajo la sesión correspondiente (sección 4 de este archivo). No hagas merge a main/develop vos mismo — dejá la rama en un PR listo para revisión."

6. A la mañana siguiente: revisar el diff, correr los cuatro comandos localmente vos también (no confiar ciegamente en el resumen de la sesión), y probar manualmente en el navegador al menos el camino feliz de cada rol (`teacher`, `psychopedagogue`, `director`) antes de mergear — estas tres sesiones son casi enteramente gating por rol, y ese tipo de bug no lo agarra `npm run build`.

## 3. Recomendación de cadencia

No metas más de una sesión por noche. La Sesión 8 es la más grande de las tres (cuatro pantallas nuevas, dos features transversales — comentarios y alertas) — si te queda grande para una sola corrida, `08-frontend-seguimiento-institucional.md` §7 ya trae el criterio de partirla en `8a`/`8b`. Las Sesiones 7 y 9 están dimensionadas para entrar en una corrida nocturna cada una.

## 4. Checklist de progreso

- [x] **Sesión 7** — Roles y permisos en la UI
  Resumen: Se creó `web/src/lib/permissions.ts` con las funciones puras de la §2
  del spec (`hasRole`, `hasAnyRole`, `isDirector`, `isPsychopedagogue`,
  `isTeacher`, `isSchoolWideStaff`, `canManageGroups`, `canDeleteGroup`,
  `canManageStudents`, `canDeleteStudent`, `canViewClinicalProfileUX`) más los
  helpers forward-looking para las Sesiones 8/9 (`canResolveAlert`,
  `canApproveAccommodation`, `canValidateBarrierAccommodation`,
  `canViewStudentHistory`, `canViewAdoptionDashboard`), cada una con comentario
  de una línea citando la Policy/controller de backend que refleja. Se
  reemplazaron los chequeos inline en `GroupsListPage` (→ `canManageGroups`),
  `StudentsListPage` (→ `canManageStudents`) y `StudentFormPage`
  (→ `canViewClinicalProfileUX` + `canDeleteStudent`); ningún componente de
  `features/groups` ni `features/students` calcula rol inline. Tests:
  `permissions.test.ts` (tabla rol→función, incluye `user === null`) y los tres
  tests de página existentes siguen pasando sin cambios de aserción.
  **Auditoría contra Policies:** confirmado contra el código real de
  `GroupPolicy`/`StudentPolicy` — el frontend previo ya coincidía, incluido
  `StudentPolicy::delete` = solo `director` (NO psicopedagogo); no hubo
  discrepancia de comportamiento que corregir, el cambio es de consolidación.
  **Fuera de alcance (confirmado, no asumido):** no hay `UserController` ni ruta
  `/api/users` en `api/routes/api.php` (solo `/me`), así que no se construyó UI
  de gestión de usuarios; catálogo curricular sin tocar.
  **Verificación real desde `web/`:** `npx oxlint` → 0 errores (1 warning
  preexistente en `button.tsx`, ajeno); `npm run typecheck` → OK;
  `npm run test -- --run` → todos en verde; `npm run build` → OK (warning de
  tamaño de chunk preexistente). PR #40 contra `develop`.
- [x] **Sesión 8** — Seguimiento institucional en la UI
  Resumen: Se agregó la capa de UI sobre la API de seguimiento (backend
  Sesión 4): tipos en `types.ts` (Comment/Alert/Accommodation/Barrier +
  StudentTracking/GroupTracking/AdoptionDashboard con labels en español),
  `features/tracking/trackingApi.ts` y `adoptionApi.ts` (endpoints `/api/v1`),
  el componente reutilizable `CommentsPanel` y tres pantallas
  (`StudentTrackingPage`, `GroupTrackingPage`, `AdoptionDashboardPage`),
  ruteadas y enlazadas desde las listas ("Seguimiento", visible a cualquier rol
  que pueda ver la clase/alumno; "Editar" sigue gateado por
  `canManageGroups`/`canManageStudents`) + una entrada de nav "Adopción"
  solo-director. Regla clave de `visible_to` (§3): si no se elige ningún rol,
  el campo se **omite** (nunca `[]`, que ocultaría el comentario incluso al
  autor) — con test dedicado. Sin librería de gráficos nueva: el tablero de
  adopción usa tablas simples. Reutiliza `canResolveAlert`/
  `canViewAdoptionDashboard`, ya agregados a `permissions.ts` en la Sesión 7
  como helpers forward-looking (no se duplicaron). **Se desarrolló apilada
  sobre `feature/frontend-sesion-07-roles-permisos`** antes de que esa rama
  mergeara a `develop`; al integrar (PR #40 mergeado primero) se resolvieron a
  mano los renames de la Sesión 7 en los 3 archivos que ambas ramas tocaban:
  `canCreateGroup`/`canEditGroup` → `canManageGroups`, `canCreateStudent`/
  `canEditStudent` → `canManageStudents`, `canAccessClinicalProfile` →
  `canViewClinicalProfileUX`, `isSchoolWide` → `isSchoolWideStaff` (sin cambios
  de comportamiento, solo de nombre). El spec formal
  `08-frontend-seguimiento-institucional.md` no existe en el repo (igual que
  el `07-*`); se implementó desde el enunciado de la tarea + el contrato real
  del backend (Resources/Controllers de Sesión 4) + las convenciones del
  frontend existente. Nada quedó pendiente: las 4 pantallas/componente del
  enunciado están completas (no hizo falta partir en 8a/8b). Verificación real
  desde `web/` tras resolver la integración con `develop`: `npx oxlint` → 0
  errores (mismo warning preexistente en `button.tsx`); `npm run typecheck` →
  OK; `npm run test -- --run` → verde; `npm run build` → OK (mismo warning de
  tamaño de chunk preexistente).
- [x] **Sesión 9** — Flujos de aprobación y trazabilidad en la UI
  Resumen: Sobre `StudentTrackingPage` de la Sesión 8, botones "Aprobar" /
  "Rechazar" por adaptación con `requires_external_approval && approved ===
  null`, un badge de estado (Aprobada / Rechazada / Pendiente / Vigente / No
  vigente) que respeta que `is_effective` reemplaza al badge de aprobación
  cuando no aplica (spec §3); el aprobar/rechazar actualiza en el lugar con
  la respuesta del endpoint —**no** hay refetch del aggregate `tracking`, que
  el backend cachea ~60s. Se agregó `BarrierAccommodationsPanel` colapsable
  por barrera que carga on-demand `GET /barriers/{id}/accommodations`, ofrece
  el `<select>` sólo con adaptaciones del propio alumno (única lista
  conocida) y respeta la regla de cuatro ojos escondiendo el botón
  "Validar" cuando `proposed_by_id === user.id` (no un botón deshabilitado).
  Nuevo `StudentHistoryPage` en `/alumnos/:id/historial` con paginación
  server-side (`Paginated<T>` como envelope único en `types.ts`), origen
  "Sistema" para entradas con `origin: system` (nunca "Usuario #null"), y
  disclosure por fila que imprime `changes` como JSON. Nuevo helper en
  `permissions.ts`: `canProposeBarrierAccommodation` (teacher/psychopedagogue
  — **director deliberadamente excluído** por `BarrierPolicy::update`);
  `canApproveAccommodation`, `canValidateBarrierAccommodation` y
  `canViewStudentHistory` reutilizan los helpers forward-looking que la
  Sesión 7 ya había agregado a `permissions.ts` (no se duplicaron).
  `Accommodation.approved` pasa a `boolean | null` (antes estaba tipado como
  `boolean` a secas, incorrecto según el ResourceJSON). El spec
  `09-frontend-flujos-aprobacion-trazabilidad.md` sí existía en `origin/main`
  (commit `5090014`) — se trajo intacto a la rama. **Se desarrolló apilada
  sobre `feature/frontend-sesion-08-seguimiento-institucional`** antes de que
  esa rama mergeara a `develop`; al integrar (PR #46 mergeado primero) se
  resolvió a mano el mismo rename de permisos que ya había resuelto la
  Sesión 8 en `GroupsListPage`/`StudentsListPage`/`StudentFormPage`/
  `permissions.ts` (tomando la versión ya consolidada de `develop` y
  agregándole únicamente `canProposeBarrierAccommodation`, que no existía
  todavía). Nada quedó pendiente; los seis criterios de aceptación del §7
  están cubiertos. Verificación real desde `web/` tras resolver la
  integración con `develop`: `npx oxlint` → 0 errores (mismo warning
  preexistente en `button.tsx`); `npm run typecheck` → OK;
  `npm run test -- --run` → verde; `npm run build` → OK (mismo warning de
  tamaño de chunk preexistente).
- [x] **Sesión 10** — Evaluaciones y resultados, carga mínima (`14-frontend-evaluaciones-resultados.md`)
  Resumen: UI llana (sin IA de Bloque 2) para que el docente dueño de la clase
  cree evaluaciones y cargue notas. Tipos `Assessment`/`AssessmentResult` en
  `types.ts` (superset de los `Resource` reales de backend Sesión 6; se **reusó**
  `AssessmentType`/`assessmentTypeLabels` ya existentes de la Sesión 8 en vez de
  duplicarlos). Nuevo helper `canManageAssessments(user, group)` en
  `permissions.ts` (rol `teacher` **y** miembro de `group.teachers` — espeja
  `AssessmentPolicy::create` + el `teachesGroup` del FormRequest; director/psico
  quedan solo-lectura, como en la Policy). `features/assessments/`:
  `assessmentsApi.ts` (`/api/v1`) + `AssessmentsPage` (form tipo/fecha/propósito,
  listado por `administered_at` desc) + `AssessmentResultsEditor` (tabla de la
  clase con nota + devolución que hace **un único POST** con el array — upsert,
  nunca N requests). Ruta `/clases/:id/evaluaciones` (el resto del producto rutéa
  grupos bajo `/clases`, no `/grupos` como sugería el spec §4; se mantuvo la
  convención real) enlazada desde `GroupsListPage` y `GroupTrackingPage`; el
  roster/nombre de la clase salen del agregado `GET /groups/{id}/tracking` (única
  fuente de alumnos del grupo visible al docente hoy). Tests nuevos:
  `AssessmentsPage.test.tsx` (payload de creación, upsert de un solo POST,
  solo-lectura sin `canManageAssessments`) y la fila de `canManageAssessments` en
  `permissions.test.ts`. Verificación real desde `web/`: `npm run lint` → 0
  errores (mismo warning preexistente en `button.tsx`); `npm run typecheck` → OK;
  `npm run test -- --run` → 133 en verde; `npm run build` → OK (mismo warning de
  tamaño de chunk preexistente).
- [x] **Sesión 11** — Seguimiento programado en la UI (`23-frontend-seguimiento-programado.md`)
  Resumen: UI para crear/listar/resolver `ScheduledFollowUp` de un alumno,
  embebida como sección "Seguimientos programados" dentro de
  `StudentTrackingPage` (no pantalla nueva). Nuevos: tipo `ScheduledFollowUp` en
  `types.ts` (con `is_overdue` calculado en servidor — el cliente nunca recalcula
  fechas), `features/tracking/scheduledFollowUpsApi.ts` (archivo separado, no se
  agrandó `trackingApi.ts`; envoltorio `{ data }`) y el componente
  `ScheduledFollowUpsPanel` (mismo patrón self-fetching que
  `BarrierAccommodationsPanel`): toggle "Mostrar resueltos" que cambia el query
  param (`?resolved=false` ↔ sin param), formulario nuevo (`description` +
  `due_date`), badge "Vencido" pintado directo desde `is_overdue`, y "Resolver"
  con nota opcional inline (nunca manda `resolution_note: ""`; usa la respuesta,
  sin refetch). Sin helper nuevo en `permissions.ts` (spec §3: los tres roles
  pueden crear/ver/resolver, el límite real es el Controller). Contrato
  verificado contra el backend real (Sesión 7): `ScheduledFollowUpController`,
  `ScheduledFollowUpResource`, `Store`/`ResolveScheduledFollowUpRequest`.
  **Dependencia:** se ramificó de `develop` y se mergeó
  `feature/frontend-sesion-10-evaluaciones-resultados` (fast-forward, sin
  conflictos) antes de implementar, ya que la Sesión 10 aún no estaba en
  `develop`. Verificación real desde `web/` (`npm ci` primero): `npm run lint` →
  0 errores (mismo warning preexistente en `button.tsx`); `npm run typecheck` →
  OK; `npm run test -- --run` → 140 tests en verde (17 archivos, +7 del panel
  nuevo); `npm run build` → OK (mismo warning preexistente de tamaño de chunk).
- [x] **Sesión 12** — Categoría de ajuste y desactivar por instancia en la UI (`24-frontend-ajustes-categoria-instancia.md`)
  Resumen: Se agregó a `StudentTrackingPage` la UI de creación/edición de
  `Accommodation` (que no existía) y la desactivación por instancia. Nuevos:
  tipo `AccommodationCategory` + `accommodationCategoryLabels` y `AccommodationInput`
  en `types.ts` (campo `category` requerido sumado a `Accommodation`);
  `canManageAccommodations` en `permissions.ts` (gateado a `canViewClinicalProfileUX`
  como decisión de UX, no endurece la Policy backend, más permisiva); tres
  funciones en `trackingApi.ts` (`createAccommodation`, `updateAccommodation` —
  `category` requerido también al editar—, `deactivateAccommodationForAssessment`);
  y los componentes `AccommodationFormDialog` (Dialog + RHF + Zod, `category`
  obligatoria vía `<Select>`, `active` solo en edición, refetch completo al
  guardar) y `AccommodationInstanceOverrideForm` (`<select>` poblado desde
  `tracking.recent_assessments` sin fetch extra, `reason` obligatorio, 403 del
  backend mostrado como mensaje legible). Solo se ofrece "Desactivar para una
  evaluación" en adaptaciones con `is_effective === true`. Contrato verificado
  contra el backend real (Sesión 8): `AccommodationController`,
  `AccommodationInstanceOverrideController`, `Store`/`UpdateAccommodationRequest`,
  `StoreAccommodationInstanceOverrideRequest`, `AccommodationCategory` enum.
  **Dependencia:** ramificado de `develop` y mergeado
  `feature/frontend-sesion-11-seguimiento-programado` (sin conflictos) antes de
  implementar, ya que la Sesión 11 aún no estaba en `develop`. Verificación real
  desde `web/` (`npm ci` primero): `npm run lint` → 0 errores (mismo warning
  preexistente en `button.tsx`); `npm run typecheck` → OK; `npm run test -- --run`
  → 145 tests en verde (17 archivos, +5 tests de la Sesión 12); `npm run build`
  → OK (mismo warning preexistente de tamaño de chunk).
- [x] **Sesión 13** — Alcance de comentarios en la UI (`25-frontend-comentarios-alcance.md`)
  Resumen: `CommentsPanel` reemplaza el `fieldset` de checkboxes de rol (que
  permitía combinaciones libres) por un `<Select>` de exactamente cuatro
  opciones preestablecidas — "Todos los que ven este registro" (default),
  "Solo dirección", "Solo psicopedagogía" y "Solo quien escribe". Un
  `buildScopeFields` traduce el estado de UI (`CommentScopeOption`) al payload
  real solo en el submit: `everyone` omite ambos campos (mantiene la regla de
  la Sesión 8, nunca `[]`), `director`/`psychopedagogue` mandan
  `visible_to: [rol]`, y `author_only` manda `author_only: true` — nunca los dos
  juntos (ortogonales y mutuamente excluyentes, §1). En el listado, un comentario
  con `author_only` muestra un badge "Privado" y oculta la línea "Visible para:
  …" (excluyentes). Tipos: `Comment.author_only: boolean` y
  `CommentInput.author_only?`; `GroupTracking.trend.comments_count` pasa a
  opcional y `GroupTrackingPage` deja de renderizar la tarjeta "Comentarios
  cargados" (y baja a una sola columna) cuando el backend omite la clave para un
  viewer sin rol school-wide (§5). Contrato verificado contra el backend real
  de la Sesión 9: `CommentResource` (`author_only`), `Store{Student,Group}
  CommentRequest` (`prepareForValidation` fuerza `visible_to: null`) y
  `GroupTrackingResource` (`$this->when(hasAnyRole(schoolWideValues()))`).
  **Dependencia:** ramificado de `develop` y mergeado
  `feature/frontend-sesion-12-ajustes-categoria-instancia` (sin conflictos)
  antes de implementar, ya que la Sesión 12 aún no estaba en `develop`.
  Verificación real desde `web/` (`npm ci` primero): `npm run lint` → 0 errores
  (mismo warning preexistente en `button.tsx`); `npm run typecheck` → OK;
  `npm run test -- --run` → 150 tests en verde (17 archivos, +5 sobre la
  Sesión 12); `npm run build` → OK (mismo warning preexistente de tamaño de
  chunk).
- [x] **Sesión 14** — Perfil de alumno: gráfico de desempeño (`26-frontend-perfil-de-alumno.md`)
  Resumen: nueva sección "Desempeño" en `StudentTrackingPage` (`StudentPerformanceChart`)
  que consume `GET /students/{student}/performance-timeline` (backend Sesión 10) vía
  `performanceApi.ts` (módulo aparte, mismo criterio que `scheduledFollowUpsApi.ts`).
  Introduce **Recharts** como primera librería de gráficos: `ComposedChart` con la
  línea de `results` (X por `administered_at`, Y por `score`) y cada `mark` como un
  `ReferenceDot` sobre el eje X, con color distinto por tipo (paleta
  `performanceMarkColors` en `types.ts`, reusable por Sesión 15) y tooltip nativo
  (`<title>`) con label + fecha + detalle (`reason`/`title`). Dos `<Input type="date">`
  (`from`/`to`, default = año lectivo actual vía `getCurrentSchoolYear()`) redisparan el
  fetch; `results` vacío muestra "Sin evaluaciones en este rango." en vez de un gráfico
  roto. El cliente **no** filtra marcas por rol — renderiza exactamente lo que trae la
  respuesta (el gate clínico/visibilidad es del backend). Contrato verificado contra el
  código real: la respuesta es `{ results, marks }` **sin** envoltorio `{ data }` (el
  controlador usa `->resolve()`), a diferencia de lo que sugería `26` §4 — se siguió el
  backend real. Tipos nuevos en `types.ts` (`PerformanceMark` discriminado por `type`,
  etc.). Verificación desde `web/` (`npm ci`, luego `npm install recharts`):
  `npm run lint` → 0 errores (solo el warning preexistente de `button.tsx`);
  `npm run typecheck` → OK; `npm run test -- --run` → 159 tests en verde (18 archivos,
  +9); `npm run build` → OK (warning de tamaño de chunk, ahora mayor por Recharts —
  costo esperado de la primera librería de gráficos).
  **Dependencia:** ramificado de `origin/develop` y mergeado
  `origin/feature/frontend-sesion-13-comentarios-alcance` (sin conflictos) antes de
  implementar, ya que la Sesión 13 aún no estaba en `develop`.
- [x] **Sesión 15** — Perfil de grupo en la UI (`27-frontend-perfil-de-grupo.md`)
  Resumen: tres secciones nuevas en `GroupTrackingPage` (no una pantalla nueva),
  consumiendo los tres agregadores del backend Sesión 11: **"Ajustes activos"**
  (`GroupAccommodationsSummary`, tabla `type`/`category`/`student_count` **sin gate
  de rol** — el endpoint es agregado y nunca nombra alumnos); **"Desempeño del grupo"**
  (`GroupPerformanceChart`, reusa el patrón Recharts de la Sesión 14 y la paleta
  `performanceMarkColors`, línea de `average_score` y tooltip con `results_count`,
  ej. "7.4 (22 de 25 alumnos)"); y **"Seguimientos vencidos"** (`GroupOverdueFollowUps`,
  `?overdue=true`, cada fila linkea a `/alumnos/{student_id}/seguimiento`). Tipos nuevos
  en `types.ts` (`GroupPerformanceMark` con **cinco** variantes — sin
  `accommodation_instance_override`, todas conteos agregados por `(tipo, fecha)`, ningún
  `student_id`) y tres funciones de API (contratos verificados contra los controladores
  reales: `accommodations-summary` y `performance-timeline` devuelven objeto plano sin
  `{ data }`; `scheduled-follow-ups` sí va envuelto). Verificación desde `web/` (`npm ci`):
  `npm run lint` → 0 errores (solo el warning preexistente de `button.tsx`);
  `npm run typecheck` → OK; `npm run test -- --run` → 170 tests en verde (21 archivos, +11);
  `npm run build` → OK.
  **Dependencia:** ramificado de `origin/develop` y mergeado
  `origin/feature/frontend-sesion-14-perfil-de-alumno` (sin conflictos, trae el setup de
  Recharts) antes de implementar, ya que la Sesión 14 aún no estaba en `develop`.
- [ ] **Sesión 16** — Columna agregada en Grupos, UI (`28-frontend-grupos-listado.md`)
  Resumen:
- [ ] **Sesión 17** — Pruebas de sondeo en la UI (`12-frontend-pruebas-de-sondeo.md`)
  Resumen:

## 5. Explícitamente fuera de este plan

No hay sesiones de frontend todavía para (ver también "Out of scope for now" de `CLAUDE.md`):

- El asistente de IA docente (`05-asistente-ia-docente.md`) — el backend ya está mergeado (ver `06-plan-de-sesiones.md`, Sesión 5), pero su UI (generar/revisar/aplicar una `AIProposal`) todavía no se planificó como sesión de frontend. Cuando se encare, se agrega `18-frontend-asistente-ia-docente.md` siguiendo este mismo formato.
- Gestión de usuarios (crear/editar/desactivar `User` por parte de un `director`) — no hay endpoint de backend (`UserController`) para eso todavía, aunque la matriz de permisos de `02-roles-permisos.md` lo mencione. Ver `07-frontend-roles-permisos.md` §4.
- Cualquier gráfico real para el panel de adopción (`weekly_login_series`/`weekly_content_series`) — se muestran como tablas simples en la Sesión 8. La Sesión 14 agrega Recharts para Perfil de alumno; reusarla acá es una mejora futura razonable, pero no está en el alcance de ninguna sesión planificada todavía.
- Integración con SIGED, billing/entitlements, Boletín/Indicador de Progreso/Proyecto, Comunicaciones, currícula/planificación (`AnnualPlan`/`Unit`/`ClassSession` UI) — mismos motivos que en el plan de backend (`CLAUDE.md` §"Out of scope for now").

Cuando se encare alguno de estos frentes, generamos el prompt de frontend correspondiente siguiendo el mismo formato.
