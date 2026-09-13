# Sesión 7 (backend) — Seguimiento programado

**Depende de:** Sesión 1-4 (dominio, roles, auditoría, seguimiento).
**Bloquea a:** Sesión 11 (backend, `21-perfil-de-grupo.md` — lista los
seguimientos vencidos del grupo). También bloquea a la Sesión 11 (frontend,
este mismo módulo) y, opcionalmente, a la UI de Perfil de alumno (Sesión 14
frontend), que puede mostrar los seguimientos del alumno en su propia
sección.
**Contexto persistente:** ya cargado desde `CLAUDE.md`. Ver también
`aulaplus-documento-vivo/documento_aulaplus.html`, decisión "Regla 8" en las
pantallas 3, 6, 9, 10 y 11 ("se programa, no vence solo").

## Objetivo

El documento repite en varias pantallas la misma regla: nada "vence" solo —
lo único que existe es un seguimiento que **alguien programó**, con una
fecha, y que puede llegar a esa fecha sin que nadie lo haya resuelto. Hoy no
existe ninguna entidad para esto — `Alert` es autogenerada por el sistema
(`alerts:generate`), no algo que una persona programa a mano sobre un caso
puntual.

**Decisión de alcance (confirmada):** `ScheduledFollowUp` es genérico —
alumno + descripción libre + fecha —, sin vínculo estructural a
`Accommodation`/`Barrier` todavía. No hay que esperar a que exista PTP
(pantalla 9, fuera de alcance) para que esto sirva: cubre cualquier caso que
alguien quiera revisar en una fecha ("revisar si el ajuste de lectura sigue
haciendo falta", "confirmar con la familia el resultado de la reunión").

## 1. Entidad

`ScheduledFollowUp` (tenant-scoped con `school_id` propio, `Auditable`):

| columna | tipo | notas |
|---|---|---|
| school_id | FK | |
| student_id | FK → students | |
| description | text | qué se va a revisar — libre |
| due_date | date | |
| created_by_id | FK → users | |
| resolved | boolean, default false | |
| resolved_by_id | FK, nullable | |
| resolved_at | timestamp, nullable | |
| resolution_note | text, nullable | qué pasó al resolverlo — opcional |

`content` de auditoría: excluir `description`/`resolution_note` del diff de
`Auditable` (`$auditableExcludeFromDiff`), mismo criterio que `Comment` y
`Accommodation` — puede contener observaciones sensibles de un menor
(CLAUDE.md regla de seguridad 11).

## 2. Policy

`ScheduledFollowUpPolicy` — nueva, no reutilizar `AccommodationPolicy` (esta
entidad sí exige relación real con el alumno, a diferencia del `create`
actual de `Accommodation` que solo chequea rol):

- `create`: `$user->teachesStudent($student) || $user->hasAnyRole(Role::schoolWideValues())`.
- `view`: comparte colegio y (`teachesStudent` o school-wide) — mismo
  criterio que `create`.
- `resolve`: igual que `create` — cualquiera que pueda crear uno puede
  resolverlo, no es una aprobación de cuatro ojos.

## 3. Endpoints

| Método | Ruta | Notas |
|---|---|---|
| POST | `/api/v1/students/{student}/scheduled-follow-ups` | `{ description, due_date }` |
| GET | `/api/v1/students/{student}/scheduled-follow-ups` | `?resolved=false` por defecto en el cliente, pero el endpoint devuelve todos si no se pasa el query param — no decidir el filtro por defecto en el backend |
| POST | `/api/v1/scheduled-follow-ups/{followUp}/resolve` | `{ resolution_note? }` |

El `Resource` de listado expone `is_overdue` calculado en el servidor
(`!resolved && due_date <= today`, en la zona horaria de la app, no en el
cliente) — evita que cada consumidor del endpoint reimplemente la misma
cuenta de fechas con reglas de timezone distintas.

## 4. Tests

- Un `teacher` que no dicta al alumno no puede crear/ver/resolver (403); uno
  que sí lo dicta, puede.
- `psychopedagogue`/`director` pueden sobre cualquier alumno del colegio.
- `is_overdue` es `true` para `due_date` de ayer sin resolver, `false` para
  uno resuelto aunque la fecha ya haya pasado, `false` para uno futuro.
- Tenant-isolation estándar.
- `description`/`resolution_note` no aparecen en el diff de `AuditLog`.

## 5. Criterios de aceptación

- [ ] `ScheduledFollowUp` completo (migración, modelo, factory, Policy,
      FormRequest, Controller, tests).
- [ ] `is_overdue` calculado server-side, no en el frontend.
- [ ] Todos los tests de la sección 4 en verde; `./vendor/bin/sail test`
      pasa completo.
- [ ] `sail bin pint` limpio.
