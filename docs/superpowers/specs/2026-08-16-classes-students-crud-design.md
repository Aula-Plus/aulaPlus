# CRUD de clases y alumnos — Diseño

> Primera feature de negocio del proyecto (hasta ahora el repo era el
> scaffolding de auth/tenancy/roles/CI descripto en `docs/ARCHITECTURE.md`).
> Objetivo: gestionar clases (`groups`) y alumnos (`students`) desde la SPA,
> con las reglas de permisos y campos definidos abajo.

## Contexto

- `Group` (clases) y `Student` (alumnos) ya existen como esqueleto de tenancy
  (migraciones, modelos, `GroupPolicy`/`StudentPolicy` de referencia), pero
  **no tienen ningún endpoint HTTP ni pantalla** — `routes/api.php` solo
  expone login/logout/me. Esta feature agrega la primera superficie CRUD real.
- Antes de diseñar esto se confirmó que el scaffold sigue verde: 17/17 tests
  Pest, 2/2 tests Vitest.
- Fuera de alcance explícito (queda para features futuras, ver
  `docs/ARCHITECTURE.md` → "Pendiente"): generación con IA, comunicaciones,
  materiales (storage), seguimiento pedagógico estructurado, inscripción
  multi-clase con historial.

## Alcance funcional

- CRUD de clases (`groups`): listar, ver, crear, editar, eliminar.
- CRUD de alumnos (`students`): listar, ver, crear, editar, eliminar.
- Un alumno pertenece a **una sola clase a la vez** (el `group_id` nullable
  que ya existe es suficiente; no se agrega historial ni inscripción
  multi-clase en esta pasada).
- Alta de alumno: incluye contacto de familia y notas pedagógicas en texto
  libre. Baja "lógica" vía `status` (no se borra la fila salvo error de
  carga).

## Modelo de datos

### Migración nueva sobre `students`

| Columna | Tipo | Notas |
|---|---|---|
| `status` | string, default `'active'` | Valores: `active`, `inactive`. Ver `App\Enums\StudentStatus` abajo. Reemplaza al hard delete como flujo normal de baja. |
| `family_contact_name` | string, nullable | Nombre del contacto de familia. |
| `family_contact_phone` | string, nullable | Teléfono de contacto. |
| `family_contact_email` | string, nullable | Validado como email en el Form Request. |
| `pedagogical_notes` | text, nullable | Notas libres. El seguimiento pedagógico estructurado (diagnósticos, adaptaciones) es una feature aparte, futura. |

`groups` no cambia: ya tiene `name`, `level`, `year`, `teacher_id`.

### Enum nuevo

```php
// app/Enums/StudentStatus.php
enum StudentStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
```

Mismo patrón que `App\Enums\Role`.

## Autorización (ajuste sobre las Policies existentes)

Regla nueva: **crear y editar clases o alumnos es exclusivo del director**,
sin excepciones. Esto es más estricto que el patrón de referencia actual:

| Policy / método | Hoy | Pasa a ser |
|---|---|---|
| `GroupPolicy::update` | director o docente dueño de la clase | **solo director** |
| `GroupPolicy::create` | solo director | sin cambios |
| `GroupPolicy::delete` | solo director | sin cambios |
| `StudentPolicy::create` | director o psicopedagogo | **solo director** |
| `StudentPolicy::update` | director o psicopedagogo | **solo director** |
| `StudentPolicy::delete` | solo director | sin cambios |
| `view` / `viewAny` (ambas policies) | docente ve lo suyo; psicopedagogo/director ven toda la escuela | sin cambios |

Solo se restringe **quién escribe**; la visibilidad de lectura no cambia. El
aislamiento por escuela (`sharesSchool`) se mantiene igual en todos los
métodos — es la capa de defensa en profundidad que ya existe.

**Impacto en tests existentes:** `GroupPolicyTest` tiene un caso
(`'lets a teacher view only groups they lead'`) que hoy espera
`$teacher->can('update', $own)` → `true`. Con el cambio pasa a `false`. Se
actualiza ese test como parte de esta feature, no se deja roto.

## API

Rutas nuevas, dentro del grupo `auth:sanctum` ya existente en
`routes/api.php`:

```php
Route::apiResource('groups', GroupController::class);
Route::apiResource('students', StudentController::class);
```

- `GroupController` / `StudentController`: controllers REST estándar
  (`index`, `store`, `show`, `update`, `destroy`), autorización vía
  `authorizeResource()` (delega a `GroupPolicy`/`StudentPolicy`).
