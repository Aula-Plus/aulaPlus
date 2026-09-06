# Sesión 8 (frontend 2) — Seguimiento institucional en la UI

**Depende de:** Sesión 7 (frontend, módulo `web/src/lib/permissions.ts`) y `docs/prompts/04-seguimiento-institucional.md` (backend, ya mergeado a `develop` — rutas bajo `/api/v1`).
**Bloquea a:** Sesión 9 (frontend) — las acciones de aprobación/validación y el historial se agregan sobre las pantallas que esta sesión construye.
**Contexto persistente:** ya cargado desde `CLAUDE.md`.

## Objetivo

Construir el primer pilar del producto en el frontend: las vistas agregadas de seguimiento por alumno y por clase, los comentarios multi-actor, las alertas tempranas, y el panel de adopción para directores. Hoy **no existe ninguna pantalla** para nada de esto — `Accommodation`, `Barrier`, `Comment`, `Alert` no tienen ninguna representación en `web/src/`. Esta sesión la crea.

## 0. Por qué esta sesión va antes que "flujos de aprobación" en el frontend (aunque en el backend fue al revés)

En el backend, `03-flujos-aprobacion-trazabilidad.md` (aprobar/rechazar Accommodation, validar Barrier↔Accommodation) se implementó antes que `04-seguimiento-institucional.md`. En el frontend conviene invertir el orden: hoy no hay ninguna pantalla donde una `Accommodation` o una `Barrier` sean visibles, así que construir primero los botones de aprobar/rechazar los dejaría sin ningún lugar donde mostrarse. Esta sesión (8) construye el contenedor — la vista de seguimiento, donde `Accommodation`/`Barrier` aparecen por primera vez en la UI. La Sesión 9 agrega las acciones de aprobación/validación adentro de esa vista. No te vuelvas a plantear este orden — es una decisión ya tomada.

## 1. Contrato de API (verificado contra el código actual, no inferido de la spec de backend)

Todas las rutas nuevas están bajo `/api/v1` (a diferencia de `/api/groups` y `/api/students`, que no llevan `v1` — no es un error, son dos generaciones de rutas coexistiendo, ver `api/routes/api.php`).

| Método | Ruta | Devuelve |
|---|---|---|
| GET | `/api/v1/students/{id}/tracking` | `StudentTracking` |
| GET | `/api/v1/groups/{id}/tracking` | `GroupTracking` |
| GET | `/api/v1/students/{id}/comments` | `Comment[]` |
| POST | `/api/v1/students/{id}/comments` | `Comment` (201) |
| GET | `/api/v1/groups/{id}/comments` | `Comment[]` |
| POST | `/api/v1/groups/{id}/comments` | `Comment` (201) |
| GET | `/api/v1/students/{id}/alerts` | `Alert[]` |
| GET | `/api/v1/groups/{id}/alerts` | `Alert[]` |
| POST | `/api/v1/alerts/{id}/resolve` | `Alert` |
| GET | `/api/v1/schools/{id}/adoption-dashboard` | `AdoptionDashboard` |

Todas las respuestas siguen el mismo sobre `{ data: ... }` que `groupsApi.ts`/`studentsApi.ts` ya usan.

## 2. Tipos (agregar a `web/src/types.ts`)

Igual que `Student.learning_profile` hoy (ver el comentario existente en `types.ts`), varios campos están **ausentes del JSON, no en `null`,** cuando el usuario no tiene el permiso correspondiente — marcarlos opcionales (`?`), no `| null`.

