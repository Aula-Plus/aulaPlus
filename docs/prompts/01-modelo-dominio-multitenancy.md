# Sesión 1 — Modelo de dominio (sobre el skeleton de tenancy existente)

**Depende de:** el skeleton de auth/tenancy/roles/CI que ya existe en el repo (ver `CLAUDE.md`).
**Bloquea a:** todas las demás sesiones.
**Contexto persistente:** ya cargado desde `CLAUDE.md`. No lo repitas, pero respetalo.

## 0. Antes de arrancar — no dupliques el skeleton

Este repo **ya tiene** auth, tenancy y roles funcionando (`schools`, `users`, `BelongsToSchool`, `SchoolScope`, `App\Support\Tenancy`, Spatie `laravel-permission`). Esta sesión agrega el modelo de dominio del producto (Student, Group, Accommodation, etc.) **sobre** esa base. Concretamente:

- Revisá las migraciones existentes de `schools` y `users` antes de tocar nada. Si algún campo de la sección 2 ya existe, no lo recrees; si falta, agregalo con una migración nueva (`add_x_to_schools_table`, no una migración que redefina la tabla).
- **No** crees un trait de tenancy nuevo, un scope nuevo, ni un helper de "current school" nuevo. Todo modelo de dominio nuevo tenant-scoped usa `App\Models\Concerns\BelongsToSchool` tal como está.
- Confirmá qué estrategia de PK usan `schools`/`users` (UUID, ULID, o bigint autoincremental) y usá **la misma** en todas las tablas nuevas — no mezcles estrategias de PK entre tablas relacionadas por FK.
- El test de aislamiento de tenant de la sección 4 debe usar `Tenancy::useSchool()` tal como lo expone el helper existente, no una implementación paralela.

## 1. Alcance

Implementar todas las entidades y relaciones de la tabla de abajo. No implementar `Project`, `ReportCard` (Boletín), `ProgressIndicator` — fuera de alcance (ver `CLAUDE.md`).

## 2. Entidades y columnas

Usar la misma estrategia de PK que `schools`/`users` (ver sección 0). Usar `timestamps()` en todas salvo que se indique lo contrario.

### School (probablemente ya existe — confirmar y extender)
| columna | tipo | notas |
|---|---|---|
| name | string | |
| logo_url | string, nullable | |
| anep_authorization_type | string | |
| anep_primary_body | enum: `dgeip`,`not_applicable` | |
| anep_secondary_body | enum: `dges`,`not_applicable` | |
| levels_offered | jsonb | array de strings |
| instruction_languages | jsonb | array de strings |

### User (probablemente ya existe — confirmar y extender)
| columna | tipo | notas |
|---|---|---|
| photo_url | string, nullable | agregar solo si no existe |

El rol se maneja vía Spatie (`roles`/`model_has_roles`), no una columna `role` — ver `02-roles-permisos.md`.

### Calendar (tenant-scoped)
| columna | tipo | notas |
|---|---|---|
| user_id | FK → users, unique | relación 1:1 |

### CalendarEvent (tenant-scoped)
| columna | tipo | notas |
|---|---|---|
| school_id | FK | |
| title | string | |
| description | text, nullable | |
| start_at | datetime | |
| end_at | datetime, nullable | |
| type | string | |

Pivot `calendar_calendar_event` (calendar_id, calendar_event_id) — M:N.

### Student (tenant-scoped)
| columna | tipo | notas |
|---|---|---|
| school_id | FK | |
| full_name | string | |
| photo_url | string, nullable | |
| birth_date | date | |
| enrollment_year | integer | |
| has_therapeutic_companion | boolean, default false | atributo de dato — no implica que exista un rol `at` con login (ver `02-roles-permisos.md`, ese rol queda afuera del alcance actual) |
| learning_profile | jsonb, nullable | estructura libre por ahora |
| tracking_notes | text, nullable | |
| individual_profile | jsonb, nullable | |
| related_documents | jsonb, nullable | array de referencias a archivos |
| soft deletes | — | |