- `StoreGroupRequest`, `UpdateGroupRequest`, `StoreStudentRequest`,
  `UpdateStudentRequest`: validan input (Form Requests son la autoridad real,
  independientemente de la validación Zod del frontend).
  - `StoreStudentRequest`/`UpdateStudentRequest` validan `family_contact_email`
    como email cuando está presente, y `status` con `Rule::enum(StudentStatus::class)`.
- `GroupResource`, `StudentResource`: forma de respuesta JSON (mismo patrón
  que `UserResource` existente). `StudentResource` incluye el `group`
  relacionado (id + name) para mostrar en la tabla sin N+1 en el frontend.
- `index` respeta el `SchoolScope` automático (no hace falta filtrar
  `school_id` a mano) y sigue el patrón de visibilidad de `view`/`viewAny`:
  un docente que llama `GET /api/groups` recibe solo las clases que lidera,
  no la lista completa filtrada en el cliente. Esto se implementa en el
  controller (`index` aplica el mismo criterio que `Policy::view` a nivel de
  query, no solo un `viewAny` genérico) — evita el problema de exponer todo
  y "ocultar" en el front, que `CLAUDE.md` prohíbe explícitamente.

## Frontend

- **`AppLayout` nuevo**: hoy no existe ningún shell de navegación (solo
  `DashboardPage` suelta). Se agrega un layout simple (barra superior o
  sidebar) con links a "Clases" y "Alumnos", envolviendo las rutas
  protegidas. Visible para cualquier rol autenticado — las policies del
  backend son la barrera real; el frontend solo oculta botones de
  crear/editar/eliminar si el usuario no es director (UX, no seguridad, por
  regla de `CLAUDE.md`).
- **`features/groups/`**: `groupsApi.ts` (mismo patrón que `authApi.ts`),
  página de lista (tabla: nombre, nivel, año, docente a cargo), página de
  crear/editar (react-hook-form + Zod solo para UX).
- **`features/students/`**: `studentsApi.ts`, página de lista (tabla: nombre
  completo, clase, estado), página de crear/editar (incluye selector de
  clase, contacto de familia, notas pedagógicas, estado activo/inactivo).
- **Rutas nuevas en `App.tsx`**, todas bajo `ProtectedRoute` y `AppLayout`:
  `/clases`, `/clases/nueva`, `/clases/:id`, `/alumnos`, `/alumnos/nuevo`,
  `/alumnos/:id`.
- Páginas completas por ruta, no modales — coincide con el patrón
  `LoginPage`/`DashboardPage` ya usado en el repo.
- Labels en español (`roleLabels` ya existe en `types.ts` como referencia;
  se agrega algo equivalente para `status` de alumno: "Activo"/"Inactivo").

## Testing

- **Pest**: feature tests por endpoint × rol, seteando `Tenancy::forSchool()`
  como ya hacen `TenancyTest`/`GroupPolicyTest`. Casos mínimos por recurso:
  - director puede crear/editar/eliminar dentro de su escuela.
  - docente/psicopedagogo no pueden crear/editar (403), pero sí listar/ver
    según las reglas de visibilidad existentes.
  - ningún rol puede tocar un registro de otra escuela (404/403).
  - `StudentPolicyTest` nuevo (no existe hoy), espejando `GroupPolicyTest`.
  - Actualizar el caso de `GroupPolicyTest` que hoy asume que el docente
    puede editar su propia clase.
- **Vitest**: render de las listas (datos mockeados), validación de
  formulario (patrón de `LoginPage.test.tsx`), y que los botones de
  crear/editar no se muestren para un usuario no-director.
- Antes de dar la feature por terminada: `sail test`, `npm run lint`,
  `npm run typecheck`, `npm run test`, `npm run build` — igual que exige
  `CLAUDE.md` antes de cualquier commit.

## Explícitamente fuera de alcance

- Inscripción multi-clase / historial por período.
- Seguimiento pedagógico estructurado (diagnósticos, adaptaciones, alertas).
- Storage de archivos (materiales) — no aplica a esta feature.
- Deploy a Railway/Vercel — se pospone; foco en desarrollo local por ahora,
  con un único deploy de humo a staging cuando esta feature esté funcionando
  en local (decisión tomada en la conversación de análisis previa, no parte
  del diseño técnico en sí).
