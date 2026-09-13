# Sesión 16 (frontend) — Columna agregada en Grupos

**Depende de:** del backend de `docs/prompts/22-grupos-listado.md` (Sesión
12 backend) ya mergeado. No depende de ninguna otra sesión frontend de este
lote (11-15) — es la más chica e independiente de las seis.
**Bloquea a:** nada dentro de este set.
**Contexto persistente:** ya cargado desde `CLAUDE.md`. Ver también
`aulaplus-documento-vivo/documento_aulaplus.html`, pantalla 2 ("Grupos"),
decisión "Línea roja".

## Objetivo

Sesión chica, espejo de su contraparte backend: agregar la columna
`active_tracking_count` al listado de grupos (`GroupsListPage`, ya existente
desde la Sesión 1) — agregado, nunca nómina, visible para cualquier rol
(docente incluido), sin ningún control de permiso nuevo.

## 1. Contrato de API

`GroupsListPage` ya consume `GET /api/groups` (ruta **sin** prefijo `v1` —
`groupsApi.fetchGroups()`, confirmado contra el código real). Esta sesión no
agrega una ruta nueva: el mismo endpoint devuelve un campo más,
`active_tracking_count`, en cada `Group` de la colección.

## 2. Tipo actualizado (`web/src/types.ts`)

Agregar a la interfaz `Group` ya existente:

```ts
export interface Group {
  // ...campos existentes...
  /**
   * Cuántos alumnos del grupo tienen seguimiento activo (adaptación
   * efectiva, barrera activa, alerta abierta, o seguimiento programado sin
   * resolver) — agregado, nunca nombra alumnos. Ver
   * `22-grupos-listado.md` §1.
   */
  active_tracking_count: number
}
```

Siempre un número (`0` si no hay nadie en seguimiento) — nunca `null` ni
ausente, según el propio contrato del backend (`22-grupos-listado.md` §3:
"Un grupo sin ningún alumno en seguimiento da `0`, no `null` ni ausente"),
así que no hace falta un chequeo de presencia en el componente.

## 3. `GroupsListPage`

Agregar una columna "Seguimiento activo" a la tabla existente, entre
"Docentes" y la columna de acciones. Mostrar el número tal cual —sin
badge de color ni alerta visual adicional, es un conteo informativo, no una
alerta (no confundir con el patrón de badges de severidad que sí usan
`Alert`/`Accommodation` en otras pantallas). Visible para cualquier rol que
vea el listado, sin ningún helper nuevo en `permissions.ts` — el backend ya
no aplica ningún gate a este campo (`22-grupos-listado.md` §3: "Cualquier
rol, incluido teacher, recibe la columna").

## 4. Tests

- La tabla de grupos muestra `active_tracking_count` para un usuario con rol
  `teacher` (no se oculta ni se reemplaza por un placeholder).
- Un grupo con `active_tracking_count: 0` muestra `0`, no una celda vacía ni
  un guion.

## 5. Criterios de aceptación

- [ ] `GroupsListPage` muestra la columna nueva, sin gate de rol.
- [ ] `npm run lint`, `npm run typecheck`, `npm run test`, `npm run build`
      pasan (desde `web/`).
