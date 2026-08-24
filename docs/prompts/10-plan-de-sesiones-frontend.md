# Plan de sesiones — Frontend Aula+

Guía de las corridas de implementación del frontend (React + Vite + TS), en el
mismo formato que `06-plan-de-sesiones.md` (que cubre la API). Cada sesión asume
que la anterior está mergeada y que `npm run lint`/`typecheck`/`test`/`build`
pasan antes de arrancar la siguiente.

> **Nota (Sesión 7):** este archivo se creó durante la Sesión 7 porque no
> existía en el repo, igual que el spec `07-frontend-roles-permisos.md` al que
> apuntaba el prompt de la corrida — ninguno de los dos estaba versionado. La
> Sesión 7 se implementó a partir del enunciado de la tarea + el código real
> (las Policies del backend y las páginas existentes), no de un markdown de
> spec. Si más adelante se genera el set formal de prompts de frontend, conviene
> reconciliar la numeración de sesiones de abajo.

## 1. Cómo correr cada sesión

Por sesión: rama `feature/frontend-sesion-0N-<nombre-corto>` desde `develop`,
contexto limpio (`CLAUDE.md` se carga solo), y al cierre correr los cuatro
comandos desde `web/`, commits descriptivos, resumen acá y PR contra `develop`
(nunca merge propio a `develop`/`main`).

## 4. Checklist de progreso

Marcar acá a medida que cada sesión se completa y mergea. 3-5 líneas de resumen
por sesión (qué se hizo, qué quedó pendiente/asumido, discrepancias contra el
backend, resultado real de lint/typecheck/test/build).

- [x] **Sesión 7** — Consolidación de roles y permisos en la UI
  Resumen: Se creó `web/src/lib/permissions.ts` como única fuente de los
  chequeos de rol de la UI y se reemplazaron los checks ad-hoc
  (`roles.includes`/`roles.some`) de `GroupsListPage`, `StudentsListPage` y
  `StudentFormPage`. Los predicados se auditaron contra el código real de
  `GroupPolicy`/`StudentPolicy` (no la matriz de `02-roles-permisos.md`):
  grupos crear/editar → solo director; alumnos crear/editar → school-wide;
  alumnos eliminar → **solo director** (más restrictivo, ya coincidía en el
  front); perfil clínico → school-wide. No se encontró discrepancia de
  comportamiento: el frontend previo ya respetaba las Policies, así que el
  cambio es de consolidación, no de corrección. Cada helper documenta que es
  capa de UX (el límite de seguridad son las Policies del server) y que los
  chequeos de tenancy/ownership por registro (`sharesSchool`, `teachesGroup`,
  `teachesStudent`) quedan del lado del servidor porque el front no tiene esos
  datos. Fuera de alcance (respetado): UI de gestión de usuarios (no hay
  endpoint) y catálogo curricular. Assumido/discrepancia de infraestructura: el
  spec `07-frontend-roles-permisos.md` y este mismo archivo no existían en el
  repo — se procedió desde el enunciado + el código. Verificación real desde
  `web/`: `npx oxlint` → 0 errores (1 warning preexistente en `button.tsx`,
  ajeno a este cambio); `npm run typecheck` → OK; `npm run test -- --run` →
  49/49 en verde (incluye 10 tests nuevos de `permissions.test.ts`);
  `npm run build` → OK (warning de tamaño de chunk preexistente).

- [x] **Sesión 8** — Seguimiento institucional en la UI
  Resumen: Se agregó la capa de UI sobre la API de seguimiento (backend
  Sesión 4): tipos en `types.ts` (Comment/Alert/Accommodation/Barrier +
  StudentTracking/GroupTracking/AdoptionDashboard con labels en español),
  `features/tracking/trackingApi.ts` y `adoptionApi.ts` (endpoints `/api/v1`),
  el componente reutilizable `CommentsPanel` y tres pantallas
  (`StudentTrackingPage`, `GroupTrackingPage`, `AdoptionDashboardPage`),
  ruteadas y enlazadas desde las listas + una entrada de nav "Adopción"
  solo-director. Regla clave de `visible_to` (§3): si no se elige ningún rol,
  el campo se **omite** (nunca `[]`, que ocultaría el comentario incluso al
  autor) — con test dedicado. Sin librería de gráficos nueva: el tablero de
  adopción usa tablas simples. Los helpers `canViewAdoptionDashboard`/
  `canResolveAlert` se sumaron a `permissions.ts` (capa de UX; el límite de
  seguridad son las Policies del server, que además re-chequean tenancy y el
  filtrado clínico/`visible_to` por request). **Apilado sobre
  `feature/frontend-sesion-07-roles-permisos`** (la Sesión 7 no está mergeada
  a `develop` y esta sesión depende de su `permissions.ts`): comparar el PR
  contra esa rama, no contra `develop`. El spec formal
  `08-frontend-seguimiento-institucional.md` no existe en el repo (igual que
  el `07-*` — ver nota de arriba); se implementó desde el enunciado de la
  tarea + el contrato real del backend (Resources/Controllers de Sesión 4) +
  las convenciones del frontend existente. Nada quedó pendiente: las 4
  pantallas/componente del enunciado están completas (no hizo falta partir en
  8a/8b). Verificación real desde `web/`: `npx oxlint` → 0 errores (mismo
  warning preexistente en `button.tsx`); `npm run typecheck` → OK;
  `npm run test -- --run` → 65/65 en verde (16 tests nuevos: `CommentsPanel`,
  las tres páginas y 2 de `permissions`); `npm run build` → OK (mismo warning
  de tamaño de chunk preexistente).

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
  disclosure por fila que imprime `changes` como JSON. Nuevos helpers en
  `permissions.ts`: `canApproveAccommodation` (school-wide, mirror de
  `AccommodationPolicy::approve`), `canProposeBarrierAccommodation`
  (teacher/psychopedagogue — **director deliberadamente excluído** por
  `BarrierPolicy::update`), `canValidateBarrierAccommodation(user,
  proposerId)` (school-wide + cuatro ojos), y `canViewStudentHistory`
  (school-wide). `Accommodation.approved` pasa a `boolean | null` (antes
  estaba tipado como `boolean` a secas, incorrecto según el ResourceJSON).
  El spec `09-frontend-flujos-aprobacion-trazabilidad.md` sí existía en
  `origin/main` (commit `5090014`) — se trajo intacto a la rama; el `10-*`
  de main es una versión inicial del checklist (ver §4 de este archivo) y
  se dejó la versión completa que ya venía de las Sesiones 7/8 en lugar de
  sobreescribirla. **Apilado sobre
  `feature/frontend-sesion-08-seguimiento-institucional`** (la Sesión 8
  todavía no está mergeada a `develop` y esta sesión depende tanto de su
  `StudentTrackingPage` como del `permissions.ts` de la 7, que la 8 apila
  primero): comparar el PR contra esa rama, no contra `develop`. Nada quedó
  pendiente; los seis criterios de aceptación del §7 están cubiertos.
  Verificación real desde `web/`: `npx oxlint` → 0 errores (mismo warning
  preexistente en `button.tsx`); `npm run typecheck` → OK;
  `npm run test -- --run` → 82/82 en verde (17 tests nuevos: 5 de
  aprobación/rechazo/vinculación/validación/historial en
  `StudentTrackingPage`, 5 de `StudentHistoryPage`, 5 de `permissions`);
  `npm run build` → OK (mismo warning de tamaño de chunk preexistente).
