# Sesión 6 (backend) — Evaluaciones y resultados

**Depende de:** Sesión 1 (modelo de dominio — `Assessment` y `AssessmentPolicy`
ya existen), Sesión 2 (roles/Policies), Sesión 3 (`Auditable`).
**Bloquea a:** Sesión 8 (backend, `18-ajustes-categoria-instancia.md` —
necesita el CRUD real de `Assessment` para que la "instancia" de
`AccommodationInstanceOverride` sea una evaluación concreta) y Sesión 10
(backend, `20-linea-tiempo-alumno.md`) — el gráfico de desempeño de Perfil de
alumno necesita los resultados que esta sesión expone. También bloquea a la
Sesión 10 (frontend, este mismo módulo).
**Contexto persistente:** ya cargado desde `CLAUDE.md`.

## Objetivo

Cerrar el hueco dejado por la Sesión 1: `Assessment` tiene migración, modelo
y Policy, pero **ningún endpoint** (no se puede crear una evaluación hoy).
Esta sesión agrega el CRUD de `Assessment` que falta y una entidad nueva,
`AssessmentResult`, para el resultado de cada alumno en esa evaluación —
construida con el mismo rigor que el resto del dominio (migración, modelo,
factory, Policy, FormRequest, Controller, tests), no como un atajo.

**No es** el asistente de generación de evaluaciones con IA de
`docs/prompts/05-asistente-ia-docente.md` (Sesión 5, ya mergeada a `develop`
— motor de `AIProposal`, no la UI). Lo que falta es la curricular/pantallas
16-17 del documento vivo ("Nueva evaluación"/"Evaluación generada"), que
consumirían ese motor para poblar `content` — fuera de alcance acá. Esto es
el CRUD llano: elegir tipo, fecha, y cargar una nota por alumno. Cuando esa
pieza curricular se construya, escribe en el mismo `content`/`Assessment`
que esta sesión ya deja armado — no hay que rehacer nada.

## 1. Completar `Assessment`

Falta una columna que el modelo de dominio no prevé y que esta sesión sí
necesita: `administered_at` (date, not null) — la fecha en que se tomó la
evaluación, distinta de `created_at` (cuándo se cargó el registro). Sin esta
columna no hay forma de armar una línea de tiempo real para Perfil de alumno.

Migración nueva (no tocar la migración original de Sesión 1):

```php
Schema::table('assessments', function (Blueprint $table) {
    $table->date('administered_at');
});
```

No hay datos reales en producción todavía (piloto no arrancó), así que
`not null` sin backfill es seguro; el `AssessmentFactory` existente
(`api/database/factories/AssessmentFactory.php`) sí crea `Assessment` sin este
campo hoy — hay que actualizarlo para que lo setee.

Agregar `administered_at` al `#[Fillable]` del modelo.

### Endpoints

Reusar `AssessmentPolicy` (ya existe, no crear una nueva). El controller/
`FormRequest` deben verificar además que el `teacher` autenticado
efectivamente dicta ese `Group` (`$user->teachesGroup($group)`, el mismo
helper que ya usan otras Policies) — `AssessmentPolicy::create` hoy solo
chequea el rol, no la relación con el grupo puntual; esa verificación
adicional va en el Controller, no reescribas la Policy existente de Sesión 2
sin necesidad.

| Método | Ruta | Notas |
|---|---|---|
| GET | `/api/v1/groups/{group}/assessments` | Listado del grupo |
| POST | `/api/v1/groups/{group}/assessments` | `{ type, purpose?, duration_minutes?, administered_at }`. Solo el teacher que dicta el grupo |
| PATCH | `/api/v1/assessments/{assessment}` | Solo el teacher dueño (`AssessmentPolicy::update`) |
| DELETE | `/api/v1/assessments/{assessment}` | Solo el teacher dueño |

`content` y el vínculo con `CurricularItem` (`assessment_curricular_item`)
quedan fuera de esta sesión — son terreno de la Sesión 5. No agregar esos
campos al formulario/FormRequest todavía.

## 2. `AssessmentResult` (nueva entidad)

Tenant-scoped con `school_id` propio (`BelongsToSchool`) — es el patrón
mayoritario del dominio (`Accommodation`, `Barrier`, `Comment`, `Alert`,
`ClassSession` lo tienen; solo `Unit` es la excepción, porque `AnnualPlan` es
el único punto de verdad de tenancy en esa jerarquía puntual). `Auditable`:

| columna | tipo | notas |
|---|---|---|
| school_id | FK | |
| assessment_id | FK → assessments | |
| student_id | FK → students | debe pertenecer al grupo de la evaluación — validar en el FormRequest. `Student` no tiene un `group_id` propio: la pertenencia es vía el pivot `group_student` (`$assessment->group->students()->whereKey($studentId)->exists()`), historizado por `school_year` — no asumir una FK directa |
| score | decimal(5,2) | requerido |
| feedback | text, nullable | comentario opcional del docente sobre esa nota puntual |
| created_by_id | FK → users | |

Restricción única: `(assessment_id, student_id)` — un resultado por alumno
por evaluación. Cargar de nuevo actualiza (`update`), no duplica.

### Endpoints

| Método | Ruta | Notas |
|---|---|---|
| POST | `/api/v1/assessments/{assessment}/results` | Body: array `[{ student_id, score, feedback? }]` — upsert por `(assessment_id, student_id)`. Solo el teacher dueño de la evaluación |
| GET | `/api/v1/assessments/{assessment}/results` | Listado. Lectura school-wide (no es dato clínico, no aplica el filtrado de campo de la Sesión 2) |
| GET | `/api/v1/students/{student}/results` | Nuevo endpoint agregador: historial de resultados del alumno ordenado por `administered_at`, con `{ assessment_id, assessment_type, administered_at, score }` — esto es lo que va a consumir el gráfico de Perfil de alumno en la sesión siguiente. Mismas reglas de autorización que `GET /students/{student}/tracking` (Sesión 4) |

`AssessmentResultPolicy`: `create`/`update` solo el teacher dueño del
`Assessment` padre (mismo criterio que `AssessmentPolicy::update`); `view`
school-wide igual que `AssessmentPolicy::view`.

## 3. Tests

- No se puede crear un `Assessment` para un grupo que el teacher autenticado
  no dicta (403), aunque `AssessmentPolicy::create` por sí sola lo permitiría
  — confirma que el chequeo de grupo puntual está en el Controller.
- `AssessmentResult` rechaza (422) un `student_id` que no pertenece al
  `group_id` de la evaluación.
- Cargar un resultado dos veces para el mismo alumno actualiza el existente,
  no crea un duplicado (verificar con la restricción única).
- Un `teacher` que no dicta el grupo no puede crear resultados (403) aunque
  pueda ver la evaluación (lectura school-wide).
- `GET /students/{student}/results` devuelve los resultados ordenados por
  `administered_at`, no por `created_at`.
- Tenant-isolation estándar para ambas entidades.

## 4. Criterios de aceptación

- [ ] `Assessment` tiene CRUD completo respetando `teachesGroup`.
- [ ] `AssessmentResult` implementado con migración, modelo, factory, Policy,
      FormRequest, Controller y tests — mismo nivel de rigor que el resto del
      dominio, sin campos placeholder.
- [ ] `GET /students/{student}/results` listo para que la Sesión 7 lo
      consuma.
- [ ] Todos los tests de la sección 3 en verde; `./vendor/bin/sail test`
      pasa completo.
- [ ] `sail bin pint` limpio.
