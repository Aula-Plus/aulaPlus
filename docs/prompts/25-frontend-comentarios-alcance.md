# Sesión 13 (frontend) — Alcance de comentarios en la UI

**Depende de:** Sesión 8 (frontend, `CommentsPanel` — esta sesión reemplaza
su selector de alcance, no crea un componente nuevo), y del backend de
`docs/prompts/19-comentarios-alcance.md` (Sesión 9 backend) ya mergeado.
**Bloquea a:** nada dentro de este set.
**Contexto persistente:** ya cargado desde `CLAUDE.md`.

## Objetivo

`CommentsPanel` (Sesión 8) hoy arma `visible_to` con un `fieldset` de
checkboxes de rol, que permite combinaciones libres (incluida la
combinación vacía = "todos"). El backend de la Sesión 9 agrega un cuarto
alcance, "solo quien escribe" (`author_only`), que **no** es una combinación
de roles — es ortogonal a `visible_to` y mutuamente excluyente con él. Esta
sesión reemplaza el `fieldset` de checkboxes por un selector de **cuatro
opciones preestablecidas**, la forma en la que el producto piensa el alcance
de un comentario (pantallas 3 y 4 del documento vivo, decisiones "E1 · Eitán"
y "D3 + Eitán"): todos los que ven el registro, solo dirección, solo
psicopedagogía, o solo quien escribe. Aplica igual a comentarios de alumno y
de grupo — es el mismo componente en las dos pantallas.

## 1. Contrato de API

Verificar contra el código real del backend de la Sesión 9 antes de tipar.
Mismas rutas ya existentes (Sesión 8), con un campo nuevo en el body:

| Método | Ruta | Body |
|---|---|---|
| POST | `/api/v1/students/{student}/comments` | `{ content, tone?, visible_to?, author_only? }` |
| POST | `/api/v1/groups/{group}/comments` | `{ content, tone?, visible_to?, author_only? }` |

`visible_to` y `author_only` son mutuamente excluyentes: si se envía
`author_only: true`, no enviar `visible_to` (el backend lo fuerza a `null`
igual, pero el cliente no debe mandar ambos como si fueran independientes).

## 2. Tipos actualizados (`web/src/types.ts`)

Agregar a la interfaz `Comment` ya existente:

```ts
export interface Comment {
  // ...campos existentes...
  /**
   * Cuando es `true`, el comentario es visible únicamente para `author_id` —
   * más restrictivo que cualquier `visible_to`, sin importar su valor (que
   * el backend fuerza a `null` en ese caso). Mutuamente excluyente con
   * `visible_to`.
   */
  author_only: boolean
}
```

Y en `CommentInput` (`trackingApi.ts`):

```ts
export interface CommentInput {
  content: string
  tone?: CommentTone | null
  visible_to?: Role[] | null
  author_only?: boolean
}
```

## 3. Las cuatro opciones (`CommentsPanel`)

Reemplazar el `fieldset` de checkboxes por un `<Select>` (o `radio-group` si
el estilo del formulario lo pide — decisión visual del implementador, no del
contrato) con exactamente estas cuatro opciones, sin permitir combinaciones
libres:

```ts
type CommentScopeOption = "everyone" | "director" | "psychopedagogue" | "author_only"

const COMMENT_SCOPE_LABELS: Record<CommentScopeOption, string> = {
  everyone: "Todos los que ven este registro",
  director: "Solo dirección",
  psychopedagogue: "Solo psicopedagogía",
  author_only: "Solo quien escribe",
}
```

Es un estado puramente de UI (`CommentScopeOption`), que se traduce al
payload real en el submit — nunca se manda tal cual al backend:

```ts
function buildScopeFields(option: CommentScopeOption): Pick<CommentInput, "visible_to" | "author_only"> {
  switch (option) {
    case "everyone":
      return {}
    case "director":
      return { visible_to: ["director"] }
    case "psychopedagogue":
      return { visible_to: ["psychopedagogue"] }
    case "author_only":
      return { author_only: true }
  }
}
```

El valor por defecto del selector es `"everyone"` — mantiene la regla ya
vigente de la Sesión 8 de que "nada seleccionado" es "visible para todos",
nunca un array vacío.

## 4. Badge "Privado" en el listado

Cuando `comment.author_only === true`, mostrar un badge "Privado" junto al
de tono (mismo estilo visual que los badges de tono/rol ya existentes en
`CommentsPanel`). Como el propio backend ya filtra los comentarios visibles
por usuario (`scopeVisibleToRole`/`isVisibleTo`), en la práctica un
comentario `author_only` solo llega al cliente cuando el viewer es su propio
autor — el badge es una confirmación visual para el autor, no un mecanismo
de seguridad. No mostrar el texto "Visible para: ..." (de `visible_to`)
junto al badge "Privado" — son alcances mutuamente excluyentes, mostrar
ambos sería contradictorio.

## 5. `comments_count` pasa a condicional (`GroupTracking`)

La Sesión 9 backend también corrige un bug de la Sesión 4: `comments_count`
de `GET /groups/{group}/tracking` hoy se devuelve a cualquier rol; a partir
de esta sesión se **omite la clave por completo** (no `0`, no `null`) para
un viewer que no pasa `hasAnyRole(Role::schoolWideValues())`. Actualizar:

```ts
export interface GroupTracking {
  group: Group
  students: GroupTrackingStudent[]
  trend: {
    period_days: number
    assessments_count: number
    /** Ausente por completo (no `0`) para un viewer sin rol school-wide. */
    comments_count?: number
  }
}
```

En `GroupTrackingPage`, la tarjeta "Comentarios cargados" (sección
"Tendencia") debe dejar de renderizarse cuando `trend.comments_count` es
`undefined` — no mostrar la tarjeta con un `0` engañoso. Ajustar el grid a
una sola columna cuando falta esa tarjeta, en vez de dejar un hueco vacío.

## 6. Tests

- Elegir cada una de las cuatro opciones produce el payload correcto
  (`{}`, `{ visible_to: ["director"] }`, `{ visible_to: ["psychopedagogue"] }`,
  `{ author_only: true }`) — un test por opción.
- El payload nunca incluye `visible_to` y `author_only` a la vez.
- Un comentario con `author_only: true` en la lista muestra el badge
  "Privado" y no muestra la línea "Visible para: ...".
- Un comentario con `visible_to: ["director"]` sigue mostrando "Visible
  para: Director" como antes (test de regresión de la Sesión 8) y no
  muestra el badge "Privado".
- El valor por defecto del selector es "Todos los que ven este registro".
- `GroupTrackingPage` no renderiza la tarjeta "Comentarios cargados" cuando
  `trend.comments_count` es `undefined`.

## 7. Criterios de aceptación

- [ ] `CommentsPanel` ofrece las cuatro opciones de alcance, sin permitir
      combinaciones libres de roles.
- [ ] El badge "Privado" se muestra para comentarios `author_only` en ambas
      pantallas que usan `CommentsPanel` (alumno y grupo).
- [ ] `GroupTrackingPage` oculta la tarjeta de `comments_count` cuando el
      viewer no es school-wide, en vez de mostrar un valor falso.
- [ ] `npm run lint`, `npm run typecheck`, `npm run test`, `npm run build`
      pasan (desde `web/`).
