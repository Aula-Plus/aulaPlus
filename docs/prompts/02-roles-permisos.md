# Sesión 2 — Roles y permisos

**Depende de:** Sesión 1 (modelo de dominio) y del skeleton de Spatie `laravel-permission` ya presente en el repo.
**Bloquea a:** Sesiones 3 y 4 (necesitan Policies para exponer endpoints).
**Contexto persistente:** ya cargado desde `CLAUDE.md`.

## Objetivo

Implementar el sistema de autorización de Aula+ sobre `Spatie\Permission`: 3 roles institucionales (los que ya define `App\Enums\Role`), con reglas de acceso granulares sobre datos sensibles de alumnos.

## 1. Roles (alcance actual: 3, a propósito)

`App\Enums\Role`: `teacher`, `psychopedagogue`, `director`.

Los roles `psychologist` y `at` (acompañante terapéutico) del brief de producto quedan **explícitamente fuera de alcance por ahora** — decisión ya tomada, no la reabras en esta sesión. No crear Policies ni reglas que asuman su existencia. Si en el futuro se agregan, van a necesitar su propia revisión de la matriz de la sección 2 — dejar un comentario en el código donde corresponda (`StudentPolicy`, principalmente) indicando qué reglas de "ver perfil clínico completo" habría que revisar cuando eso pase.

## 2. Matriz de permisos (fuente de verdad)

Implementar exactamente estas reglas como Policies. Donde el documento de dominio no especifica explícitamente, se indica la regla por defecto propuesta.

| Entidad | Acción | Quién puede |
|---|---|---|
| User | crear/editar/desactivar | `director` (solo dentro de su school) |
| Student | ver (todos los campos) | `director`, `psychopedagogue` |
| Student | ver (campos básicos, sin perfil clínico) | `teacher` que dicta a ese alumno (vía `group_teacher` + `group_student`) |
| Student | crear/editar | `director`, `psychopedagogue` |
| Group | ver | cualquier rol de la school con relación al grupo (teacher asignado, o director/psychopedagogue sin restricción) |
| Group | crear/editar | `director` |
| AnnualPlan / Unit / ClassSession / Assessment | crear/editar/eliminar | el `teacher` dueño (usuario creador) únicamente |
| AnnualPlan / Unit / ClassSession / Assessment | ver | el teacher dueño, más `director` y `psychopedagogue` en modo solo lectura |
| Accommodation | crear/editar | `psychopedagogue`, `teacher`, `director` |
| Accommodation | aprobar (cuando `requires_external_approval = true`) | `director`, `psychopedagogue` |
| Accommodation | eliminar (soft delete) | mismas que crear/editar |
| Barrier | crear/editar | cualquier `teacher` o `psychopedagogue` |
| Barrier | eliminar (soft delete) | mismas que crear/editar |
| TechnicalReport | crear/subir | únicamente `psychopedagogue` |
| TechnicalReport | ver | `director`, `psychopedagogue` |
| Calendar / CalendarEvent | CRUD propio | cualquier usuario sobre su propio calendario |
| CurricularFramework / CurricularCatalog / CurricularItem | ver | cualquier usuario autenticado |
| CurricularFramework / CurricularCatalog / CurricularItem | crear/editar | ninguno todavía (dato de catálogo global, se carga por seeder — no exponer endpoint de escritura en esta sesión) |

## 3. Implementación

- Una `Policy` por entidad en `app/Policies/`, registradas en `AuthServiceProvider`.
- Dentro de cada método de Policy, usar `$user->hasRole('director')` (helper de Spatie) para el chequeo de rol, combinado con el chequeo de relación cuando aplica (docente↔grupo↔alumno). **Spatie resuelve el rol, la Policy sigue siendo la única fuente de la decisión de autorización** — no reemplaza a las Policies, es una pieza que usan.
- Para las reglas "teacher que dicta a ese alumno/grupo", crear un método helper reutilizable, por ejemplo `User::teachesGroup(Group $group): bool` y `User::teachesStudent(Student $student): bool`, basados en `group_teacher` y `group_student`. No dupliques esta lógica en cada Policy.
- Los **Form Requests** de escritura (creados en sesiones 3-4) deben llamar `$this->authorize()` contra la Policy correspondiente en su método `authorize()` — no metas checks de rol sueltos en los controllers.
- Para el filtrado a **nivel de campo** (teacher ve Student sin perfil clínico, director/psychopedagogue ven todo), no uses dos endpoints distintos: usá un único `StudentResource` que decide qué campos incluir según `Gate::allows('view-clinical-profile', $student)` dentro del `toArray()`. Documentá este patrón con un comentario porque se repite en otros Resources sensibles (`Barrier`, `Accommodation` parcialmente).

## 4. Tests

- Un test de Feature por Policy que cubra: caso permitido, caso denegado por rol incorrecto, caso denegado por pertenecer a otra school (aunque `SchoolScope` ya lo bloquee a nivel de query, la Policy debe fallar igual — defensa en profundidad, regla 4 del `CLAUDE.md`).
- Test específico: un `teacher` que NO dicta a un alumno no puede ver su perfil clínico aunque sea de su misma school.
- Test específico: solo `psychopedagogue` puede crear `TechnicalReport`, incluyendo que `director` (que tiene bastante acceso en otras entidades) NO puede.

## 5. Criterios de aceptación

- [ ] Existe una Policy por cada entidad de la tabla de la sección 2.
- [ ] Los tests de la sección 4 pasan, incluyendo los dos casos específicos marcados.
- [ ] Ningún controller tiene un `if ($user->hasRole(...))` suelto — toda la lógica de autorización vive en Policies/Gates.
- [ ] El filtrado de campos sensibles en `StudentResource` está probado con un test que compara las claves presentes en la respuesta JSON como `teacher` sin relación y como `psychopedagogue`.
- [ ] No hay ninguna referencia a `psychologist` ni `at` como roles en el código de esta sesión.
- [ ] `./vendor/bin/sail test` pasa en verde.
