# Plan de sesiones — Frontend Aula+

Este documento no es un prompt de implementación — es la guía de cómo usar los tres anteriores (`07` a `09`) con Claude Code, en corridas nocturnas. Mismo formato que `06-plan-de-sesiones.md`, que cubre las cinco sesiones de backend.

## 0. Por qué recién ahora

`06-plan-de-sesiones.md` §5 decía explícitamente que el frontend necesitaba su propio set de prompts una vez que la API estuviera estable, para no construir UI contra un contrato que todavía se mueve. Al momento de escribir esto, las Sesiones 1 a 4 del backend (`01`-`04`) ya están mergeadas a `develop` con tests en verde — el contrato de las tres sesiones de este documento (roles/permisos, flujos de aprobación, seguimiento institucional) es estable. La Sesión 5 de backend (asistente de IA docente, `05-asistente-ia-docente.md`) **todavía no existe** — por eso no hay una sesión de frontend para eso todavía; se agrega cuando ese backend exista, siguiendo este mismo formato.

## 1. Orden de ejecución (no es opcional, y no coincide con el orden del backend)

```
07-frontend-roles-permisos.md
        ↓
08-frontend-seguimiento-institucional.md
        ↓
09-frontend-flujos-aprobacion-trazabilidad.md
```

En el backend, la sesión de "flujos de aprobación" (`03`) se hizo antes que "seguimiento institucional" (`04`). En el frontend el orden se invierte a propósito: la Sesión 8 construye la primera pantalla donde `Accommodation`/`Barrier` son visibles en la UI; sin eso, los botones de aprobar/rechazar/validar de la Sesión 9 no tendrían dónde vivir. Ver `08-frontend-seguimiento-institucional.md` §0 para el detalle. No lo reordenes de nuevo.

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

## 5. Explícitamente fuera de este plan

No hay sesiones de frontend todavía para (ver también "Out of scope for now" de `CLAUDE.md`):

- El asistente de IA docente (`05-asistente-ia-docente.md`) — su backend no existe todavía. Cuando exista, se agrega `11-frontend-asistente-ia-docente.md` siguiendo este mismo formato.
- Gestión de usuarios (crear/editar/desactivar `User` por parte de un `director`) — no hay endpoint de backend (`UserController`) para eso todavía, aunque la matriz de permisos de `02-roles-permisos.md` lo mencione. Ver `07-frontend-roles-permisos.md` §4.
- Cualquier gráfico real para el panel de adopción (`weekly_login_series`/`weekly_content_series`) — se muestran como tablas simples en la Sesión 8 porque el frontend no tiene ninguna librería de gráficos instalada; agregar una es una decisión aparte, no algo para colar dentro de estas sesiones.
- Integración con SIGED, billing/entitlements, Boletín/Indicador de Progreso/Proyecto — mismos motivos que en el plan de backend.

Cuando el backend de la Sesión 5 esté listo, generamos el prompt de frontend correspondiente siguiendo el mismo formato.