### Group (tenant-scoped)
| columna | tipo | notas |
|---|---|---|
| school_id | FK | |
| name | string | |
| level | string | |
| school_year | integer | |
| group_profile | jsonb, nullable | |
| related_documents | jsonb, nullable | |

### CurricularFramework (global, catálogo)
| columna | tipo | notas |
|---|---|---|
| name | string | seed: ANEP EBI, ANEP Bachillerato, Cambridge Primary, Cambridge LS, IGCSE, IB PYP, IB MYP, IB DP |

### CurricularCatalog (global)
| columna | tipo | notas |
|---|---|---|
| curricular_framework_id | FK | |
| name | string | |
| valid_from | date | |
| valid_until | date, nullable | |

### CurricularItem (global, jerárquico)
| columna | tipo | notas |
|---|---|---|
| curricular_catalog_id | FK | |
| parent_id | FK, nullable, self-ref | |
| type | enum: `general_competency`,`curricular_space`,`subject`,`strand`,`substrand` | |
| code | string | |
| name | string | |
| description | text, nullable | |

Adjacency list simple (`parent_id`), no over-engineer con nested sets. Agregar un método `descendants()` recursivo y un test que arme un árbol de 3 niveles y lo recorra.

### AnnualPlan (tenant-scoped)
| columna | tipo | notas |
|---|---|---|
| group_id | FK | |
| curricular_framework_id | FK | |
| teacher_id | FK → users | creador/dueño |
| student_id | FK, nullable → students | si no es null, es un plan individualizado (PEI/PTP) para ese alumno dentro del grupo |
| description | text | |
| year | integer | |
| subject | string | |
| language | string | |

### Unit (tenant-scoped, hereda tenant vía annual_plan)
| columna | tipo | notas |
|---|---|---|
| annual_plan_id | FK | |
| name | string | |
| position | integer | posición en la secuencia anual |
| start_date | date | |
| end_date | date | |
| materials | jsonb, nullable | |

Pivot `unit_curricular_item` (unit_id, curricular_item_id) — M:N.

### ClassSession (tenant-scoped)
| columna | tipo | notas |
|---|---|---|
| group_id | FK | |
| unit_id | FK, nullable | ad-hoc si es null |
| assessment_id | FK, nullable → assessments | ver nota abajo |
| date | date | |
| duration_minutes | integer | |
| title | string | |
| objective | text, nullable | |
| description | text, nullable | descripción planificada |
| outcome | text, nullable | lo que efectivamente pasó en la clase |
| teacher_notes | text, nullable | |
| status | enum: `planned`,`delivered`,`cancelled` | |

> **Asunción:** el documento de dominio lista un campo "Evaluación" dentro de Sesión de Clase sin más detalle. Asumimos una relación opcional `assessment_id` para vincular la sesión con la evaluación que se toma en ella. Documentar esta asunción en el PR.

### Assessment (tenant-scoped)
| columna | tipo | notas |
|---|---|---|
| group_id | FK | |
| teacher_id | FK → users | creador |
| type | enum: `written`,`assignment`,`project`,`oral`,`submission` | |
| purpose | text, nullable | |
| duration_minutes | integer, nullable | |
| content | jsonb, nullable | |
| variant_number | integer, default 1 | |

Pivot `assessment_curricular_item` (assessment_id, curricular_item_id) — M:N.

### Accommodation (tenant-scoped) — ver también sesión 3 para autoría/aprobación
| columna | tipo | notas |
|---|---|---|
| student_id | FK | |
| type | string | |
| active | boolean, default true | |
| description | text | |
| focus_area | string | |
| llm_rule | jsonb, nullable | |
| requires_external_approval | boolean, default false | |
| approved | boolean, nullable | |
| created_by_id | FK → users | |
| deleted_by_id | FK, nullable → users | |
| soft deletes | — | |

