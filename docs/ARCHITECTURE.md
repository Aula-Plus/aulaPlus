# Portal Docentes v2 — Arquitectura

> Documento vivo. Refleja el estado actual del scaffolding (auth + tenancy +
> roles + CI) y las decisiones tomadas. Las reglas de seguridad son requisitos,
> no preferencias — ver también [`../CLAUDE.md`](../CLAUDE.md).

## Contexto

Rewrite completo de una app de gestión escolar para **docentes, directores y
psicopedagogos**. Los **alumnos NO son usuarios**: son registros gestionados por
el staff. Producto **multi-tenant**: varias escuelas usan la misma app, aisladas
entre sí.

La versión anterior (vibe coding, React + Supabase directo) tuvo una auditoría
con hallazgos graves: auth falsa compartida, RLS `USING (true)`, edge functions
privilegiadas sin auth, API keys logueadas, sin tenant real de esquema. Este
rewrite existe para no repetir esos errores. **No hay usuarios en producción
todavía** — se prioriza una base técnica correcta sobre velocidad.

## Stack

| Capa | Tecnología |
|---|---|
| Frontend | React 19 + Vite + TypeScript + Tailwind v4 + shadcn/ui (SPA, sin SSR) |
| Backend | Laravel 13 (PHP 8.3+) como API REST/JSON — único backend con acceso a la DB |
| Base de datos | PostgreSQL (proveedor externo en deploy — por env, nunca hardcodeado) |
| Auth | Laravel Sanctum (SPA vía cookies) — auth real por persona |
| Roles | Spatie laravel-permission (`teacher`, `director`, `psychopedagogue`) |
| Validación backend | Form Requests (autoridad real) |
| Validación frontend | Zod + react-hook-form (solo UX) |
| Storage | Laravel Storage driver S3-compatible (R2) — discos privados + URLs firmadas |
| IA (futuro) | HTTP Client → OpenAI en Jobs + Queues; API key solo en backend |
| Tests backend | Pest |
| Tests frontend | Vitest + Testing Library |
| E2E (futuro) | Playwright (login, crear evaluación, ver reporte) |
| CI | GitHub Actions: lint + typecheck + tests por PR |
| Hosting | Front: Vercel/Netlify · Back: Railway/Render/Laravel Cloud · DB: managed PG |
| Local dev | Laravel Sail (Docker) para el backend |

## Estructura del repo

```
/
├── api/            → Laravel (backend)
├── web/            → React + Vite (frontend)
├── .github/workflows/ci.yml  → CI para ambas apps
├── docs/ARCHITECTURE.md      → este documento
└── CLAUDE.md       → reglas de arquitectura y seguridad
```

## Arquitectura por capas

```
React (SPA)
   │  axios → API REST (JSON, cookie de Sanctum)
   ▼
Laravel — capa de aplicación
   │  Controller → FormRequest (valida) → Policy (autoriza)
   │  → Service/Action (lógica) → Job (async: IA)
   ▼
Eloquent (Global Scope de school_id automático)
   ▼
PostgreSQL (cada tabla de negocio con school_id)
```

**Regla no negociable:** la autorización se decide en el servidor (Policies),
nunca confiando en el cliente. El front puede ocultar botones por rol (UX), pero
eso nunca es la barrera de seguridad.

## Tenancy y roles

- `schools` es el tenant raíz. `users.school_id` obligatorio; un usuario
  pertenece a **una sola** escuela (sin multi-escuela por usuario en v1).
- Cada tabla de negocio tiene `school_id`, aplicado por el trait
  `App\Models\Concerns\BelongsToSchool`, que:
  - agrega el global scope `App\Models\Scopes\SchoolScope` (filtra lecturas), y
  - autocompleta `school_id` al crear (desde el tenant actual).
- El "tenant actual" lo resuelve `App\Support\Tenancy` (del usuario autenticado;
  en consola/Jobs/tests se fija con `Tenancy::useSchool()` / `forSchool()`).
- `User` **no** usa el scope (recursaría al resolver la auth); el aislamiento de
  filas de usuarios se controla explícitamente en queries/policies.
- Roles (`App\Enums\Role`) — identificadores en inglés, labels en español en el
  frontend (`web/src/types.ts`):
  - **Docente** (`teacher`): sus propios grupos/planificaciones/evaluaciones.
  - **Psicopedagogo** (`psychopedagogue`): visibilidad completa de la escuela.
  - **Director** (`director`): visibilidad completa + administración de la escuela.
- Las Policies chequean **tenant + rol** (defensa en profundidad sobre el scope).
  Ver `GroupPolicy` / `StudentPolicy` como patrón de referencia.
- Alumnos = registros (tabla `students`), sin login.

## Alertas: circulación entre roles

Reglas de quién puede pasar una alerta y a quién (ClickUp 86e3jpzdp;
documento vivo, pantalla 11), actualizadas por la revisión de producto del
14/10. Fuente única en el backend: `App\Support\AlertCirculation` (la usan el
endpoint de candidatos y `ChooseAlertOutcomeRequest`).

- **La alerta pide una salida**: «Me ocupo yo» / «Se la paso a otro rol» /
  «La dejo en observación», siempre con responsable y plazo
  (`POST /alerts/{alert}/outcome`).
- **Solo a quien ya ve al alumno**: misma regla que el responsable de un
  seguimiento programado (`ScheduledFollowUpPolicy::canFollow`: da clase al
  alumno, o es psicopedagogía/dirección del colegio). Además: misma escuela,
  activo, invitación aceptada, distinto de quien actúa.
- **Nunca docente → docente**: un docente solo pasa la alerta a
  psicopedagogía o dirección; a un docente solo le llegan alertas pasadas por
  psicopedagogía o dirección. Un pedido armado a mano con otro destinatario
  falla validación (422).
- **Pasar es transferir a una sola persona** (`alerts.assignee_id`): desde ahí
  solo esa persona actúa (salidas, notas, cierre). Quien la pasó conserva
  **lectura**, sin acciones ni nuevos avisos, mientras conserve acceso al
  alumno y según su rol (`alert_user` en `Alert::scopeVisibleTo`: un docente
  la deja de ver si deja de darle clase al alumno).
