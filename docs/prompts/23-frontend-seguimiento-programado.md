# Sesión 11 (frontend) — Seguimiento programado en la UI

**Depende de:** Sesión 7 (frontend, `permissions.ts`), Sesión 8 (frontend,
convenciones de `features/tracking/*Api.ts` y `StudentTrackingPage`), y del
backend de `docs/prompts/17-seguimiento-programado.md` (Sesión 7 backend) ya
mergeado.
**Bloquea a:** nada directamente, pero la Sesión 15 (frontend, "Perfil de
grupo") consume el agregado de seguimientos vencidos del grupo
(`GET /groups/{group}/scheduled-follow-ups`, `21-perfil-de-grupo.md` §4) — no
reinventar ahí los tipos/labels que agrega esta sesión, reusarlos.
**Contexto persistente:** ya cargado desde `CLAUDE.md`.

## Objetivo

UI para crear, listar y resolver un `ScheduledFollowUp` de un alumno — la
vista concreta de la regla "nada vence solo, alguien lo programó"
(`17-seguimiento-programado.md` §Objetivo). Se agrega **dentro de
`StudentTrackingPage`**, no como pantalla nueva: es una sección más de esa
vista, igual que alertas/adaptaciones/barreras ya lo son.

## 1. Contrato de API

Verificar contra el código real del backend de la Sesión 7 antes de tipar —
no asumir nombres de campo sin confirmarlos (misma práctica que
`09-frontend-flujos-aprobacion-trazabilidad.md` §1).

| Método | Ruta | Body / Query | Notas |
|---|---|---|---|
| GET | `/api/v1/students/{student}/scheduled-follow-ups` | `?resolved=false` opcional | Sin el query param devuelve todos (resueltos y pendientes) — el filtro por defecto es decisión del cliente, el backend no lo impone |
| POST | `/api/v1/students/{student}/scheduled-follow-ups` | `{ description, due_date }` | |
| POST | `/api/v1/scheduled-follow-ups/{followUp}/resolve` | `{ resolution_note? }` | |

## 2. Tipos nuevos (`web/src/types.ts`)

```ts
export interface ScheduledFollowUp {
  id: number
  student_id: number
  description: string
  due_date: string
  created_by_id: number
  resolved: boolean
  resolved_by_id: number | null
  resolved_at: string | null
  resolution_note: string | null
  /**
   * Calculado en el servidor: `!resolved && due_date <= hoy`, en la zona
   * horaria de la app. Nunca recalcular esta condición en el cliente.
   */
  is_overdue: boolean
}
```

## 3. Permisos (`web/src/lib/permissions.ts`)

No se agrega ningún helper nuevo. `ScheduledFollowUpPolicy::create` /
`view` / `resolve` (backend) son las tres `$user->teachesStudent($student) ||
$user->hasAnyRole(Role::schoolWideValues())` — es decir, **cualquiera de los
tres roles puede crear/ver/resolver si tiene relación con el alumno**; no hay
un rol que quede afuera para poder discriminar algo útil en el cliente (a
diferencia de, por ejemplo, `canApproveAccommodation`, exclusivo de roles
school-wide). Igual que `CommentsPanel.canComment` (que por defecto es
`true`), el formulario de nuevo seguimiento y el botón "Resolver" se
muestran siempre que la sección sea visible — el límite real es el
Controller, que rechaza con 403 a un teacher que no dicta al alumno. No
inventar acá un helper que no discrimina nada.

## 4. `ScheduledFollowUpsPanel` (`web/src/features/tracking/ScheduledFollowUpsPanel.tsx`)

Componente reutilizable (mismo patrón que `CommentsPanel`), embebido en
`StudentTrackingPage` como una sección nueva ("Seguimientos programados"):

- Toggle "Mostrar resueltos" (por defecto solo pendientes, pidiendo
  `?resolved=false`); al togglear, vuelve a pedir el endpoint con el query
  param correspondiente.
- Formulario "Nuevo seguimiento": `description` (textarea) + `due_date`
  (input `type="date"`). Al guardar, agrega el seguimiento devuelto a la
  lista local (no hace falta un refetch completo).
- Lista de seguimientos: cada fila muestra `description`, `due_date`, y un
  badge "Vencido" (rojo) cuando `is_overdue`, pintado directamente desde el
  booleano del servidor — el componente no calcula fechas.
- Por cada seguimiento no resuelto: botón "Resolver" que pide
  opcionalmente `resolution_note` antes de confirmar (reusar `ConfirmDialog`
  con un campo de texto extra si alcanza, o un formulario inline simple — es
  decisión del implementador, no duplicar un diálogo de confirmación nuevo
  si `ConfirmDialog` ya sirve). Al confirmar, `POST .../resolve` y
  actualizar esa fila en el estado local con la respuesta — mismo criterio
  de "usar la respuesta, no refetch" que `StudentTrackingPage` ya aplica
  para accommodations (acá no hay caché de 60s de por medio, pero se
  mantiene la misma convención por consistencia y para evitar un request
  extra).
- Seguimientos ya resueltos (visibles solo con "Mostrar resueltos" activo) se
  muestran de solo lectura: `resolved_at`, `resolved_by_id` (mostrar
  "Usuario #N" — no hay endpoint que resuelva nombres desde un id suelto,
  mismo criterio que `StudentHistoryPage` con `user_id`) y `resolution_note`
  si existe.

## 5. `web/src/features/tracking/scheduledFollowUpsApi.ts` (archivo nuevo)

```ts
export async function fetchScheduledFollowUps(
  studentId: number,
  resolved?: boolean,
): Promise<ScheduledFollowUp[]>

export async function createScheduledFollowUp(
  studentId: number,
  input: { description: string; due_date: string },
): Promise<ScheduledFollowUp>

export async function resolveScheduledFollowUp(
  id: number,
  resolutionNote?: string,
): Promise<ScheduledFollowUp>
```

Mismo envoltorio `{ data: ... }` que el resto de `trackingApi.ts`. Archivo
separado (no agregar a `trackingApi.ts`) porque ese archivo ya concentra
comentarios, alertas, aprobación y barreras — separar por dominio en vez de
seguir agrandando un solo archivo, mismo criterio que dejó abierto
`09-frontend-flujos-aprobacion-trazabilidad.md` §3.

## 6. Tests

- El formulario de nuevo seguimiento envía `{ description, due_date }` y
  agrega la fila devuelta sin refetch.
- El toggle "Mostrar resueltos" cambia el query param de la request
  (`resolved=false` vs. sin query param).
- El badge "Vencido" se pinta cuando `is_overdue === true` y no aparece
  cuando es `false` — test que confirma que el componente no calcula nada,
  solo lee el campo.
- "Resolver" llama al endpoint con `resolution_note` cuando se completa, y
  sin el campo cuando se deja vacío (nunca manda `resolution_note: ""`).
- Un seguimiento ya resuelto no muestra el botón "Resolver".

## 7. Criterios de aceptación

- [ ] Crear, listar (con filtro pendientes/todos) y resolver un seguimiento
      funciona end-to-end contra el backend de la Sesión 7.
- [ ] El badge de vencido usa `is_overdue` del servidor, nunca una fecha
      calculada en el cliente.
- [ ] `npm run lint`, `npm run typecheck`, `npm run test`, `npm run build`
      pasan (desde `web/`).
