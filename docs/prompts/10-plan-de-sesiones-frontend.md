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
