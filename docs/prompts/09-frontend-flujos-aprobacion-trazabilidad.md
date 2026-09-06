# Sesión 9 (frontend 3) — Flujos de aprobación y trazabilidad en la UI

**Depende de:** Sesión 7 (frontend, `permissions.ts`) y Sesión 8 (frontend, `StudentTrackingPage` — esta sesión agrega acciones adentro de esa pantalla, no crea una nueva). También de `docs/prompts/03-flujos-aprobacion-trazabilidad.md` (backend, ya mergeado).
**Bloquea a:** nada dentro de este set — es la última de las tres sesiones frontend planificadas por ahora.
**Contexto persistente:** ya cargado desde `CLAUDE.md`.

## Objetivo

Sobre la vista de seguimiento de alumno construida en la Sesión 8, agregar las acciones de aprobar/rechazar una `Accommodation`, vincular y validar `Barrier`↔`Accommodation` (regla de cuatro ojos), y una pantalla de historial de auditoría por alumno.

## 1. Contrato de API (verificado contra el código actual)

| Método | Ruta | Efecto |
|---|---|---|
| POST | `/api/v1/accommodations/{id}/approve` | `approved = true` (falla 422 si `approved` ya no es `null` o `requires_external_approval` es `false`) |
| POST | `/api/v1/accommodations/{id}/reject` | `approved = false` (misma precondición) |
| GET | `/api/v1/barriers/{barrier}/accommodations` | Lista de accommodations vinculadas a esa barrera, con estado de validación |
| POST | `/api/v1/barriers/{barrier}/accommodations` | Body `{ accommodation_id }` — vincula, `validated = false` |
| POST | `/api/v1/barriers/{barrier}/accommodations/{accommodation}/validate` | Falla 422 si `proposed_by_id === auth()->id()` (regla de cuatro ojos) |
| GET | `/api/v1/students/{id}/history` | Timeline de `audit_logs`, paginado con `?page=N` (paginación estándar de Laravel: `data` + `links` + `meta`) |

## 2. Tipos nuevos (agregar a `web/src/types.ts`)

```ts
export interface BarrierAccommodationLink {
  id: number              // id de la Accommodation
  type: string
  description: string
  focus_area: string | null
  proposed_by_id: number
  validated: boolean
  validated_by_id: number | null
}

export type AuditAction = "created" | "updated" | "deleted"
export type AuditOrigin = "user" | "system"

export interface AuditLogEntry {
  id: number
  auditable_type: string  // "Accommodation" | "Barrier" | "TechnicalReport"
  auditable_id: number
  action: AuditAction
  user_id: number | null  // null si origin === "system"
  origin: AuditOrigin
  changes: Record<string, { before: unknown; after: unknown }> | Record<string, unknown>
  created_at: string
}

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; total: number }
}
```

`Paginated<T>` reemplaza el sobre `{ data: T[] }` que usan el resto de los clientes de API solo para este endpoint — es el único paginado del frontend hasta ahora, así que la función del cliente de API debe devolver el objeto completo (`data` + `meta`), no solo el array.

## 3. Aprobar/rechazar Accommodation

En `web/src/features/tracking/trackingApi.ts` (o un archivo nuevo `accommodationsApi.ts` si preferís separar responsabilidades — decisión tuya, pero no dupliques el cliente axios), agregar `approveAccommodation(id)` y `rejectAccommodation(id)`.

En `StudentTrackingPage` (sección de accommodations, ya existe desde la Sesión 8): para cada accommodation donde `requires_external_approval && approved === null`, y solo si `canApproveAccommodation(user)` (de `permissions.ts`, Sesión 7), mostrar botones "Aprobar"/"Rechazar". Al confirmar, llamar el endpoint correspondiente y refrescar la vista de tracking (volver a pedir `fetchStudentTracking` — no hay necesidad de un estado optimista más sofisticado, el aggregate ya está cacheado 60s en el backend así que un refetch inmediato después de escribir puede no reflejar el cambio todavía; **usar la respuesta del propio `approve`/`reject` para actualizar solo esa accommodation en el estado local, no un refetch completo del tracking**, así se evita depender de que expire el caché del backend).

