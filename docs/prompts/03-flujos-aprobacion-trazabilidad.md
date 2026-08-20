# Sesión 3 — Flujos de aprobación y trazabilidad de autoría

**Depende de:** Sesión 1 (modelo de dominio), Sesión 2 (roles y permisos).
**Bloquea a:** Sesión 4 (seguimiento institucional la usa para comentarios/alertas) y Sesión 5 (el asistente de IA registra autoría sobre lo que genera).
**Contexto persistente:** ya cargado desde `CLAUDE.md`.

## Objetivo

Esta sesión construye la infraestructura transversal de **quién hizo qué, cuándo, y quién lo validó** — es plomería que después usan las sesiones 4 y 5. Se hace ahora, antes de las features de negocio, para no tener que retrofittear auditoría sobre entidades ya construidas.

## 1. Trait genérico de autoría

Crear `App\Models\Concerns\Auditable`, aplicable a cualquier modelo:

- Agrega automáticamente (via `booted()`) el registro de un evento en la tabla `audit_logs` en los eventos `created`, `updated`, `deleted`.
- `audit_logs` (tabla polimórfica):

| columna | tipo | notas |
|---|---|---|
| id | (misma estrategia de PK que el resto del repo) | |
| school_id | FK | para poder filtrar por tenant sin joins |
| auditable_type | string | clase del modelo |
| auditable_id | — | |
| action | enum: `created`,`updated`,`deleted` | |
| user_id | FK, nullable → users | quién hizo la acción (null si fue un job del sistema, ej. el asistente de IA aplicando una propuesta autogenerada — dejar constancia de esto con `origin` en vez de forzar un usuario falso) |
| origin | enum: `user`,`system` | default `user` |
| changes | jsonb | diff simplificado: `{field: {before, after}}` para `updated`; snapshot completo para `created`/`deleted` |
| created_at | timestamp | |

- No guardar diffs de campos marcados como sensibles-en-texto-plano si el modelo lo indica (por ejemplo, no hace falta guardar el contenido completo de `TechnicalReport.summary` en el diff, alcanza con marcar que cambió — usar una property estática `$auditableExcludeFromDiff = ['summary', 'document_url']` por modelo).
- Aplicar `Auditable` a: `Accommodation`, `Barrier`, `TechnicalReport`, `Student`, `Group`, `AnnualPlan`.

## 2. Autoría explícita (creador/eliminador)

Estos campos ya existen desde la sesión 1 en `Accommodation` y `Barrier` (`created_by_id`, `deleted_by_id`). En esta sesión:

- Crear un trait `App\Models\Concerns\TracksAuthorship` que, en el `creating()`, setea `created_by_id = auth()->id()` automáticamente si no viene seteado, y en el soft-delete (`deleting()`), setea `deleted_by_id = auth()->id()`.
- Aplicar a `Accommodation` y `Barrier`.
- `TechnicalReport.uploaded_by_id` se setea igual, aunque no tenga campo de eliminador (no tiene soft delete por ahora — borrarlo requiere intervención manual, no endpoint de delete; no crear la ruta DELETE para este recurso).

## 3. Flujo de aprobación: Accommodation

`Accommodation` ya tiene `requires_external_approval` (bool) y `approved` (bool, nullable) desde la sesión 1.

- Endpoint `POST /api/v1/accommodations/{id}/approve`:
  - Autorización: Policy `AccommodationPolicy@approve` — solo `director` o `psychopedagogue` (definido en sesión 2).
  - Precondición: `requires_external_approval === true` y `approved === null`. Si no se cumple, devolver 422 con mensaje claro.
  - Efecto: setea `approved = true`, registra en `audit_logs` con `action = updated`.
- Endpoint `POST /api/v1/accommodations/{id}/reject`: mismo esquema, setea `approved = false`.
- Una `Accommodation` con `requires_external_approval = true` y `approved = null` (o `false`) no debe considerarse `active` a efectos de otras features, aunque el campo `active` sea independiente en la DB — agregar un accessor `isEffective(): bool` que combine ambos (`active && (!requires_external_approval || approved === true)`) y usarlo en cualquier lugar donde se necesite saber si una accommodation aplica de verdad.

## 4. Flujo de validación: Barrier ↔ Accommodation

Pivot `barrier_accommodation` (ya creado en sesión 1: `proposed_by_id`, `validated`, `validated_by_id`).

- Endpoint `POST /api/v1/barriers/{barrier}/accommodations` — vincula una accommodation existente a una barrier. Body: `{accommodation_id}`. Setea `proposed_by_id = auth()->id()`, `validated = false`.
- Endpoint `POST /api/v1/barriers/{barrier}/accommodations/{accommodation}/validate`:
  - Autorización: `director` o `psychopedagogue`.
  - **Regla de cuatro ojos**: el usuario que valida no puede ser el mismo que propuso (`proposed_by_id !== auth()->id()`). Si coincide, 422.
  - Efecto: `validated = true`, `validated_by_id = auth()->id()`.
- Endpoint `GET /api/v1/barriers/{barrier}/accommodations` — lista con estado de validación.

## 5. Endpoint de auditoría (solo lectura)

- `GET /api/v1/students/{student}/history` — devuelve la línea de tiempo de `audit_logs` para todo lo relacionado a ese alumno (Accommodations, Barriers, TechnicalReports asociados), ordenado por fecha descendente. Autorización: mismas reglas que ver el perfil clínico completo del alumno (`director`, `psychopedagogue` — ver sesión 2).
- Paginar (cursor o page, tu criterio, pero documentarlo).

## 6. Notificaciones (stub, no implementar canal real todavía)

- Crear una interfaz `App\Contracts\StatusChangeNotifier` con un único método `notify(string $event, array $context): void`.
- Implementación por defecto `LogNotifier` que solo hace `Log::info()` (sin PII sensible, solo IDs y tipo de evento — regla 2 y 11 del `CLAUDE.md`).
- Invocarla en: aprobación/rechazo de Accommodation, validación de Barrier↔Accommodation.
- No implementar email/push todavía — cuando se decida el canal real solo hace falta una nueva implementación de la interfaz.

## 7. Tests

- Test: crear/actualizar/eliminar una `Accommodation` genera el registro de auditoría correspondiente con el diff correcto.
- Test: `created_by_id` se setea automáticamente al crear una `Barrier`, sin necesidad de pasarlo en el request.
- Test: aprobar una `Accommodation` que no requiere aprobación externa devuelve 422.
- Test: la regla de cuatro ojos en validación de Barrier↔Accommodation (mismo usuario no puede proponer y validar) devuelve 422.
- Test: `isEffective()` devuelve `false` para una accommodation con `requires_external_approval = true` y `approved = null`, y `true` una vez aprobada.
- Test: `GET /students/{id}/history` devuelve 403 para un `teacher` sin relación al alumno.

## 8. Criterios de aceptación

- [ ] `Auditable` y `TracksAuthorship` implementados y aplicados a las entidades listadas.
- [ ] Endpoints de aprobación/rechazo/validación funcionando con las reglas de autorización y de cuatro ojos.
- [ ] `isEffective()` implementado y usado consistentemente.
- [ ] Endpoint de historial funcionando y paginado.
- [ ] Todos los tests de la sección 7 en verde.
- [ ] `./vendor/bin/sail test` pasa en verde.
