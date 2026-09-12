# Sesión 9 (backend) — Alcance de comentarios: "solo quien escribe" y contador reservado

**Depende de:** Sesión 4 (`Comment`, `visible_to`).
**Bloquea a:** Sesión 10 (línea de tiempo del alumno, que filtra comentarios
"concerning" con esta misma regla) y Sesión 11 (Perfil de grupo, que
consume el contador corregido).
**Contexto persistente:** ya cargado desde `CLAUDE.md`. Ver también
`aulaplus-documento-vivo/documento_aulaplus.html`, decisión "E1 · Eitán"
(pantallas 3 y 4) y "D3 + Eitán" (pantallas 3 y 4).

## Objetivo

Dos cambios sobre `Comment`, ambos en el mismo módulo de visibilidad —
extraídos de una sesión más grande para poder revisarlos por separado.

## 1. Cuarto alcance: "solo quien escribe"

Los tres alcances "todos los que ven al alumno/grupo" / "solo dirección" /
"solo psicopedagogía" ya son expresables con `visible_to` (`null` /
`["director"]` / `["psychopedagogue"]`). El cuarto, "solo quien escribe",
**no** es expresable hoy: `visible_to` es una lista de *roles*, y restringir
a un rol no aísla a una persona (dos docentes comparten el rol `teacher`).
Esto aplica igual a comentarios de alumno y de grupo — es el mismo campo,
la misma regla en las dos pantallas.

Migración nueva sobre `comments`:

```php
Schema::table('comments', function (Blueprint $table) {
    $table->boolean('author_only')->default(false);
});
```

Cuando `author_only = true`, el comentario es visible únicamente para
`author_id`, sin importar `visible_to` (que debe guardarse `null` en ese
caso — el FormRequest lo fuerza).

**Actualizar en conjunto** (el docblock de `Comment` ya advierte que deben
mantenerse sincronizados):

- `Comment::scopeVisibleToRole(Builder $query, User $user)` — agregar:
  visible si `author_only && author_id === $user->id`, además de la
  condición de rol existente cuando `author_only` es `false`.
- `Comment::isVisibleToRoles(array $roles)` — este método ya no alcanza
  porque necesita saber *quién* pregunta, no solo sus roles. Cambiarlo a
  `isVisibleTo(User $user)` (recibe el usuario completo) y actualizar el
  único call site actual, `StudentTrackingResource` (Sesión 4) — verificar
  que no quede roto.

FormRequest de creación de comentario (`StudentCommentController`/
`GroupCommentController`, ya existentes): agregar `author_only` (boolean,
opcional) a la validación, mutuamente excluyente con `visible_to` (si
`author_only` es `true`, ignorar/rechazar `visible_to` si vino seteado).

## 2. Corregir: contador de comentarios de grupo expuesto a todos

`GroupTrackingController::show` (Sesión 4) devuelve hoy `comments_count` sin
ninguna restricción de rol — cualquier viewer lo recibe. El documento
(pantalla 3, "D3 + Eitán") es explícito: el docente lee el contenido de los
comentarios de otros docentes, pero el **conteo** es exclusivo de
psicopedagogía/dirección — "el número ancla, el contenido no". Esto es un
bug a corregir, no una feature nueva (ya estaba mal desde la Sesión 4).

Cambio en `GroupTrackingResource` (o donde arme la respuesta): omitir la
clave `comments_count` por completo (no `0`, no `null` — omitirla) cuando el
usuario no pasa una verificación school-wide (`$user->hasAnyRole(Role::schoolWideValues())`).
Mismo patrón de "se omite la clave, no se devuelve un valor falso" que ya se
usa para `marks` en el resto del dominio clínico.

## 3. Tests

- Comentario con `author_only = true`: visible para el autor, invisible para
  cualquier otro rol (incluido director/psicopedagogía) — test explícito de
  que "solo quien escribe" es más restrictivo que cualquier rol, no un rol
  más.
- `isVisibleTo`/`scopeVisibleToRole` siguen dando el mismo resultado que
  antes para comentarios con `author_only = false` (test de regresión de
  Sesión 4/8/9 frontend).
- `GET /groups/{group}/tracking`: un `teacher` no recibe la clave
  `comments_count` en la respuesta (no un `0`); psicopedagogía/dirección sí.
- Test de regresión: el fix de la sección 2 no cambia nada para
  psicopedagogía/dirección (mismo valor que antes).

## 4. Criterios de aceptación

- [ ] `Comment.author_only` implementado; `isVisibleTo`/`scopeVisibleToRole`
      actualizados y sincronizados; `StudentTrackingResource` actualizado al
      nuevo método.
- [ ] `comments_count` de `GroupTrackingController` respeta el rol del
      viewer.
- [ ] Todos los tests de la sección 3 en verde; `./vendor/bin/sail test`
      pasa completo (sin romper Sesiones 4, 8, 9 frontend).
- [ ] `sail bin pint` limpio.
