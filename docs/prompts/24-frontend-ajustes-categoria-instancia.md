# Sesión 12 (frontend) — Categoría de ajuste y desactivar por instancia en la UI

**Depende de:** Sesión 7 (frontend, `permissions.ts`), Sesión 8 (frontend,
convenciones de `StudentTrackingPage`/`trackingApi.ts`), Sesión 9 (frontend,
`BarrierAccommodationsPanel` como precedente de panel expandible on-demand),
Sesión 10 (frontend, `14-frontend-evaluaciones-resultados.md` —
`recent_assessments` del alumno, que esta sesión reusa para el selector de
instancia), y del backend de `docs/prompts/18-ajustes-categoria-instancia.md`
(Sesión 8 backend) ya mergeado.
**Bloquea a:** Sesión 14 (frontend, "Perfil de alumno") — el gráfico de esa
sesión marca las desactivaciones por instancia que se crean acá.
**Contexto persistente:** ya cargado desde `CLAUDE.md`.

## Objetivo

Dos piezas puntuales sobre `Accommodation`, igual de acotadas que su
contraparte backend (`18-ajustes-categoria-instancia.md`):

1. **`category` en el formulario de adaptación** — y, como consecuencia
   directa de un hallazgo de la Sesión 8 backend, el formulario de
   creación/edición de `Accommodation` en sí, que **hoy no existe en el
   frontend en absoluto**: `StudentTrackingPage` solo lista adaptaciones ya
   cargadas (por seed/factory) y permite aprobar/rechazar/vincular a una
   barrera, pero no hay ningún botón "Nueva adaptación" ni "Editar". El
   backend de la Sesión 8 agregó recién el endpoint de creación/edición que
   faltaba — esta sesión es la que le pone UI.
2. **Desactivar un ajuste para una evaluación puntual**
   (`AccommodationInstanceOverride`) — una acción nueva sobre cada
   adaptación vigente.

## 1. Contrato de API

Verificar contra el código real del backend de la Sesión 8 antes de tipar.

| Método | Ruta | Body | Notas |
|---|---|---|---|
| POST | `/api/v1/students/{student}/accommodations` | `{ type, description, focus_area, category, requires_external_approval, active }` | `category` requerido |
| PATCH | `/api/v1/accommodations/{accommodation}` | igual forma, parcial | |
| POST | `/api/v1/accommodations/{accommodation}/instance-overrides` | `{ assessment_id, reason }` | Solo el teacher dueño del `Assessment` elegido — el backend devuelve 403 si no lo es; el frontend no puede verificar esto de antemano (ver §4) |

## 2. Tipos nuevos/actualizados (`web/src/types.ts`)

```ts
export type AccommodationCategory = "access" | "content" | "criteria"

export const accommodationCategoryLabels: Record<AccommodationCategory, string> = {
  access: "Acceso",
  content: "Contenido",
  criteria: "Criterio",
}
```

Agregar `category: AccommodationCategory` a la interfaz `Accommodation` ya
existente en `types.ts` (Sesión 8, seguimiento institucional) — es un campo
nuevo del mismo recurso, no una interfaz separada.

```ts
export interface AccommodationInput {
  type: string
  description: string
  focus_area: string
  category: AccommodationCategory
  requires_external_approval: boolean
  active: boolean
}
```

No se agrega un tipo `AccommodationInstanceOverride` a `types.ts`: esta
sesión solo **crea** overrides (formulario de una vía), no los lista en
ninguna pantalla — el primer consumo de lectura de esa entidad es el gráfico
de la Sesión 14 (`performance-timeline`), que ya trae su propio tipo de
marca. Repetir la interfaz acá sin un componente que la use sería
especular.

## 3. Permisos (`web/src/lib/permissions.ts`)

```ts
export function canManageAccommodations(user: User | null | undefined): boolean {
  return canAccessClinicalProfile(user)
}
```

**Nota de diseño (asumida, no un contrato exacto del backend):**
`AccommodationPolicy::create` en el backend en realidad permite crear a
*cualquier* rol autenticado (`Role::values()`, sin distinción), y
`AccommodationPolicy::update` solo exige compartir colegio (sin restricción
de rol) — más permisivo que esto. El frontend restringe la UI de
creación/edición a los mismos roles que ya pueden **ver** adaptaciones
(school-wide, `canAccessClinicalProfile`) por dos razones prácticas: (a) es
donde ya vive la sección de adaptaciones en `StudentTrackingPage` — no tiene
sentido ofrecer "crear una adaptación" a un viewer que ni siquiera ve las
existentes; (b) es consistente con la regla de seguridad 11 de `CLAUDE.md`
("enforce field-level access by role"), aunque el backend no la exija a este
nivel de estrictez. Documentar esto como decisión de UX, no como
descubrimiento de un bug — no es tarea de esta sesión endurecer la Policy.

