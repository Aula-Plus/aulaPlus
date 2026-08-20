# Sesión 5 — Asistente de IA docente (segundo pilar)

**Depende de:** Sesión 1, Sesión 2, Sesión 3 (autoría sobre lo que se aplica), Sesión 4 (usa el perfil de seguimiento como contexto de entrada al prompt).
**Bloquea a:** nada.
**Contexto persistente:** ya cargado desde `CLAUDE.md`.

## Objetivo

Implementar la generación asistida por IA de `AnnualPlan`, `Unit`, `ClassSession` y `Assessment`, siempre en modo "propone, no decide": el docente revisa un borrador y explícitamente lo aplica o lo descarta. **No** implementar el asistente de redacción de boletines (depende de `ReportCard`, fuera de alcance — ver `CLAUDE.md`).

## 1. Arquitectura general

- Llamadas a la API de Anthropic (`POST /v1/messages`) desde un `Job` en cola, nunca de forma síncrona en el request.
- API key en `ANTHROPIC_API_KEY` (env, nunca hardcodeada — regla 1 del `CLAUDE.md`). Modelo configurable vía `ANTHROPIC_MODEL` (env), con default a un modelo Sonnet vigente — **no hardcodear un string de modelo específico en el código**; dejá el valor en `.env.example` con un comentario de "revisar en la documentación de Anthropic cuál es el modelo Sonnet vigente al momento de deployar".
- Usar el cliente HTTP nativo de Laravel (`Http::withHeaders(...)->post(...)`) contra `https://api.anthropic.com/v1/messages`.

## 2. Entidad `AIProposal`

Tenant-scoped, `Auditable`:

| columna | tipo | notas |
|---|---|---|
| school_id | FK | |
| group_id | FK | |
| requested_by_id | FK → users | |
| type | enum: `annual_plan`,`unit`,`class_session`,`assessment` | |
| input_parameters | jsonb | lo que pidió el docente (tema, duración, foco, etc. — definir un schema mínimo por tipo, no lo dejes completamente libre) |
| context_sent | jsonb | snapshot de qué contexto se le mandó al modelo (para poder auditar/debuggear sin reconstruirlo) — **sin PII innecesaria, ver sección 4** |
| raw_response | jsonb, nullable | respuesta del modelo ya parseada a JSON |
| status | enum: `pending`,`completed`,`error`,`discarded`,`applied` | |
| error_message | text, nullable | |
| applied_to_id | — , nullable | ID de la entidad real creada al aplicar (AnnualPlan/Unit/ClassSession/Assessment) |
| applied_to_type | string, nullable | |

## 3. Endpoints

- `POST /api/v1/groups/{group}/assistant/generate`
  - Body: `{type, parameters}` (validado con Form Request específico por tipo — no un validador genérico).
  - Autorización: mismas reglas que crear la entidad real correspondiente (un teacher solo puede pedir generación para sus propios grupos — reusar las Policies de la sesión 2, no crear una regla nueva).
  - Crea el registro `AIProposal` en estado `pending`, despacha `GenerateAIProposalJob`, devuelve `202 Accepted` con el `id`.
  - Rate limit: máximo 20 generaciones por school por hora (rate limiter de Laravel) — control de costo, no de seguridad, dejalo como constante fácil de ajustar.
- `GET /api/v1/ai-proposals/{id}` — poll de estado. Autorización: el solicitante, o `director`/`psychopedagogue` de la misma school en modo lectura.
- `POST /api/v1/ai-proposals/{id}/apply` — convierte el borrador en la entidad real (ver sección 5). Solo el solicitante puede aplicar.
- `POST /api/v1/ai-proposals/{id}/discard` — marca `discarded`, no crea nada.

## 4. Construcción del prompt y minimización de PII

El contexto que se arma para cada generación debe incluir:

