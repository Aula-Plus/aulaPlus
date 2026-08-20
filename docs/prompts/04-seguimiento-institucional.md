# Sesión 4 — Seguimiento institucional (primer pilar)

**Depende de:** Sesión 1, Sesión 2, Sesión 3 (usa `Auditable` y el patrón de notificación para alertas).
**Bloquea a:** nada directamente, pero la Sesión 5 (asistente de IA) usa los mismos endpoints de perfil de alumno/grupo como contexto de entrada.
**Contexto persistente:** ya cargado desde `CLAUDE.md`.

## Objetivo

Implementar el pilar de seguimiento: vistas agregadas por alumno y grupo, sistema de comentarios multi-actor, generación de alertas tempranas, y el tablero de adopción para Dirección (uno de los tres indicadores de éxito del piloto según el formulario VIN).

## 1. Comentarios

Nueva entidad `Comment` (tenant-scoped, `Auditable`):

| columna | tipo | notas |
|---|---|---|
| school_id | FK | |
| author_id | FK → users | |
| commentable_type | string | polimórfico |
| commentable_id | — | polimórfico — por ahora solo se usa sobre `Student` y `Group`, pero dejarlo polimórfico para no tener que migrar después |
| content | text | |
| tone | enum, nullable: `positive`,`neutral`,`concerning` | opcional, lo setea el autor — usado por la regla de alertas de la sección 4 |
| visible_to | jsonb | array de roles que pueden verlo, ej. `["director","psychopedagogue","teacher"]` — permite que un comentario de psicopedagogía sea privado entre psicopedagogía/dirección si hace falta |

- Endpoints: `POST /api/v1/students/{student}/comments`, `POST /api/v1/groups/{group}/comments`, `GET` de listado para cada uno (filtrando por `visible_to` según el rol del usuario autenticado).
- Autorización para crear comentario sobre un Student: mismas reglas que ver su perfil (teacher solo si dicta al alumno; psychopedagogue/director sin restricción). Reutilizar la Policy de sesión 2, no crear una regla paralela.

## 2. Vista de seguimiento por alumno

`GET /api/v1/students/{student}/tracking` — endpoint agregador (no es un CRUD, es una vista compuesta). Devuelve:

- Datos básicos del alumno (respetando el filtrado de campos sensibles de la sesión 2).
- Últimas N evaluaciones en las que participó (vía su grupo — no hay relación directa Student-Assessment en el modelo de dominio, se infiere por pertenencia a grupo en el período correspondiente).
- Accommodations vigentes (`isEffective() === true`).
- Barriers activas.
- Últimos comentarios visibles para el rol del usuario autenticado.
- Alertas abiertas (ver sección 4).

No hace falta un solo query gigante — armá el Resource combinando varias queries pequeñas y cacheá el resultado 60 segundos (cache de aplicación, no HTTP).

## 3. Vista de seguimiento por grupo

`GET /api/v1/groups/{group}/tracking` — análogo a nivel de grupo:

- Lista de alumnos del grupo con un indicador resumido por alumno (cantidad de alertas abiertas, si tiene accommodations activas — sin exponer el detalle clínico si el usuario no tiene permiso, solo el conteo).
- Tendencia agregada del grupo (cantidad de evaluaciones tomadas en el período, cantidad de comentarios cargados — mantenelo simple y extensible, no inventes métricas pedagógicas que no están en el dominio).

## 4. Alertas tempranas

Nueva entidad `Alert` (tenant-scoped, `Auditable`):

| columna | tipo | notas |
|---|---|---|
| school_id | FK | |
| student_id | FK | |
| type | enum: `performance`,`behavior`,`planning_attendance` | `planning_attendance` es un placeholder para cuando haya datos de asistencia real (fuera de alcance hoy, dejar el enum pero no generarlo aún) |
| severity | enum: `low`,`medium`,`high` | |
| description | text | |
| resolved | boolean, default false | |
| resolved_by_id | FK, nullable | |
| resolved_at | timestamp, nullable | |

**Regla de generación (versión simple, no un motor de reglas configurable):**

Crear un comando `php artisan alerts:generate` (pensado para correr por cron/scheduler diario) que:

- Para cada alumno, cuenta comentarios con `tone = concerning` en los últimos 15 días. Si 3 o más se acumulan para el mismo alumno y no hay ya una alerta abierta del mismo tipo, generar una `Alert` tipo `behavior`, severidad `medium`.
- Documentar esta regla como v1 explícitamente reemplazable — dejar un comentario en el código: "Regla simplificada para el piloto. Ajustar umbrales con feedback real de los 3 colegios piloto antes de escalar."
- Al generar una alerta, invocar `StatusChangeNotifier` de la sesión 3.
- Endpoint `POST /api/v1/alerts/{id}/resolve` — cualquier rol que pueda ver el perfil clínico del alumno puede resolver.
- Endpoint `GET /api/v1/students/{student}/alerts` y `GET /api/v1/groups/{group}/alerts`.

## 5. Tablero de adopción (para Dirección)

Este es uno de los tres indicadores de éxito del piloto mencionados en el formulario VIN — tratalo con prioridad real, no como algo cosmético.

Nueva tabla `usage_events` (tenant-scoped, sin `Auditable` — es un log de alto volumen, no necesita el overhead de auditoría completa):

| columna | tipo | notas |
|---|---|---|
| school_id | FK | |
| user_id | FK | |
| event_type | string | ej. `login`, `annual_plan.created`, `class_session.created`, `assessment.created`, `ai_proposal.applied` (este último lo dispara la sesión 5) |
| metadata | jsonb, nullable | |
| created_at | timestamp | sin `updated_at` |

- Crear un listener/observer liviano que registre un evento en los puntos clave (login vía evento de Sanctum, creación de las entidades de planificación). Un solo trait `LogsUsageEvents` aplicado donde corresponda, que escuche `created` del modelo y escriba el evento correspondiente.
- `GET /api/v1/schools/{school}/adoption-dashboard` — autorización: solo `director` de esa school. Devuelve:
  - % de teachers con al menos 1 login en los últimos 30 días.
  - % de teachers con al menos 1 objeto de planificación creado en los últimos 30 días.
  - Serie temporal simple (por semana) de eventos de tipo `login` y de creación de contenido.

## 6. Tests

- Test: un `teacher` sin relación a un alumno no puede comentar sobre él (403).
- Test: un comentario con `visible_to = ["psychopedagogue"]` no aparece en el listado que ve un `teacher`.
- Test: `alerts:generate` genera una alerta cuando se cumplen las condiciones y NO genera una segunda si ya hay una abierta del mismo tipo para el mismo alumno.
- Test: `adoption-dashboard` devuelve 403 para un rol que no es `director`.
- Test: el cálculo de % de teachers activos es correcto con un set de datos conocido (armar el escenario explícitamente en el test, no depender del seeder).

## 7. Criterios de aceptación

- [ ] Endpoints de comentarios, seguimiento por alumno/grupo, alertas y tablero de adopción implementados y documentados en `routes/api.php`.
- [ ] Comando `alerts:generate` registrado en el scheduler de Laravel para correr diariamente.
- [ ] Todos los tests de la sección 6 en verde.
- [ ] Ningún endpoint de esta sesión expone datos clínicos a un rol que no debería verlos según la Policy de la sesión 2.
- [ ] `./vendor/bin/sail test` pasa en verde.