Para accommodations donde `approved` ya no es `null`, mostrar un badge de solo lectura ("Aprobada" / "Rechazada") sin botones. Para las que no requieren aprobación externa, usar el campo `is_effective` (ya existente desde la Sesión 8) para el badge de vigencia — no agregar un badge de aprobación que no aplica.

## 4. Vínculo y validación Barrier↔Accommodation

En la sección de barreras de `StudentTrackingPage` (ya existe desde la Sesión 8, listando `barriers`/`barriers_count`), agregar, para cada barrera visible (viewer con perfil clínico):

- Un panel expandible/on-demand que llama `GET /api/v1/barriers/{barrier}/accommodations` al abrirse (no precargar todas al renderizar la página — son N barreras × N accommodations, no vale la pena).
- Dentro del panel: lista de accommodations vinculadas con su estado (`validated` / `proposed_by_id`).
- Formulario "Vincular accommodation existente": un `<select>` con las accommodations **del mismo alumno** (usar el array `accommodations` que ya trae `StudentTracking` desde la Sesión 8 — no hay un endpoint de listado general de accommodations, así que las únicas conocidas por el cliente son las de ese alumno). Al enviar, `POST .../accommodations` con `{ accommodation_id }`.
- Botón "Validar" por cada vínculo no validado, visible solo si `canValidateBarrierAccommodation(user, link.proposed_by_id)` (de `permissions.ts` — ya contempla que el usuario no puede validar lo que él mismo propuso). Si el usuario es quien propuso el vínculo, no mostrar el botón en absoluto (no un botón deshabilitado con tooltip — más simple y menos superficie de error).

## 5. Historial de auditoría

`web/src/features/tracking/StudentHistoryPage.tsx`, ruta `/alumnos/:id/historial`. Link desde `StudentTrackingPage`, visible solo si `canViewStudentHistory(user)`.

- Tabla paginada: columnas fecha (`created_at`), entidad (`auditable_type`), acción (`action`), origen (`origin` — mostrar "Sistema" cuando es `system` y `user_id` es `null`, no mostrar "Usuario #null").
- Controles de paginación simples (anterior/siguiente) usando `meta.current_page`/`meta.last_page`.
- No mostrar el contenido de `changes` en la tabla principal (puede ser denso) — un botón/disclosure "Ver detalle" por fila que lo despliega como JSON formateado alcanza, no hace falta una UI de diff más elaborada.

## 6. Tests

- Un test por acción: aprobar, rechazar, vincular, validar — verificando que el botón/form llama al endpoint correcto y actualiza el estado local (no un refetch completo, según la sección 3).
- Test: el botón "Aprobar"/"Rechazar" no aparece para una accommodation con `approved !== null`.
- Test: el botón "Validar" no aparece cuando `proposed_by_id === user.id`.
- Test: `StudentHistoryPage` renderiza "Sistema" (no "Usuario #null") para una entrada con `origin: "system"`.
- Test: un usuario sin `canViewStudentHistory` no ve el link al historial.

## 7. Criterios de aceptación

- [ ] Aprobar/rechazar funciona desde `StudentTrackingPage` y respeta la precondición de estado (no se muestra el botón cuando el backend igual rechazaría la acción).
- [ ] Vincular y validar Barrier↔Accommodation funciona, incluyendo que el propio proponente no puede validar su propio vínculo desde la UI.
- [ ] `StudentHistoryPage` pagina correctamente y distingue origen `user`/`system`.
- [ ] Ningún cliente de API para este endpoint pagina "a mano" reimplementando lo que ya da `meta`.
- [ ] `npm run lint`, `npm run typecheck`, `npm run test` y `npm run build` pasan (correr desde `web/`).