### Barrier (tenant-scoped)
| columna | tipo | notas |
|---|---|---|
| student_id | FK | |
| description | text | |
| coping_strategy | text, nullable | |
| active | boolean, default true | |
| created_by_id | FK → users | |
| deleted_by_id | FK, nullable → users | |
| soft deletes | — | |

### TechnicalReport (tenant-scoped)
| columna | tipo | notas |
|---|---|---|
| student_id | FK | |
| document_url | string | |
| summary | text, nullable | |
| attachments | jsonb, nullable | |
| uploaded_by_id | FK → users | |

## 3. Relaciones adicionales (pivots)

| pivot | columnas propias | tipo |
|---|---|---|
| `school_curricular_framework` | level_from, level_to, active (bool), configuration (jsonb) | M:N |
| `group_curricular_framework` | — | M:N |
| `group_student` | school_year (int), details (jsonb, nullable) | M:N histórica — ver nota abajo |
| `group_teacher` | details (text, nullable) | M:N |
| `student_curricular_framework` | — | M:N |
| `barrier_accommodation` | proposed_by_id, validated (bool, default false), validated_by_id (nullable) | M:N — la lógica de validación se implementa en sesión 3, acá solo la tabla y las relaciones Eloquent |

> **Nota sobre `group_student`:** asumimos que un alumno pertenece a un único grupo por año lectivo. Agregar constraint `unique(student_id, school_year)`. Si el negocio confirma que un alumno puede estar en más de un grupo el mismo año, quitar el constraint — dejalo señalado como comentario en la migración.

`CurricularFramework`, `CurricularCatalog` e `CurricularItem` son catálogo **global**, no tenant-scoped (los usa más de una institución). No les apliques `BelongsToSchool`.

## 4. Test de aislamiento (el más importante de la sesión)

Escribir un test de Feature que verifique el aislamiento: crear 2 schools con datos, autenticar como usuario de la school A (`Tenancy::useSchool()`), y comprobar que ningún endpoint/consulta devuelve datos de la school B. No lo saltees.

## 5. Factories y seeders

- Factory para cada entidad nueva.
- Seeder `PilotSchoolsSeeder` con las 3 instituciones piloto reales (Escuela Integral, Ivy Thomas Memorial School, St. Patrick's College): 1 usuario por rol existente por school (`teacher`, `psychopedagogue`, `director` — ver sesión 2), 2-3 groups, 15-20 students por group, un curricular framework acorde (St. Patrick's → Cambridge/IB, por ejemplo — usá criterio razonable), y un `CurricularCatalog` con `CurricularItem` de 2-3 niveles de profundidad.
- Seeder de `CurricularFramework` con los 8 marcos listados arriba (dato global, correr siempre).
- Si `php artisan app:create-user` ya es el camino oficial para crear el primer usuario de cada school (según `CLAUDE.md`), usalo al menos una vez en el seeder para confirmar que sigue funcionando con los campos nuevos; el resto de los datos de prueba puede ir directo por factories.

## 6. Criterios de aceptación

- [ ] `./vendor/bin/sail artisan migrate:fresh --seed` corre limpio.
- [ ] No se creó ningún trait/scope/helper de tenancy nuevo — todo usa `BelongsToSchool`/`SchoolScope`/`Tenancy` existentes.
- [ ] Todas las relaciones Eloquent de la sección 2 y 3 están implementadas y testeadas.
- [ ] Test de aislamiento de tenant pasa (sección 4).
- [ ] Test de recorrido del árbol de `CurricularItem`.
- [ ] Test de constraint `unique(student_id, school_year)` en `group_student`.
- [ ] `CurricularFramework`, `CurricularCatalog` y `CurricularItem` NO tienen `school_id`.
- [ ] Ningún modelo tenant-scoped permite guardarse sin `school_id` (constraint `not null` a nivel de DB, no solo de aplicación).
- [ ] `./vendor/bin/sail test` pasa en verde.