## 4. Formulario de adaptación (`web/src/features/tracking/AccommodationFormDialog.tsx`, nuevo)

Diálogo modal (mismo patrón que `StudentFormPage`/`GroupFormPage`:
`Dialog`/`DialogContent` + `react-hook-form` + Zod), abierto desde
`StudentTrackingPage`:

- Botón "Nueva adaptación" en la sección de adaptaciones, visible solo si
  `canManageAccommodations(user)`.
- Por cada adaptación ya listada, un botón "Editar" con la misma
  visibilidad.
- Campos: `type` (texto libre, como hoy en el modelo), `description`,
  `focus_area`, `category` (`<Select>` con las tres opciones y sus labels en
  español), `requires_external_approval` (checkbox), `active` (checkbox,
  solo visible en edición — al crear una adaptación nueva no tiene sentido
  ofrecer crearla ya inactiva).
- Al guardar, refresca la sección de adaptaciones de `StudentTrackingPage`
  (refetch de `tracking` completo alcanza acá — a diferencia del
  aprobar/rechazar de la Sesión 9, esta acción no compite con el caché de
  60s porque es una escritura de un campo que no cambia por otro camino).

## 5. Desactivar por instancia (`AccommodationInstanceOverrideForm`, dentro de la sección de adaptaciones)

Por cada adaptación vigente (`is_effective === true`) en
`StudentTrackingPage`, un botón "Desactivar para una evaluación" — visible
con la misma condición que el resto de la sección (`canAccessClinicalProfile`,
ver §3), que abre un formulario chico:

- `<select>` de evaluación: poblado con `tracking.recent_assessments` (ya
  presente en el `StudentTracking` que trae la página, `AssessmentSummary[]`
  — no hace falta un fetch nuevo).
- `reason` (textarea, obligatorio).
- Al enviar, `POST /accommodations/{accommodation}/instance-overrides`.

**Nota de diseño importante:** el backend solo permite esta acción al
*teacher dueño de la evaluación elegida* (`AssessmentPolicy::isOwner`), no a
cualquier rol school-wide — así que en la práctica esta acción solo tiene
éxito para un usuario que sea simultáneamente (a) capaz de ver la sección de
adaptaciones (`canAccessClinicalProfile`, hoy solo director/psicopedagogía)
y (b) el teacher dueño de esa evaluación puntual. Esto puede parecer
contradictorio (un `teacher` normalmente no pasa (a)), pero `roles` es un
array en `User` — nada impide que un usuario tenga más de un rol asignado
(ej. alguien con rol `teacher` y `psychopedagogue` a la vez). Mismo criterio
ya aceptado en `BarrierAccommodationsPanel` (`canProposeBarrierAccommodation`
incluye `teacher`, pero el `<select>` que arma esa acción usa
`tracking.accommodations`, que solo llega al cliente si el viewer ya tiene
`view-clinical-profile`): el frontend muestra la acción cuando puede armar la
UI con los datos que ya tiene, y deja que el backend haga el chequeo de
ownership real, devolviendo un error legible si falla (no se puede evitar el
403 de antemano sin un endpoint de "assessments que dicto" que no existe).
No es un bug de esta sesión ni de la 18 backend — es una limitación conocida
del modelo de permisos actual, documentada igual que la de la Sesión 9.

## 6. Tests

- El formulario de nueva adaptación exige `category` (no se puede enviar sin
  elegir una).
- Un usuario sin `canManageAccommodations` no ve "Nueva adaptación" ni
  "Editar" en ninguna adaptación.
- "Desactivar para una evaluación" no aparece en una adaptación con
  `is_effective === false`.
- El `<select>` de evaluación en el formulario de desactivación usa
  `tracking.recent_assessments`, no hace un fetch adicional.
- Un error 403 al desactivar (el usuario no es el teacher dueño de la
  evaluación elegida) se muestra como mensaje legible, no como una excepción
  sin manejar.

## 7. Criterios de aceptación

- [ ] Crear y editar una `Accommodation` (incluyendo `category`) funciona
      end-to-end contra el endpoint nuevo de la Sesión 8 backend.
- [ ] Desactivar un ajuste para una evaluación puntual funciona end-to-end,
      con manejo explícito del caso de error (usuario no es el dueño).
- [ ] `npm run lint`, `npm run typecheck`, `npm run test`, `npm run build`
      pasan (desde `web/`).
