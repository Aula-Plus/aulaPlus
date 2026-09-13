# Sesión 12 (backend) — Columna agregada en el listado de grupos

**Depende de:** Sesión 4 (`Accommodation`, `Barrier`, `Alert`), Sesión 7
(`ScheduledFollowUp`).
**Bloquea a:** Sesión 16 (frontend, "Grupos").
**Contexto persistente:** ya cargado desde `CLAUDE.md`. Ver también
`aulaplus-documento-vivo/documento_aulaplus.html`, pantalla 2 ("Grupos"),
decisión "Línea roja".

## Objetivo

Sesión chica: agregar al listado de grupos (`GET /api/groups`, ya
existente — **sin** prefijo `v1`: la ruta base de `groups` se declaró en la
Sesión 1 vía `Route::apiResource('groups', GroupController::class)`, fuera
del grupo `Route::prefix('v1')` que agregaron las Sesiones 3+; verificar
`routes/api.php` antes de cablear el frontend contra la ruta equivocada)
cuántos alumnos del grupo tienen seguimiento activo — agregado, nunca
nómina, visible para cualquier rol que vea el listado (docente incluido).

## 1. Definición de "seguimiento activo"

Un alumno cuenta si tiene **al menos uno** de:

- Una `Accommodation` con `isEffective() === true`.
- Una `Barrier` con `active === true`.
- Una `Alert` con `resolved === false`.
- Un `ScheduledFollowUp` con `resolved === false` (Sesión 7).

## 2. Cambio en `GroupController::index`

Calcular `active_tracking_count` por grupo en **una sola consulta agregada**
por tabla (cuatro consultas totales, no una por grupo — `Group::query()` ya
trae todos los grupos visibles de una vez, no reintroduzcas N+1 acá).
Agregar a `GroupResource`:

```php
'active_tracking_count' => $this->active_tracking_count, // int
```

Sugerencia de forma de cálculo: por cada una de las cuatro tablas, un query
`GROUP BY group_id` (vía la relación `group_student`) que cuente
`student_id` distintos con la condición correspondiente, después unir los
sets en PHP por `group_id` (unión de alumnos, no suma de conteos — un
alumno con accommodation Y alerta abierta cuenta una sola vez).

La condición de `Accommodation` no es una columna simple: `isEffective()` es
`active && (!requires_external_approval || approved === true)` (método PHP en
el modelo, `app/Models/Accommodation.php`) — para el query agregado hay que
replicar esa misma condición en el `WHERE` SQL
(`active = true and (requires_external_approval = false or approved = true)`),
no llamar `$accommodation->isEffective()` fila por fila, que reintroduciría
el N+1 que esta sesión busca evitar.

## 3. Tests

- Un alumno con accommodation efectiva Y alerta abierta cuenta una sola vez
  en `active_tracking_count` del grupo (no dos).
- Un grupo sin ningún alumno en seguimiento da `0`, no `null` ni ausente.
- No hay N+1: test que cuenta queries (`assertQueryCount` o equivalente) con
  varios grupos cargados.
- Cualquier rol (incluido `teacher`) recibe la columna en `GET /groups`.

## 4. Criterios de aceptación

- [ ] `active_tracking_count` en `GET /api/v1/groups`, agregado y sin
      exponer nombres.
- [ ] Sin N+1 (verificado con test).
- [ ] Todos los tests de la sección 3 en verde; `./vendor/bin/sail test`
      pasa completo.
- [ ] `sail bin pint` limpio.