- **No se comparte la alerta**: no hay endpoint de compartir ni
  co-responsables (se quitó lo que había asumido #130). La colaboración se
  organiza en un **seguimiento vinculado**.
- **Seguimiento vinculado** («Crear seguimiento vinculado»): se crea con el
  mismo formulario y las mismas reglas de asignación que cualquier seguimiento
  programado (`POST /students/{student}/scheduled-follow-ups` con `alert_id`),
  con responsable y tantas personas como haga falta (sin el límite de dos).
  Lo puede crear quien puede actuar sobre la alerta (`AlertPolicy::
  linkFollowUp`) y ve al alumno (`ScheduledFollowUpPolicy::create`); la alerta
  tiene que ser del mismo alumno y colegio. Son registros distintos
  (`scheduled_follow_ups.alert_id`): crear, compartir o resolver el
  seguimiento no cierra la alerta ni cambia su plazo, y resolver o pasar la
  alerta no toca participantes ni responsables del seguimiento. El vínculo se
  ve en ambos lados (`AlertResource.follow_ups` con estado y plazo;
  `ScheduledFollowUpResource.alert_id`). Las instancias de un seguimiento son
  otra tarea.
- **«Llegó a su fecha sin salida»** (`alerts:overdue`, diario): si el plazo de
  cualquiera de las tres salidas pasó y la alerta sigue pendiente, se marca la
  **misma** alerta (`alerts.overdue_at`) y se agrega un paso al hilo
  (`kind = overdue`, sin actor, con quién la tenía y el plazo). No se crea
  otra alerta; responsable, plazo, movimientos y notas quedan como estaban. Es
  un pendiente del circuito, no un empeoramiento del alumno. Una nueva salida
  pone un plazo nuevo y limpia la marca.
- **A quién se avisa**: a quien tiene la alerta, si sigue activo y con permiso
  vigente (ve la alerta). Hoy es el único participante de la alerta en sí —
  quienes trabajan el caso en un seguimiento vinculado se gestionan en ese
  seguimiento. Quien la transfirió no recibe avisos, salvo que haya prendido
  «Avisarme a mí también cuando llegue el plazo» (`alert_user.
  notify_at_deadline`, `PUT /alerts/{id}/deadline-notice`, decisión del 24/10):
  viene apagada, la cambia desde la lectura de la alerta cualquier rol que la
  haya pasado y no la tenga ahora, y se mantiene si la alerta se vuelve a
  pasar. El aviso es una
  notificación de base de datos (`alert_overdue`, solo ids y fecha) y se ve en
  «Mis seguimientos».
- **Dirección no se suma por plazo**: está desde el inicio si la
  configuración del colegio la incluye en los destinatarios del tipo (la ve
  por rol, sin tenerla ni recibir el aviso de plazo), o se incorpora
  explícitamente cuando alguien le pasa la alerta (pasa a tenerla). La marca de
  plazo no cambia destinatarios ni `alert_user`.
- **Alertas escaladas anteriores** (`type = escalated_overdue`): ya no se
  crean ni se configuran (no aparecen en la pantalla de configuración). Las
  que ya existen quedan como estaban y se cierran cuando la alerta que escalan
  se mueve o se cierra. La columna `escalated_at` pasó a ser `overdue_at`
  (migración `2026_10_15_000001`).
- El hilo (`alert_actions.kind`: `outcome` / `note` / `overdue`) es
  operativo: qué se hizo con el caso, nunca un diagnóstico; las notas quedan
  fuera del diff de auditoría. Solo deja notas quien tiene la alerta, así que
  no antes de elegir una salida, ni en una alerta cerrada o escalada.
- Elegir una salida, dejar una nota y cerrar bloquean la fila
  (`lockForUpdate`) y vuelven a verificar que quien actúa siga teniéndola.
- **Cada uno lee su propia situación** (decisión del 21/10, opción A):
  `AlertResource.is_in_charge` dice si quien mira tiene la alerta. En
  «Alertas y seguimientos» las alertas se agrupan en «Te toca elegir una
  salida» (nadie la tiene todavía), «A tu cargo» (la tenés vos) y «En curso»
  (solo lectura). Quien recibe una alerta pasada ve «A tu cargo desde el dd/mm
  · plazo al dd/mm», nunca «Pasada a otro rol»; quien la pasó sigue viendo
  «Pasada a otro rol».
- **Alumno dado de baja** (soft delete): su alerta no le llega a nadie nuevo
  (sin candidatos; pasarla da 422). Quien la tiene puede igual tomarla,
  dejarla en observación, anotarla y cerrarla.

## Lo que el colegio le indica a la IA (capa del colegio)

Pantalla 24 · solapas «Juicios» y «Evaluaciones» (ClickUp 1dfd3mc4bh6;
P24-DEC-20 / PA-091, P24-DEC-110 / PA-126, P24-DEC-111 / PA-127). Fuente única
en el backend: `App\Services\AIInstructions\SchoolAIInstructions`.

- **Glosario:** *indicación del colegio* / *capa del colegio* →
  `SchoolAIInstruction`; *tipo de pedido a la IA* → `App\Enums\AIRequestType`
  (`judgment`, `assessment`, `annual_plan`, `unit`, `class_session`,
  `report_summary`); *«Ver ejemplo»* → `JudgmentStylePreview`.
- **Base fija de Aula+ + capa del colegio.** La capa es general (una por tipo
  de pedido), pero hoy solo se completan juicios y evaluaciones; los demás
  tipos se ven vacíos («Todavía sin indicación») hasta definir su base. La
  línea roja de los juicios («nunca decir por qué tiene un ajuste ni qué
  diagnóstico lo originó») vive en la base fija y ningún texto del colegio la
  apaga. Los textos del colegio son referencia: el prompt le dice a la IA que
  ignore cualquier orden escrita adentro.
- **Reglas por alcance:** una para toda la institución (sin nivel) y, si hace
  falta, otras por nivel o por rango de grados de un nivel (`education_level_id`
  + `school_grade_ids`, de la lista de Colegio y estructura). Gana la más
  específica para el nivel y el grado del grupo: grado o rango más angosto,
  después el nivel entero, después la institución (P24-DEC-02). Un mismo
  alcance no se repite. Una regla de nivel o rango que todavía no dice nada
  no compite (el grupo sigue con la institucional) y, entre dos rangos que se
  pisan con el mismo ancho, gana la más vieja. No hay herencia campo por campo:
  la regla que gana se envía entera.
- **Evaluaciones:** `BuildProposalContext` suma `school_instruction` (la regla
  que aplica al grupo) a cada evaluación que genera la IA; no cambia escala,
  criterios ni la regla de una sola evaluación accesible.
- **«Así le llega a la IA»:** cada regla devuelve `prompt_text`, exactamente lo
  que recibe el modelo. Se guarda sola al escribir (P24-DEC-07).
- **«Ver ejemplo»** (solo juicios): a pedido, en la cola
  (`GenerateJudgmentStylePreviewJob`), con un alumno y una nota del docente
  inventados y la materia y los nombres de unidades del grupo que elige
  Dirección. Nunca datos de un alumno real; tampoco puntajes (P24-DEC-112).
- Solo Dirección (`SchoolPolicy::manageAIInstructions`).

## Escalas oficiales y resultados por nivel

Pantalla 24 · solapa «Escalas oficiales» (ClickUp 1dfd3mc4bgg). Fuente única
en el backend: `App\Services\ResultScales`.

- **Glosario:** *Escala oficial* → `OfficialScale`; *nivel de avance* →
  `OfficialScaleLevel`; *equivalencia del colegio* (nivel → nota 1..10) →
  `SchoolScaleEquivalence`.
- **Las escalas son datos globales**, como los marcos curriculares (sin
  `school_id`): las carga el equipo de Aula+ una vez por marco con
  `OfficialScaleSeeder` (`php artisan db:seed --class=OfficialScaleSeeder`,
  idempotente), cada una con su cita (documento + página). No hay ruta de
  escritura: Dirección las consulta (`GET /api/v1/official-scales`, solo
  Dirección, `SchoolPolicy::viewOfficialScales`) y nadie las edita.
- **Lo que decide el colegio** (`GET/PUT /api/v1/numeric-grade-settings`, solo
  Dirección): `schools.show_primary_numeric_grade` («Mostrar la calificación
  numérica junto al nivel de avance», una sola casilla para todo lugar donde
  se ve una calificación de Primaria) y, opcional, su propia equivalencia
  nivel → 1..10 para su escala de Primaria (completa o vacía).
- **Resultados:** un grupo de Primaria cuyo marco (el del grupo, o si no tiene,
  los activos del colegio) tiene escala oficial de Primaria registra cada
  resultado como un nivel (`assessment_results.official_scale_level_id`,
  `score` nulo). El resto de los grupos sigue con el `score` decimal. El
  número junto al nivel sale de la equivalencia del colegio o, si no hay, del
  que escribe el docente (`numeric_grade`); con la casilla apagada no se
  devuelve ningún número. Aula+ nunca convierte entre escalas.
- La línea «Nota de 1 a 10» de los gráficos de evolución solo toma resultados
  numéricos (PA-088): un nivel nunca se ubica por una conversión inventada.
- **Respuestas del PO del 12/11:**
  - Casilla prendida y sin número (ni equivalencia ni nota del docente): se
    muestra el nivel con «sin nota» al lado, discreto.
  - El número que escribió el docente se conserva aunque se apague la casilla
    o Dirección configure una equivalencia (solo se escribe cuando el docente
    puede escribirlo y lo manda).
  - Un resultado registrado del otro modo (nota donde ahora se registra nivel,
    o al revés) se sigue viendo; reemplazarlo exige `replace_previous: true`
    en esa fila («Reemplazar» en la planilla), si no es 422.
  - Si el grupo tiene dos escalas de Primaria válidas, el docente elige una
    antes del primer resultado (`PUT /api/v1/assessments/{id}/result-scale`).
    La escala queda guardada con la evaluación (`assessments.official_scale_id`,
    también al primer resultado cuando hay una sola), así los resultados no
    cambian de escala si después cambian los marcos del grupo.
  - La franja oficial de ANEP («9–10»…) se muestra con la escala como dato de
    consulta (`official_scale_levels.official_band`); nunca entra en un
    cálculo ni en la calificación de un alumno.

## Autenticación (SPA + Sanctum)

- Login por cookies (no tokens). Flujo: `GET /sanctum/csrf-cookie` →
  `POST /api/login` → sesión; `GET /api/me` para bootstrap; `POST /api/logout`.
- Middleware `statefulApi()` en `bootstrap/app.php`; dominios en
  `SANCTUM_STATEFUL_DOMAINS`; CORS en `config/cors.php` desde `FRONTEND_URL`.
- Primer usuario: `php artisan app:create-user` (sin cuentas hardcodeadas).

## Reglas de seguridad (derivadas de la auditoría)

1. Sin credenciales/API keys/cuentas demo en el código; todo por env, nunca
   commiteado (`.env` en `.gitignore` desde el primer commit).
2. Nunca loguear API keys ni tokens completos.
3. Ningún endpoint privilegiado sin auth + chequeo de autorización explícito.
4. Ninguna policy/scope "permitir todo" (`true`) salvo recurso público deliberado.
5. Todo input se valida en el backend (Form Requests).
6. HTML de IA se sanitiza con librería real (DOMPurify, `web/src/lib/sanitize.ts`),
   nunca regex casero.
7. Buckets/discos privados por defecto; acceso vía URLs firmadas temporales.
8. CORS restringido a los dominios reales, nunca `*` en producción.
9. Separación real de ambientes por env; sin hosts/URLs de deploy hardcodeados.
10. Sin endpoints de debug que expongan información interna.

## Historial de cambios de la ficha del alumno

Registra **cambios, no vistas**: altas, modificaciones y bajas en todo lo
editable de la ficha. Abrir la ficha, recorrer pestañas o consultar un documento
no escribe nada (cubierto por test).

- **Un solo rastro:** extiende `audit_logs` (no hay tabla paralela). El trait
  `Auditable` sigue escribiendo cada created/updated/deleted; para los modelos de
  la ficha delega en `App\Support\StudentFileHistory`, que agrega `student_id`,
  `section` (`App\Enums\StudentFileSection`), `actor_role` (el rol con el que
  actuó) y `details` (valores legibles). Un update que toca varias secciones (p.
  ej. el formulario del alumno) se divide en una fila por sección. Cambios sólo
  de pivote (grupo del alumno, vínculo ajuste↔barrera, categorías de una
  observación) se registran con `StudentFileHistory::recordManual()` desde su
  controller. Las categorías de una observación nueva van en su entrada de
  alta; si cambian después (hoy: al borrar una categoría, que se la quita a
  sus observaciones) queda una modificación antes → después
  (`recordCommentCategoriesChange()`).
- **Secciones:** `student_data`, `summary`, `learning_profile`, `documents`,
  `accommodations` (incl. desactivación por evaluación), `barriers` (incl.
  vínculos), `observations`, `reports`, `follow_ups`, `alerts`.
- **Contenido sensible (regla 11):** `changes` sigue redactado como antes (lo
  leen otras features); `details` guarda antes/después completos, **cifrado en
  reposo** (`encrypted:array`, depende de `APP_KEY`; rotar con
  `APP_PREVIOUS_KEYS`) y oculto en la serialización. `llm_rule` y `document_url`
  nunca se copian (se informan como "modificado"). Nada de esto va a logs.
- **Lectura:** `GET /api/v1/students/{id}/history` (filtros `user_id`,
  `section`, `action`, `from`, `to`; paginado; `actors` para el filtro
  Persona). Endpoint sólo para psicopedagogía y dirección
  (`StudentPolicy::viewChangeHistory`). Además, cada entrada pasa por la
  visibilidad del registro original (`App\Services\StudentChangeHistory`:
  `Comment::isVisibleTo()` para observaciones, la Policy `view` del modelo para
  el resto, `viewClinicalProfile` para perfil/documentos). **Si no la pasa, la
  entrada se oculta entera** (no se devuelve ni la metadata), igual que en el
  listado original. Ese chequeo recorre sólo las columnas de metadata de todo
  el historial del alumno (índice `student_id, id`); `changes`/`details` se
  leen y descifran sólo para la página devuelta.
- Filas anteriores a esta feature: se backfillean `student_id`/`section` cuando
  el registro dueño existe; sin `details`, se muestran desde `changes` (los
  campos sensibles aparecen como "contenido no disponible").

## Calendario (pantalla 21)

ClickUp 1dfd3mc4bgh. Ordena en el tiempo fechas que ya existen en otras
pantallas; no es un calendario nuevo (P21-DEC-02) ni reemplaza el del colegio
(P21-DEC-03).

- **Feed** `GET /api/v1/calendar?from&to&types[]` (`App\Services\Calendar\CalendarFeed`):
  lee cada fecha de su propio registro. Siete tipos (`App\Enums\CalendarItemType`);
  hoy tienen fuente `assessment`, `class`, `task` (seguimientos propios con
  plazo), `institutional` y `meeting` (eventos del calendario propio, ver
  abajo). `period_closing` y `project_stage` devuelven vacío hasta que existan
  las pantallas 26 y 18. El Inicio
  («Hoy en tu calendario», P01-HOY-01) lee el mismo feed para hoy.
- **Quién ve qué** (línea roja): clases solo el docente que da esa materia en
  ese grupo; evaluaciones el docente dueño, Psicopedagogía y Dirección todas
  (sin notas ni alumnos); tareas solo las propias; institucionales, los que
  le corresponden (ver abajo) y, en «Ver todo el calendario del colegio»
  (`scope=school`), todos los del colegio. Una
  reunión (`CalendarEvent` tipo `meeting`) la ve solo quien la tiene en su
  calendario (pivot `calendar_calendar_event`) — ni Dirección la ve si no está
  citada. Ningún ítem lleva nombre de alumno.
- **Evento institucional**: la única fecha que nace acá. Solo Dirección crea,
  edita y quita (`CalendarEventPolicy`). Fecha, hora opcional (`all_day`),
  lugar opcional; fechas/horas en hora local del colegio, guardadas en UTC.
- **A quién le corresponde** (ClickUp 1dfd3mc55zp, P21-DEC-05/108):
  `calendar_event_audiences`, una fila por destinatario y el evento toca los
  grupos que cumplan **cualquiera** (combinación). Destinatario = un nivel
  (`education_level_id`; «Secundaria» = Ciclo Básico + Bachillerato, dos
  filas), una generación (`school_grade_id`) o los grupos de un programa
  dentro de una generación (`school_grade_id` + `curricular_framework_id`,
  según la oferta curricular del grupo en cualquier materia) o un grupo
  (`group_id`, del colegio y del año lectivo de la fecha del evento; Eitán,
  ClickUp 1dfd3mc55zp). Los grupos se muestran siempre con el nombre que les
  puso el colegio («3° B»): cada destinatario trae `groups`, los grupos a los
  que llega en el año del evento (el propio grupo, o los de la generación o
  programa; vacío para un nivel), resuelto en el servidor con una consulta
  para todo el feed. Si se borra el grupo, la fila queda con todo en null y no
  le corresponde a nadie (nunca «todo el colegio»). Sin filas = todo
  el colegio (los eventos previos quedan así). Validación en
  `InstitutionalEventRequest`: el programa tiene que estar activo y no ser el
  régimen obligatorio del colegio y aplicar al nivel del grado
  (`GET /calendar-events/audience-options`, solo Dirección).
  `EventAudience`: al docente le corresponden los de los grupos donde da una
  materia, del año lectivo de la fecha del evento; a Psicopedagogía y
  Dirección (roles de todo el colegio) todos. Es un filtro de la vista, no de
  permisos: cualquier persona del colegio puede leer todos los eventos
  institucionales (`CalendarEventPolicy::view`). Cada ítem trae `audience` y
  `for_me`.
- **Hora y salón de una clase**: datos de la clase (`class_sessions.start_time`,
  `room`, ambos opcionales), editables por su docente en cualquier momento.
  `class_sessions.subject_id` dice de qué materia es la clase.
- **Mis horarios** (`TimetableSlot`): horario semanal privado del docente (solo
  él lo lee, `TimetableSlotPolicy`). Es un valor por defecto: precompleta hora y
  salón al crear (aplicar una propuesta de IA) o editar una clase
  (`TimetableSlotSuggester`: el horario libre de ese día; si no hay, el
  primero). Nunca crea clases; cambiarlo no toca las ya creadas.
- **Calendario propio y eventos compartidos** (ClickUp 1dfd3mc56kp,
  P21-DEC-108/PA-124): cada rol crea eventos personales (`CalendarEvent` tipo
  `personal`, `PersonalEventController`, `PersonalEventRequest`) e invita a
  cualquier usuario activo de su colegio (misma escuela, sin `disabled_at`,
  con contraseña); quien ya está en el evento y después fue desactivado se
  mantiene al editar (no bloquea la edición ni se le vuelve a avisar), y el
  creador lo ve en el selector para sacarlo. El evento se guarda una vez y se cuelga del calendario de
  cada participante (pivot `calendar_calendar_event`, `EventParticipants`):
  aparece una sola vez en el feed de cada uno, como ítem `meeting` con
  `shared` (dueño, participantes, alumno). No hay que aceptar: al invitado le
  llega `CalendarEventInvitedNotification` (pantalla 22, sin nombre de
  alumno). Solo el creador edita o borra; cada invitado lo quita de su
  calendario (`DELETE /calendar-events/{id}/participation`,
  `CalendarEventPolicy::leave`) sin borrarlo para los demás. Alumno vinculado
  opcional (`calendar_events.student_id`): el creador solo puede elegir uno
  que ya ve; todos los participantes ven el nombre y `shared.student.can_view`
  (StudentPolicy de quien mira) decide si se ofrece la ficha — la invitación
  no amplía permisos. `EventParticipants::invite()` es el mismo mecanismo que
  usarán las reuniones del Espacio de psicopedagogía (pantalla 10).
  Dirección alterna «Mi calendario» / «Calendario institucional» en el SPA
  (filtro de vista: sin la carga de evaluaciones / sin su agenda personal;
  el subtítulo, la nota y los chips de tipo siguen a la vista elegida).

### Glosario (Calendario)

| Español (UI)        | Código                                   |
|---------------------|------------------------------------------|
| Calendario          | calendar feed (`CalendarFeed`)           |
| Evento institucional| `CalendarEvent` tipo `institutional`     |
| A quién le corresponde (destinatarios) | `CalendarEventAudience` (`calendar_event_audiences`) |
| Generación          | grado (`SchoolGrade`) de los grupos del año |
| Calendario completo del colegio | `scope=school` del feed          |
| Reunión             | `CalendarEvent` tipo `meeting` / item `meeting` |
| Cierre de período   | item `period_closing`                    |
| Etapa de proyecto   | item `project_stage`                     |
| Tarea (en Calendario)| item `task` (= `ScheduledFollowUp` propio) |
| Mis horarios        | `TimetableSlot` (tabla `timetable_slots`) |
| Salón               | `room`                                   |
| Evento personal     | `CalendarEvent` tipo `personal` (item `meeting` con `shared`) |
| Invitado / participante | `Calendar` del usuario ligado por `calendar_calendar_event` (`EventParticipants`) |
| Quitar de mi calendario | `leave` (`DELETE /calendar-events/{id}/participation`) |
| Mi calendario / Calendario institucional | vista de Dirección en `CalendarPage` |

## Buscar en Aula+ (pantalla 01, PA-134)

ClickUp 1dfd3mc56kr, P01-DEC-118. Botón «Buscar» fuera de la barra lateral
(arriba del contenido, al lado de la barra, fijo al hacer scroll) y atajo
Ctrl+K / ⌘K en todas las pantallas (`web/src/features/search/GlobalSearch.tsx`).

- **Endpoint** `POST /api/v1/search` con `{ q }` en el cuerpo
  (`GlobalSearchRequest`: `q` de 2 a 80 caracteres, leído solo del cuerpo;
  `App\Services\Search\GlobalSearch`). Es POST para que lo que se escribe
  (quizás el nombre de un alumno) no viaje en la URL ni quede en registros de
  acceso (Eitán, 26/11). Hasta 8 resultados por tipo.
- **Quién ve qué**: los mismos scopes que los listados — `Student::visibleTo`,
  `Group::visibleTo`, `Assessment::visibleTo` (espejo de `AssessmentPolicy::view`:
  el docente, solo las suyas) y `ClassSession::visibleTo` (espejo de
  `ClassSessionPolicy::view`: el docente, las clases de su materia en sus
  grupos, o de sus grupos si la clase no tiene materia) — sobre el
  `SchoolScope`. Una evaluación además
  exige que su grupo sea visible, porque el resultado abre sus evaluaciones.
  Las secciones del menú se buscan en el SPA sobre `buildNavSections` (la
  misma lista filtrada por rol que la barra lateral).
- **Qué devuelve** (sin datos sensibles): alumno → nombre y grupo de su último
  año; grupo → nombre, nivel y año; evaluación → título (`purpose`, como en el
  Calendario), tipo, materia, grupo y fecha; clase → título, materia, grupo y
  fecha (nunca objetivo, descripción, secciones ni notas del docente).
- **Evaluaciones sin propósito** (Eitán, 26/11): `title` llega `null` y el SPA
  arma el nombre «tipo · materia · grupo · fecha» (`assessmentSearchLabel`, p.
  ej. «Escrita · Matemática · 5.°A · 12/11»). Se encuentran si cada palabra del
  término coincide con su tipo (palabras clave en español en
  `GlobalSearch::TYPE_KEYWORDS`, «escrito» incluido), su materia, su grupo o su
  fecha (`12/11` o `12/11/2026`). Las que tienen propósito se siguen buscando
  solo por su título.
- **Cómo compara**: solo nombres y títulos; sin mayúsculas ni tildes
  (á é í ó ú ü ñ) con `LOWER` + `REPLACE` anidados, igual en SQLite y
  PostgreSQL, sin extensiones (`unaccent` no se usa). Otros diacríticos
  (à, ç…) sí distinguen. Los comodines `%` `_` del término son literales.
- **Destinos**: ficha del alumno, perfil del grupo, evaluaciones del grupo, la
  clase (`/clase/:id`, pantalla 15) o la pantalla. Los proyectos se suman
  cuando existan (pantalla 18, hoy fuera de alcance).
- La consulta puede contener nombres de alumnos: no se loguea.

## Estado actual (scaffolding)

**Implementado**
- Laravel + Sail (pgsql + redis), Sanctum SPA, Spatie Permission.
- Tenancy: `schools`, `users.school_id`, `groups`, `students`; trait + scope +
  `Tenancy`.
- Roles seeder + Policies de ejemplo (group/student).
- Endpoints de auth (`login`/`logout`/`me`) + `LoginRequest`.
- Comando `app:create-user`.
- Tests Pest (auth, tenancy, policies) — verde.
- SPA: cliente axios con CSRF, `AuthProvider`, login + dashboard, ruta protegida;
  tests Vitest — verde.
- CI para ambas apps.

**Pendiente (no implementar hasta pedirlo)**
- Generación de evaluaciones/planificaciones/boletines con IA (Jobs + OpenAI).
- Comunicaciones, materiales, seguimiento pedagógico.
- Storage R2 + URLs firmadas (config lista en `.env`, sin uso todavía).
- Sentry, Playwright E2E.

## Glosario de dominio (código ↔ producto)

Términos sin traducción 1:1, decididos una vez (CLAUDE.md → Language convention).
Se agregan acá a medida que aparecen.

| Producto (UI, español) | Código (inglés) | Notas |
|---|---|---|
| Oferta curricular | `CurricularOffering` (`curricular_offerings`) | Un programa que sigue un grupo-materia; varios por grupo-materia (pantalla 23). La oferta efectiva (`CurricularOfferings::effectiveFor`) = lo guardado + los programas obligatorios del nivel del grupo; decide lo que recibe la IA y la escala de resultados. |
| Régimen obligatorio | `school_curricular_framework.mandatory` | Programa que Aula+ carga para el colegio y no se puede sacar de la oferta. Hoy ANEP para todos (Uruguay, P23-DEC-01); es dato por colegio, no regla fija, y nunca se explica en pantalla. |
| Marco / programa (ANEP, IB MYP, Cambridge…) | `CurricularFramework` | Catálogo global. `planner_family` dice qué campos del planificador trae; `education_levels`, a qué niveles aplica. Lo activa Aula+ por colegio, a pedido (P23-DEC-01). |
| Planificador de unidad | planner (`PlannerFieldDefinition`, `SchoolPlannerField`) | Campos del catálogo (base común + los que pide cada programa) y los propios del colegio (pantallas 23 y 13). |
| Indicador (pantalla 30) | `Indicator` (`indicators`) | Lo que el colegio decide calcular con sus datos: dato tal cual (`raw`), promedio (`average`), puntaje Z (`z_score`, población `grade_year` por defecto, `group` o `school`) o diferencia A − B (`difference`), con franjas y su significado. No es el «indicador de progreso» del boletín, que sigue fuera de alcance. Lo calculan `App\Services\Indicators\IndicatorCalculator` sobre `IndicatorData`. |
| Cruce | `Crossing` (`crossings`) | Una o dos condiciones (`band`, `level`, `below`, `above`) y la lectura que escribe el colegio. La vista previa cuenta por grupo, sin nombres. Nunca para el docente. |
| Dato de afuera / dato cargado | `ExternalMeasure` + `ExternalMeasureValue` | Prueba que el colegio toma con otra herramienta (plantilla, CSV o a mano). Escala declarada, un valor por alumno y año lectivo, siempre el número original. |
| Se toma todos los años | `screening_test_types.taken_every_year` (y `external_measures.taken_every_year`) | Anual: el indicador usa la toma de este año (sin toma, sin dato). No anual: la última de cualquier año, mostrando de qué año es. |
| Nivel de avance como dato | `IndicatorData` (`level`) | En Primaria con escala, la materia es el último nivel de avance de las calificaciones que elige la regla (ver abajo) y funciona como una franja; solo es número (promedio) a través de la equivalencia del colegio (pantalla 24). |
| Regla de calificaciones / categoría de calificación | `App\Enums\SubjectGradeRule`, `grades: {rule, category}` en cada dato de materia de `indicators.inputs` y `crossings.conditions` | Quien arma el indicador o el cruce dice qué notas de la materia usa (respuesta del PO, 17/11): `all` (todas, por defecto y lo que hacían los guardados antes), `all_except_category`, `category_average`, `latest` (solo la última del año) o `latest_of_category`. La «categoría» es el tipo de evaluación (`AssessmentType`: Escrita, Tarea, Proyecto, Oral, Entrega); no hay otra entidad. La regla se muestra siempre junto al dato. Dos reglas sobre la misma materia son dos datos. |
| Franja usada por un cruce | `App\Services\Indicators\CrossingBandUsage` | Un cruce nombra la franja que lee: editar el indicador, guardar un diseño nuevo del screening o volver al anterior no puede sacar ni renombrar esa franja (422 con `bands` y `used_by_crossings`, que la pantalla muestra en castellano: «cambiá primero el cruce»). Los nombres de franja son únicos. |
| Código del alumno en la plantilla | `id_alumno` (id de `students`) | Cada fila del CSV dice quién es por el código o, si no lo trae, por «alumno» (y «grupo»). `POST /external-measures/{id}/match-students` (`App\Actions\Indicators\MatchTemplateStudents`) busca solo en el padrón de este año del colegio; si un nombre se repite, devuelve los candidatos con su código y la persona elige: nunca se adivina. |
| Año lectivo (abierto / cerrado) | `SchoolYear` (`school_years`, `SchoolYearStatus`) | Pantalla 26. `year` = `groups.school_year`. Cerrar no borra ni mueve nada: los grupos de ese año (y de los anteriores) quedan de solo lectura, sus datos y su estructura —el grupo, sus docentes, su oferta curricular y su nivel— (`group.writable` → `GroupPolicy::writeInYear`, una sola regla en `SchoolYearCalendar`; test guardián de rutas en `SchoolYearTest`). Un grupo de un año cerrado no se borra, no cambia de año y no se crean grupos en ese año. Dirección elige desde qué fecha se habilita el cierre. |
| Deshacer el cierre | `POST /v1/school-years/{id}/reopen` (`reopened_at`, `reopened_by_id`) | Solo Dirección, con confirmación, y solo mientras no exista un año posterior (si ya se abrió el siguiente, se bloquea). Vuelve a abrir el año sin tocar períodos, habilitaciones ni datos. |
| Período (Trimestre, Semestre…) | `SchoolPeriod` (`school_periods`) | Por año y por fila: un organismo (`calendar_body`) o, si se separó por programa, un programa (`curricular_framework_id`); con nombre y fecha de inicio; dura hasta el siguiente de la misma fila. Independiente del corte de «Visibilidad de calificaciones» (PA-040). Hoy solo lo usa la pantalla 26. |
| Organismo (del calendario) | `CalendarBody` (`curricular_frameworks.calendar_body`) | ANEP Bachillerato, ANEP Ciclo Básico y Primaria, Cambridge, IB: una fila de períodos por organismo de la oferta del colegio (`PeriodCalendars`). Dato del catálogo. |
| Separar por programa | `school_years.split_calendar_bodies` | Solo Cambridge e IB, con al menos dos programas en la oferta: cada programa arranca con una copia de los períodos del organismo. Volver a una fila solo si todos tienen los mismos períodos. Pasa al año siguiente al abrirlo. |
| Habilitar la carga (en un grupo de un año cerrado) | `GroupWriteWindow` (`group_write_windows`) | Motivo, fecha hasta la que vale (inclusive, día local), quién (`enabled_by_id`) y cuándo (`created_at`); se puede terminar antes (`ended_at`). Pasada la fecha, el grupo vuelve solo a lectura. Mientras dura también deja corregir la estructura del grupo; cada escritura que pasa gracias a ella deja una fila en `audit_logs` sobre la habilitación (quién, cuándo, grupo y ruta; el porqué es su motivo). |
| Recursos disponibles (del colegio / del grupo) | `SchoolResource`, `GroupAddedResource` (`group_added_resources`, `ResourceVisibility`: `group` / `own`), `GroupResourceExclusion` | La huerta, el laboratorio, las computadoras (pantalla 23 P23-REC-02, PA-085). Dirección carga los del colegio (solo ella). En el perfil del grupo, sus docentes, Psicopedagogía y Dirección destildan los que el grupo no tiene a mano (`group_resource_exclusions`) y suman o quitan recursos del grupo, `GroupPolicy::manageResources`. Al sumar, el docente elige «Todo el grupo» (lo ven todos los docentes del grupo, con el nombre de quien lo sumó) o «Solo mío» (entre docentes, solo él; Dirección y Psicopedagogía ven que existe y de quién es). Dirección y Psicopedagogía no tienen recursos propios: lo que suman es para todo el grupo (`own` de ellas → 422). Quita uno de todo el grupo quien gestiona los recursos del grupo; uno «solo mío», solo su autor (`GroupAddedResourcePolicy`). `GroupAddedResource` no es `App\Http\Resources\GroupResource` (el API Resource del grupo). No confundir con Materiales (pantalla 19). |
| Nueva clase / Propuesta de clase (pantallas 14–15) | `AIProposal` tipo `class_session` → `ClassSession` | ClickUp 86e3jujh0. Solo el docente que dicta la materia en el grupo pide clases y evaluaciones a la IA (PA-104: `AIProposalPolicy::generateClass` + `GenerateAIProposalRequest`; Psicopedagogía y Dirección reciben 403); desde una unidad la materia sale del programa (`unit_id`), directo se elige (`subject_id`); sin una de las dos, 422. La IA propone por secciones (`App\Enums\ClassSection`: `opening` apertura, `central_activity` actividad central, `closing` cierre, `materials` materiales, `assessment` evaluación de la clase) y el docente decide cada una (`ClassSectionDecision`: aprobada, editada, rechazada) al confirmar (`POST /ai-proposals/{id}/apply`); regenerar secciones es un pedido nuevo con `reference_proposal_id` + `regenerate_sections` y lo no tocado se copia del borrador anterior. Confirmar crea la `ClassSession` con `sections` (solo lo aprobado/editado) y, si cuelga de una unidad, la vincula a los objetivos elegidos (cuentan como planificados). Una «clase libre» no tiene unidad: no lee planificador ni suma a la cobertura. |
| «Lo que recibe la IA» para una clase | `BuildProposalContext` (`unit`, `unit_planner`, `group_performance`) | `group_performance` (`BuildGroupPerformanceInput`): promedios por evaluación del grupo, de cualquier unidad o de ninguna (PA-116), con la misma visibilidad de notas que el perfil del grupo y solo con 3+ resultados; y cómo se cerraron sus clases recientes. Nunca alumnos ni nombres. `unit_planner` (`BuildUnitPlannerInput`) es el lugar para conectar el planificador de la Unidad (pantalla 13): hoy siempre «no leído». |
| «¿Por qué la IA generó esta propuesta?» / Datos usados | `raw_response.rationale`, `raw_response.data_used`, `raw_response.planner` | `data_used`: qué dato tomó, de dónde sale y por qué. `planner`: si se leyó el planificador (lo pone el servidor, no la IA). |
| Cerrar la clase (Todo / Una parte / Nada) | `POST /class-sessions/{id}/close`, `class_sessions.closing_result` (`ObjectiveCoverage`) + `closed_at` | Un toque (P15-DEC-03), desde el día de la clase, solo su docente. Todo → objetivos `full`; Una parte → por objetivo, «Se dio» `full` (`given_objective_ids`) o «Se empezó» `partial` (`started_objective_ids`, empezado, se termina con «Lo terminé»); el no marcado queda sin registro (`coverage` null: sigue planificado, P15-PRO-05); Nada → `none` (siguen planificados). Volver a cerrar reescribe todos los objetivos de la clase. Todo y Una parte pasan la clase a `delivered`. |
| Espacio de psicopedagogía (pantalla 10) | rutas `/v1/psychopedagogy/*` | Agenda, Acompañantes y Planillas. Ningún docente entra (403 en todo). Dirección ve la carga de la semana por tipo, los acompañantes vigentes (con su rol, sin el historial) y que cada planilla existió (fecha, tipo, grupo o «Individual»); nunca con quién, el texto, los alumnos tratados ni las acciones. |
| Encuentro (de la agenda) | `PsychopedagogyMeeting` (`psychopedagogy_meetings`) | `kind`: `individual_session` (sesión individual, con un alumno) o `meeting` (reunión de uno de los tipos de planilla). Estado `scheduled` / `done` («Programada» / «Realizada»). La nota de una sesión individual es una observación (`Comment`) con su alcance, «solo Psicopedagogía» por defecto (`note_comment_id`). |
| Acompañante terapéutico | `TherapeuticCompanion` (`therapeutic_companions`) | Registro que carga Psicopedagogía (nombre, horas semanales, días, rol del acompañante); **no** es una cuenta ni un rol de usuario. Cuando deja de acompañar se le pone fecha de fin (`ended_on`, «Dejó de acompañar») y queda en el historial del alumno, que solo ve Psicopedagogía; **vigente** = sin fecha de fin. Solo los vigentes mantienen `students.has_therapeutic_companion`, cuentan como activos, salen en la lista de Dirección y en la ficha. |
| Rol del acompañante | `TherapeuticCompanion.role` | Texto libre que escribe Psicopedagogía, sin opciones fijas (ClickUp 1dfd3mc4bge; reemplaza al «motivo»). Lo ve quien puede ver que el alumno tiene acompañante: Psicopedagogía, Dirección en su lista y los docentes del alumno en la fila «Acompañante terapéutico» de la ficha. |
| Tipo de planilla | `MeetingSheetType` (`meeting_sheet_types`) | Del colegio, iguales para todas las planillas (P10-DEC-01). Arrancan los tres de siempre; Dirección los renombra y suma en Colegio y estructura › Institución. No se borran. |
| Planilla | `MeetingSheet` (`meeting_sheets`) | Una por reunión (P10-DEC-02). `draft` hasta «Confirmar el reparto» (`confirmed`); nada baja a las fichas antes. |
| Fragmento (del reparto) | `MeetingSheetFragment` | La parte de la planilla que va a la ficha de un alumno tratado, con su alcance (`ObservationScope`). Al confirmar se vuelve una observación (`comment_id`). Lo escribe Psicopedagogía a mano (`source = manual`): la propuesta de reparto con IA espera a que existan los datos de la familia (respuesta C de Eitán en ClickUp). Arranca en «Solo Psicopedagogía» y se abre a mano por alumno. Al confirmar se antepone «Tipo · 20 nov 2026 — ». |
| Acción (de una planilla) | `MeetingSheetAction` | Con responsable y plazo; al confirmar es una tarea en el Inicio de su responsable (`/v1/meeting-actions/mine`) y le llega un aviso. |
| Materiales (pantalla 19) | `Material` (`materials`) | Material pedagógico: archivo que sube Docente o Psicopedagogía (`source = upload`, disco privado `materials`, solo enlaces temporales) o copia de catálogo de una evaluación confirmada (`source = assessment`, `content` sin marcas de alumno: `StudentMarkCleaner`). Las carpetas del catálogo son materia × grado (`subject_id`, `school_grade_id`). Antes se llamaba «Recursos»; «Recursos disponibles» es otra cosa (la huerta, el laboratorio). |
| Catálogo institucional / Sacar del catálogo | `materials.catalog_status` (`published` / `removed`) | Se publica al guardar (sin aprobación hasta que exista Usuarios y permisos › Aprobaciones) y se avisa a Psicopedagogía y Dirección (`MaterialPublishedNotification`), que lo pueden sacar con un motivo opcional (`removed_reason`, hasta 500 caracteres); a quien lo publicó le llega «Sacaron del catálogo tu material» con ese motivo (`MaterialRemovedNotification`), salvo que lo haya sacado él mismo. Si la dueña lo vuelve a publicar, Psicopedagogía y Dirección reciben otra vez el aviso de publicación y `removed_at/_by_id/_reason` vuelven a null (el historial queda en `audit_logs`). Un archivo solo se publica con «Revisé que no tenga datos de alumnos» (`no_student_data_confirmed_at/_by_id`). |
| Puesta en marcha (pantalla 29) | `SchoolOnboarding`, `OnboardingSource`, `OnboardingLogEntry` | Registro de cómo entró la información del colegio: fechas del encabezado y contacto de Aula+, «Qué se recibió» y «Bitácora». Lo carga el equipo de Aula+ con `php artisan app:onboarding-status|-source|-log` (no hay rol ni pantalla para Aula+); Dirección solo lo lee (`SchoolPolicy::viewOnboarding`). Migración, no sincronización. |
| Régimen ANEP por nivel (habilitado / autorizado) | `SchoolLevelRegime` (`LevelRegime`: `enabled` / `authorized`) | Lo carga Aula+ (`app:onboarding-regime`), nunca lo declara el colegio (P29-DEC-01). Ciclo Básico y Bachillerato se leen «Habilitado» hasta que se cargue otra cosa. Distinto de `school_curricular_framework.mandatory` (qué programa es obligatorio). |
| Corrección de régimen | `SupportMessage::KIND_REGIME_CORRECTION` | «Solicitar corrección» de la pantalla 29: mismo camino que «Ayuda y sugerencias» (se guarda y sale mail a soporte), con máxima urgencia. Después se ofrecen `SUPPORT_WHATSAPP_NUMBER` / `SUPPORT_CONTACT_EMAIL` si están configurados. |
