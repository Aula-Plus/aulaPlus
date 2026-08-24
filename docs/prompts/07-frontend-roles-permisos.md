# Sesión 7 (frontend 1) — Roles y permisos en la UI

**Depende de:** `docs/prompts/02-roles-permisos.md` (backend, ya mergeado a `develop`), y de la realineación de Grupos/Alumnos al modelo nuevo (PR #36, ya mergeado).
**Bloquea a:** Sesión 8 (frontend) y Sesión 9 (frontend) — ambas importan el módulo de permisos que se crea acá en vez de duplicar chequeos de rol.
**Contexto persistente:** ya cargado desde `CLAUDE.md`.

## Objetivo

Consolidar en un único módulo client-side el chequeo de rol que hoy está duplicado, ad-hoc, en tres componentes distintos — y auditar que cada gate de UI coincide exactamente con lo que la Policy real del backend permite. Esto es trabajo de UX únicamente: la autorización real ya vive en las Policies de Laravel (Sesión 2, mergeada) y no cambia acá — ver "Non-negotiable" en `CLAUDE.md`. Este módulo nunca debe ser la razón por la que algo es o no accesible; solo decide qué mostrar.

## 1. Estado actual (duplicación a resolver)

Hoy el chequeo de rol está repetido, con nombres distintos, en:

- `web/src/features/groups/GroupsListPage.tsx` — `isDirector = user?.roles.includes("director") ?? false`.
- `web/src/features/students/StudentsListPage.tsx` — `canManage = user?.roles.some((role) => role === "director" || role === "psychopedagogue") ?? false`.
- `web/src/features/students/StudentFormPage.tsx` — `canEditClinicalProfile` (misma regla que `canManage` de arriba, repetida) y `canDelete = user?.roles.includes("director") ?? false`.

Ninguno de los tres importa nada compartido. Antes de tocar estos archivos, leer:

- `api/app/Policies/GroupPolicy.php`
- `api/app/Policies/StudentPolicy.php`

para confirmar contra el código real (no contra la tabla de `02-roles-permisos.md`, que es la intención pero puede haber detalles de implementación, p. ej. quién puede eliminar) qué regla aplica a cada acción. Si algo en el frontend actual no coincide con la Policy (por ejemplo, `canDelete` en `StudentFormPage` restringe a `director` solo — confirmar si `StudentPolicy::delete` también permite `psychopedagogue`), corregir el frontend para que coincida con la Policy, y dejarlo anotado en el resumen de la sesión.

## 2. Módulo de permisos

Crear `web/src/lib/permissions.ts`. Funciones puras, reciben `User | null` (nunca hacen fetch ni dependen de contexto React, para que sean triviales de testear):

```ts
hasRole(user, role): boolean
hasAnyRole(user, roles): boolean
isDirector(user): boolean
isPsychopedagogue(user): boolean
isTeacher(user): boolean
isSchoolWideStaff(user): boolean        // director o psicopedagogo
canManageGroups(user): boolean          // crear/editar Group
canDeleteGroup(user): boolean
canManageStudents(user): boolean        // crear/editar Student
canDeleteStudent(user): boolean
canViewClinicalProfileUX(user): boolean // heurística de UX: si hay que mostrar
                                         // el bloque de perfil clínico en el
                                         // formulario. El campo real ya llega
                                         // ausente del backend si no corresponde
                                         // (ver StudentResource) — esto NO filtra
                                         // datos, solo decide si mostrar inputs
                                         // de edición.
```

Agregar también, aunque nada las use todavía — las Sesiones 8 y 9 las necesitan tal cual y así no se duplica la lógica de nuevo (mismo criterio que `docs/prompts/02-roles-permisos.md` §3 aplicó a `User::teachesGroup`/`teachesStudent` en el backend):

```ts
canResolveAlert(user): boolean                 // director o psicopedagogo
                                                // (AlertPolicy::resolve → StudentPolicy::viewClinicalProfile)
canApproveAccommodation(user): boolean         // director o psicopedagogo
                                                // (AccommodationPolicy::approve)
canValidateBarrierAccommodation(user, proposedById): boolean
                                                // director o psicopedagogo, Y
                                                // user.id !== proposedById (regla
                                                // de cuatro ojos — ver
                                                // BarrierAccommodationController::validateLink)
canViewStudentHistory(user): boolean           // director o psicopedagogo
                                                // (StudentHistoryController)
canViewAdoptionDashboard(user): boolean        // director
                                                // (SchoolPolicy::viewAdoptionDashboard)
```

No hace falta chequear `school_id` en ninguna de estas: el usuario del frontend solo ve datos de su propia escuela (el backend ya filtra todo vía `SchoolScope`), así que las comparaciones de `school_id` de las Policies de backend no tienen equivalente útil acá.

## 3. Reemplazar los usos existentes

- `GroupsListPage.tsx`: reemplazar `isDirector` local por `canManageGroups(user)` / `canDeleteGroup(user)` según corresponda a cada botón.
- `StudentsListPage.tsx`: reemplazar `canManage` local por `canManageStudents(user)`.
- `StudentFormPage.tsx`: reemplazar `canEditClinicalProfile` por `canViewClinicalProfileUX(user)`, y `canDelete` por `canDeleteStudent(user)`.

No cambiar el comportamiento visible salvo que la auditoría de la sección 1 haya encontrado una discrepancia real contra la Policy — en ese caso, corregir y documentarlo.

## 4. Explícitamente fuera de alcance

- **No construir UI de gestión de usuarios** (crear/editar/desactivar `User`, permiso de `director` según la matriz de `02-roles-permisos.md` §2). No existe endpoint en el backend todavía — no hay `UserController` ni ruta `/api/users` en `api/routes/api.php`. Confirmarlo (no asumir) y dejar constancia en el resumen: esta sesión no la construye porque no hay contrato de API contra el cual construirla, siguiendo el mismo criterio de `docs/prompts/06-plan-de-sesiones.md` §5 sobre no adelantarse a un backend que no existe.
- No tocar el catálogo curricular (`CurricularFramework`/`Catalog`/`Item`): es de solo lectura para cualquier usuario autenticado y hoy no tiene ninguna pantalla — no hace falta agregar gating porque no hay nada que gatear.

## 5. Tests

- `web/src/lib/permissions.test.ts`: una tabla de casos (rol → función → resultado esperado) cubriendo los tres roles para cada helper, incluyendo `user === null`.
- Actualizar `GroupsListPage.test.tsx`, `StudentsListPage.test.tsx`, `StudentFormPage.test.tsx` para que sigan pasando importando el comportamiento del helper (no deberían necesitar cambios de aserciones si el comportamiento no cambió, salvo donde la sección 1 haya corregido algo).

## 6. Criterios de aceptación

- [ ] `web/src/lib/permissions.ts` existe con las funciones de la sección 2, cada una con un comentario de una línea indicando la Policy de backend que refleja.
- [ ] Ningún componente de `web/src/features/groups` o `web/src/features/students` calcula un rol inline (`user?.roles...`) — todos importan de `permissions.ts`.
- [ ] Cualquier discrepancia encontrada entre el gating actual del frontend y la Policy real está corregida y documentada en el resumen de la sesión.
- [ ] No se agregó ninguna pantalla de gestión de usuarios.
- [ ] `npm run lint`, `npm run typecheck`, `npm run test` y `npm run build` pasan (correr desde `web/`).