- Perfil del grupo (`Group.group_profile`, nivel, año lectivo).
- Marco curricular y subárbol relevante de `CurricularItem` (filtrado, no el catálogo completo).
- Principios de DUA/UDL como instrucción fija del sistema (no varía por request, va en el system prompt).
- Perfiles de aprendizaje de los alumnos del grupo, **agregados y anonimizados**: no mandes `full_name` ni ningún identificador directo al modelo (regla 11 del `CLAUDE.md`). Mandá un resumen agregado tipo "3 de 22 alumnos tienen accommodations activas en el área de lectoescritura, 1 alumno tiene acompañamiento terapéutico" en vez de la lista alumno por alumno con nombre. Si se necesita personalización a nivel de un alumno específico (`AnnualPlan.student_id` no nulo), ahí sí se manda el perfil de ESE alumno puntual, pero seguís sin mandar su nombre — referencialo por un identificador corto (`Student #1`) dentro del prompt y resolvé el nombre real solo del lado de la aplicación al mostrar el resultado.
- Si el docente marcó una `AIProposal` anterior como referencia (`input_parameters.reference_proposal_id`), incluir su contenido aplicado como few-shot.

Documentar esta lógica en una clase dedicada `App\Actions\AI\BuildProposalContext` — no la mezcles con el Job, para poder testearla de forma aislada sin mockear HTTP.

## 5. Parseo y aplicación

- Instruir al modelo (system prompt) para que devuelva **únicamente JSON**, con un schema fijo por `type`. Definir el schema exacto para cada uno de los 4 tipos antes de escribir el prompt (por ejemplo, para `unit`: `{name, suggested_position, suggested_start_date, suggested_end_date, suggested_curricular_items: [code], sessions: [{title, objective, duration_minutes}]}`).
- Al recibir la respuesta: intentar `json_decode`, si falla marcar `status = error` con el mensaje, no crashear el job. Si el JSON no matchea el schema esperado, mismo tratamiento.
- `POST /ai-proposals/{id}/apply`:
  - Solo permitido si `status = completed`.
  - Crea la entidad real (`AnnualPlan`/`Unit`/`ClassSession`/`Assessment`) usando los datos de `raw_response`, con `created_by`/autoría apuntando al usuario que aplica (no "el sistema") — la decisión final es del docente.
  - Si el tipo es `unit` y la respuesta incluye sesiones sugeridas, crear también las `ClassSession` asociadas en la misma transacción.
  - Registrar evento en `usage_events` (`event_type = ai_proposal.applied`, de la sesión 4) y en `audit_logs`.
  - Actualiza `AIProposal.status = applied`, `applied_to_id`, `applied_to_type`.
  - Todo en una transacción DB.

## 6. Manejo de errores y reintentos

- El Job debe reintentar hasta 2 veces ante error de red/timeout de la API de Anthropic (backoff simple), y marcar `status = error` recién después de agotar los reintentos.
- Timeout de la request HTTP: 60 segundos.
- Loggear errores con el `id` de la `AIProposal`, nunca con el contexto completo enviado (regla 2 y 11 del `CLAUDE.md`).

## 7. Tests

- Test de `BuildProposalContext`: dado un grupo con alumnos con accommodations activas, el contexto generado no contiene ningún `full_name` de alumno.
- Test del Job con el cliente HTTP mockeado (`Http::fake()`): caso de éxito con JSON válido → `status = completed`; caso de JSON inválido → `status = error` sin excepción no controlada; caso de timeout → reintenta y luego marca error.
- Test de `apply`: aplicar una `AIProposal` de tipo `unit` crea la `Unit` y sus `ClassSession` asociadas, con autoría correcta.
- Test: un teacher no puede aplicar una `AIProposal` de otro teacher (403).
- Test de rate limit: la request número 21 en una hora para la misma school devuelve 429.

## 8. Criterios de aceptación

- [ ] `ANTHROPIC_API_KEY` y `ANTHROPIC_MODEL` documentados en `.env.example` con comentario de que el modelo debe revisarse contra la documentación vigente de Anthropic.
- [ ] Ningún nombre de alumno viaja hacia la API de Anthropic — verificado con el test de la sección 7.
- [ ] Los 4 tipos de generación tienen su schema de salida definido y su lógica de aplicación implementada.
- [ ] Flujo completo probado de punta a punta con `Http::fake()`: generar → poll → aplicar → la entidad real existe en DB con la autoría correcta.
- [ ] Todos los tests de la sección 7 en verde.
- [ ] `./vendor/bin/sail test` pasa en verde.