```ts
export type CommentTone = "positive" | "neutral" | "concerning"

export const commentToneLabels: Record<CommentTone, string> = {
  positive: "Positivo",
  neutral: "Neutral",
  concerning: "Preocupante",
}

export interface Comment {
  id: number
  author_id: number
  commentable_type: "Student" | "Group"
  commentable_id: number
  content: string
  tone: CommentTone | null
  visible_to: Role[] | null // null = visible para todos los roles
  created_at: string
}

export type AlertType = "performance" | "behavior" | "planning_attendance"
export type AlertSeverity = "low" | "medium" | "high"

export const alertTypeLabels: Record<AlertType, string> = {
  performance: "Rendimiento",
  behavior: "Comportamiento",
  planning_attendance: "Asistencia",
}

export const alertSeverityLabels: Record<AlertSeverity, string> = {
  low: "Baja",
  medium: "Media",
  high: "Alta",
}

export interface Alert {
  id: number
  student_id: number
  type: AlertType
  severity: AlertSeverity
  description: string
  resolved: boolean
  resolved_by_id: number | null
  resolved_at: string | null
  created_at: string
}

export interface AssessmentSummary {
  id: number
  group_id: number
  type: string
  variant_number: number
  created_at: string
}

export interface Accommodation {
  id: number
  student_id: number
  type: string
  active: boolean
  description: string
  focus_area: string | null
  requires_external_approval: boolean
  approved: boolean | null
  is_effective: boolean
  created_by_id: number
  created_at: string
  updated_at: string
}

export interface Barrier {
  id: number
  description: string
  coping_strategy: string | null
  active: boolean
}

export interface StudentTracking {
  student: Student
  recent_assessments: AssessmentSummary[]
  accommodations?: Accommodation[]   // ausente si el viewer no pasa view-clinical-profile
  accommodations_count: number       // siempre presente, para todos los roles
  barriers?: Barrier[]               // ídem
  barriers_count: number
  recent_comments: Comment[]         // ya filtrados por visible_to en el backend
  alerts?: Alert[]                   // ídem
  open_alerts_count: number
}

export interface GroupTrackingStudentSummary {
  id: number
  full_name: string
  open_alerts_count: number
  has_active_accommodations: boolean
}

export interface GroupTracking {
  group: Group
  students: GroupTrackingStudentSummary[]
  trend: {
    period_days: number
    assessments_count: number
    comments_count: number
  }
}

export interface AdoptionDashboard {
  teacher_login_rate_30d: number
  teacher_planning_rate_30d: number
  weekly_login_series: { week_start: string; count: number }[]
  weekly_content_series: { week_start: string; count: number }[]
}
```

## 3. Cliente de API

Crear `web/src/features/tracking/trackingApi.ts` con `fetchStudentTracking`, `fetchGroupTracking`, `fetchStudentComments`, `postStudentComment`, `fetchGroupComments`, `postGroupComment`, `fetchStudentAlerts`, `fetchGroupAlerts`, `resolveAlert`, siguiendo el mismo patrón que `groupsApi.ts` (una función por endpoint, desenvuelven `{ data }`).

Crear `web/src/features/adoption/adoptionApi.ts` con `fetchAdoptionDashboard(schoolId)`.

**`visible_to` al crear un comentario — cuidado con este detalle, es una fuente real de bug:** si el formulario de comentario permite elegir a qué roles es visible y el usuario no selecciona ninguno, **no envíes `visible_to: []`**. Un array vacío (a diferencia de `null`/campo ausente) no matchea ninguna regla de `Comment::scopeVisibleToRole` en el backend — el comentario quedaría invisible para todo el mundo, incluido su propio autor. Si no se seleccionó ningún rol, omití el campo del body (o mandá `null` explícito) para heredar el default "visible a todos" que ya maneja el backend.

## 4. Pantallas

- `web/src/features/tracking/StudentTrackingPage.tsx`, ruta `/alumnos/:id/seguimiento`. Enlazar desde una columna nueva en `StudentsListPage.tsx` (visible para cualquier fila, sin gating — el contenido que devuelve el backend ya viene recortado por rol).
  - Sección de evaluaciones recientes (`recent_assessments`).
  - Sección de accommodations: si `accommodations` está presente, listar con `description`, `focus_area`, badge de vigencia usando el campo `is_effective` tal cual (no reimplementar la lógica de `Accommodation::isEffective()` en TypeScript). Si está ausente, mostrar solo `accommodations_count` como texto simple ("3 medidas de apoyo registradas").
  - Sección de barreras: mismo patrón con `barriers`/`barriers_count`.
  - Sección de comentarios: usar el componente `CommentsPanel` de la sección 5.
  - Sección de alertas: si `alerts` está presente, listar con badge de severidad (`alertSeverityLabels`) y tipo (`alertTypeLabels`); si no, mostrar solo `open_alerts_count`. (Los botones de "Resolver" se agregan en la Sesión 9 — acá solo el listado.)
- `web/src/features/tracking/GroupTrackingPage.tsx`, ruta `/clases/:id/seguimiento`. Enlazar desde una columna nueva en `GroupsListPage.tsx`, mismo criterio (sin gating de visibilidad del link).
  - Tabla de `students` (nombre, `open_alerts_count`, `has_active_accommodations` como ✓/—).
  - Bloque de tendencia (`trend.assessments_count`, `trend.comments_count`, `trend.period_days`).
  - Sección de comentarios del grupo, mismo `CommentsPanel`.
- `web/src/features/adoption/AdoptionDashboardPage.tsx`, ruta `/panel-adopcion`. Usar `user.school!.id` del `useAuth()` actual (un director siempre tiene escuela asignada) — no hace falta parámetro de ruta. Gatear el acceso a la ruta con `canViewAdoptionDashboard(user)` (redirect a `/` si no cumple, mismo patrón que `ProtectedRoute`) y agregar el link a `AppLayout.tsx` **solo si** `canViewAdoptionDashboard(user)`. Mostrar `teacher_login_rate_30d`/`teacher_planning_rate_30d` como tarjetas de porcentaje, y las dos series semanales (`weekly_login_series`/`weekly_content_series`) como tablas simples semana/cantidad — **no agregues una librería de gráficos nueva** (`package.json` no tiene ninguna hoy); si en el futuro se quiere un gráfico de verdad, es una decisión aparte.

`AppLayout.tsx` hoy no filtra `navItems` por rol (todos los links son estáticos). Esta es la primera vez que hace falta esa condición — agregarla de forma genérica (`navItems` como función del `user`, o filtrando con `.filter()`) en vez de un `if` puntual solo para adopción, porque no es descabellado que haga falta de nuevo.

## 5. `CommentsPanel` (componente reusable)

Un componente único usado tanto en `StudentTrackingPage` como en `GroupTrackingPage`, con una prop que indica sobre qué entidad opera:

```ts
interface CommentsPanelProps {
  subject: { type: "student" | "group"; id: number }
  comments: Comment[]
  onCommentAdded: () => void
}
```

- Lista los `comments` recibidos por prop (ya vienen filtrados por `visible_to` desde el backend — no filtrar de nuevo en el cliente).
- Formulario: `content` (textarea, requerido), `tone` (select opcional, usando `commentToneLabels`), `visible_to` (multi-select opcional de roles, usando el componente `MultiSelect` ya existente en `web/src/components/ui/multi-select.tsx` y `roleLabels` de `types.ts` — recordar la nota de la sección 3 sobre no mandar `[]`).
- Al enviar, llama `postStudentComment`/`postGroupComment` según `subject.type`, y dispara `onCommentAdded` (mismo patrón `onSaved` que ya usan `GroupFormPage`/`StudentFormPage`).

## 6. Tests

- `trackingApi.test.ts` / `adoptionApi.test.ts`: mockear `api` y verificar que cada función pega al endpoint correcto.
- `StudentTrackingPage.test.tsx`: un caso con `accommodations`/`barriers`/`alerts` presentes (viewer con perfil clínico) y un caso con esos tres campos ausentes (viewer sin acceso), verificando que se muestra el detalle o el contador según corresponda.
- `GroupTrackingPage.test.tsx`: verifica la tabla de estudiantes y el bloque de tendencia.
- `CommentsPanel.test.tsx`: el caso de "no seleccionar ningún rol en visible_to" — verificar que el body enviado NO incluye `visible_to: []`.
- `AdoptionDashboardPage.test.tsx`: un director ve el contenido; un usuario sin `canViewAdoptionDashboard` es redirigido y el link no aparece en `AppLayout`.

## 7. Si esta sesión es demasiado grande para una corrida nocturna

Partirla en dos, siguiendo el mismo criterio que `06-plan-de-sesiones.md` §3 aplicó a la Sesión 1 del backend:

- **8a**: secciones 1-5 salvo alertas y panel de adopción (tracking de alumno/clase + comentarios).
- **8b**: alertas (listado, sin resolver — eso es Sesión 9) + panel de adopción.

## 8. Criterios de aceptación

- [ ] Las cuatro pantallas de la sección 4 existen y son alcanzables desde la navegación (columna "Seguimiento" en Grupos/Alumnos, link condicional de adopción).
- [ ] Ningún componente reimplementa `isEffective()` ni la lógica de `visible_to` — usan los campos ya calculados por el backend.
- [ ] El caso "sin roles seleccionados en `visible_to`" está cubierto por un test y no manda `[]`.
- [ ] `AdoptionDashboardPage` está gateada tanto en la navegación como en la ruta.
- [ ] No se agregó ninguna librería de gráficos nueva.
- [ ] `npm run lint`, `npm run typecheck`, `npm run test` y `npm run build` pasan (correr desde `web/`).
