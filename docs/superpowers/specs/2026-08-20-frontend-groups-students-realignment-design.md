# Frontend: realinear Clases y Alumnos al modelo nuevo — Diseño

> `develop` ya tiene mergeadas Sesión 1 (modelo de dominio /
> multi-tenancy, PR #34) y Sesión 2 (roles y permisos, PR #35). El
> frontend (`web/`) quedó desalineado: sigue hablando con la forma
> vieja de `Group`/`Student` (`teacher_id` único, `first_name`/
> `last_name`, `status`, `group_id` único). Esta feature realinea las
> pantallas existentes al modelo y la autorización nuevos.

## Contexto

- `routes/api.php` en `develop` solo expone controllers/rutas reales
  para `groups` y `students` (más `/login`, `/logout`, `/me`,
  `/teachers`). Sesión 2 agregó 13 `Policy` nuevas (Accommodation,
  Barrier, AnnualPlan, Unit, Assessment, ClassSession, Calendar,
  CalendarEvent, CurricularFramework/Catalog/Item, TechnicalReport,
  User) pero **ningún controller/ruta** para esas entidades — quedan
  para una sesión de backend futura.
- `GroupResource`/`StudentResource` actuales (verificado en
  `api/app/Http/Resources/`):
  - `Group`: `id, name, level, school_year, group_profile,
    related_documents, teachers: {id,name}[]`. `teacher_id`/`teacher`
    únicos y `year` (string) ya no existen.
  - `Student`: `id, full_name, photo_url, birth_date, enrollment_year,
    has_therapeutic_companion, groups: {id,name,school_year}[],
    learning_profile, tracking_notes, individual_profile,
    related_documents`. Los 4 últimos campos están **ausentes del
    JSON** (no `null`, ausentes) salvo que el usuario pase
    `StudentPolicy::viewClinicalProfile` (director/psicopedagogo).
    `first_name`/`last_name`/`status`/`family_contact_*`/
    `pedagogical_notes` ya no existen.
- `StudentPolicy` en Sesión 2 amplió creación/edición de alumno a
  `director` **y** `psychopedagogue` (antes solo `director`). La
  policy de `Group` no cambió: crear/editar sigue siendo solo
  `director`.
- No existe en el backend un concepto de "año lectivo actual" — se
  resuelve en el frontend.
- El repo no tiene builds de `web/` corriendo en este chequeo; se
  valida con `npm run lint`, `npm run typecheck`, `npm run test`,
  `npm run build` al cerrar, como exige `CLAUDE.md`.

## Alcance funcional

Realinear únicamente lo que ya tiene backend real:

- `GroupsListPage`, `GroupFormPage` (`features/groups/`).
- `StudentsListPage`, `StudentFormPage` (`features/students/`).
- `types.ts`, `groupsApi.ts`, `studentsApi.ts`.
- 3 componentes de UI nuevos y reutilizables en `components/ui/`.

Explícitamente **no** se agrega ninguna pantalla para Accommodation,
Barrier, AnnualPlan, Unit, Assessment, ClassSession, Calendar,
CalendarEvent, CurricularFramework/Catalog/Item ni administración de
`User` — no tienen endpoint todavía.

## Modelo de datos (frontend)

### `types.ts`

```ts
export interface Group {
  id: number
  name: string
  level: string | null
  school_year: number
  group_profile: unknown | null
  related_documents: unknown | null
  teachers: { id: number; name: string }[]
}

export interface Student {
  id: number
  full_name: string
  photo_url: string | null
  birth_date: string | null
  enrollment_year: number
  has_therapeutic_companion: boolean
  groups: { id: number; name: string; school_year: number }[]
  // Ausentes en el JSON (no null) si el usuario no tiene
  // view-clinical-profile sobre este alumno.
  learning_profile?: unknown
  tracking_notes?: string
  individual_profile?: unknown
  related_documents?: unknown
}
```

Se elimina `StudentStatus`/`studentStatusLabels` (el campo ya no
existe en el backend).

### `lib/schoolYear.ts` (nuevo)

```ts
export function getCurrentSchoolYear(): number {
  return new Date().getFullYear()
}
```

Usado para: filtrar qué `Group`s aparecen en el selector de clase de
`StudentFormPage` (solo `school_year === getCurrentSchoolYear()`), y
qué entrada de `student.groups[]` se muestra en
`StudentsListPage`/`StudentFormPage`.

### `groupsApi.ts` / `studentsApi.ts`

- `GroupInput`: `{ name, level?, school_year, group_profile?,
  related_documents?, teacher_ids?: number[] }` — refleja
  `StoreGroupRequest`.
- `StudentInput`: `{ full_name, photo_url?, birth_date?,
  enrollment_year, has_therapeutic_companion?, group_id?,
  school_year?, learning_profile?, tracking_notes?,
  individual_profile?, related_documents? }` — refleja
  `StoreStudentRequest`. `school_year` se completa automáticamente
  con el `school_year` del grupo elegido, no es un campo que el
  usuario edite directamente.

## Componentes nuevos (`components/ui/`)

Hechos a mano sobre `radix-ui` (ya es dependencia) y `cn()` de
`lib/utils.ts`, mismo patrón que `button.tsx`/`card.tsx`. **No** se
usa la CLI de shadcn/ui para esto (decisión explícita del usuario).

- **`table.tsx`**: `Table`, `TableHeader`, `TableBody`, `TableRow`,
  `TableHead`, `TableCell` — wrappers finos sobre `<table>` nativo
  (no necesita Radix). Reemplaza el `<table>` suelto que hoy tienen
  `GroupsListPage`/`StudentsListPage`.
- **`dialog.tsx`**: sobre `radix-ui`'s `Dialog`. Reemplaza
  `window.confirm()` en los botones "Eliminar" de
  `GroupFormPage`/`StudentFormPage` por un diálogo real (foco
  atrapado, cierre por Escape/overlay).
- **`multi-select.tsx`**: `MultiSelect<T extends { id: number; label:
  string }>`, sobre `radix-ui`'s `Popover` — trigger con chips de
  seleccionados + popover con checkboxes. Genérico (no
  `TeacherMultiSelect` hardcodeado) para que sesiones futuras del
  backend (p. ej. asignar `curricular_framework` a un grupo) lo
  reutilicen sin reescribirlo.

### Flujo DesignSync

Antes de cablear estos 3 componentes a las páginas reales:

1. Crear un proyecto nuevo "Aula+ Design System" en claude.ai/design
   (no existe ninguno para este repo todavía).
2. Armar previews HTML autónomos (mismas clases Tailwind/tokens que
   `index.css`) de cada componente en sus estados clave: tabla con
   roster de ejemplo, diálogo abierto, multi-select con 2-3 docentes
   tildados.
3. Subirlos (`finalize_plan` → `write_files`) para revisión visual
   antes de tocar código de producción.

Nota de riesgo: no está cargada la skill `/design-sync` en esta
sesión, así que los previews son HTML estático escrito a mano
(mismas clases que el componente real), no un render en vivo del
TSX — puede haber divergencia chica entre preview y componente real;
si eso pasa, el TSX real manda sobre el preview.

## Páginas

- **`GroupsListPage`**: usa `Table`. Columna "Docentes" = nombres de
  `teachers[]` separados por coma, o "—". "Nueva clase" solo si
  `isDirector` (sin cambios — Group sigue siendo director-only).
- **`GroupFormPage`**: `name`, `level`, `school_year` (numérico,
  default `getCurrentSchoolYear()`), `MultiSelect` de docentes en vez
  del `<Select>` único (sigue pegando a `/api/teachers`). Botón
  "Eliminar clase" abre `Dialog` en vez de `window.confirm`.
- **`StudentsListPage`**: usa `Table`. Columna "Clase" = la entrada de
  `groups[]` con `school_year === getCurrentSchoolYear()`, o "—". Se
  cae la columna "Estado". **Cambio de gate**: "Nuevo alumno"/
  "Editar" pasan de `isDirector` a `isDirector || isPsychopedagogue`,
  alineado con `StudentPolicy` de Sesión 2.
- **`StudentFormPage`**: `full_name`, `photo_url` (input de texto,
  sin upload — no hay endpoint de storage), `birth_date`,
  `enrollment_year` (requerido), `has_therapeutic_companion`
  (checkbox). Selector de clase filtrado a grupos del año actual; al
  elegir uno, `school_year` se completa solo. Sección clínica
  (`learning_profile`/`individual_profile`/`related_documents` como
  textarea de JSON crudo validado con Zod `.refine(JSON.parse)`,
  `tracking_notes` como textarea de texto plano) se renderiza solo si
  `isDirector || isPsychopedagogue` — mismo gate que
  `viewClinicalProfile` en el backend.

## Testing

- Actualizar `GroupsListPage.test.tsx`, `GroupFormPage.test.tsx`,
  `StudentsListPage.test.tsx`, `StudentFormPage.test.tsx`: mocks a la
  forma nueva; casos nuevos para multi-select (tildar 2 docentes →
  `createGroup` llamado con `teacher_ids: [...]`), diálogo de borrado
  (abrir/confirmar → `deleteGroup`/`deleteStudent` llamado), gate
  `isDirector || isPsychopedagogue` (psicopedagogo ve "Nuevo
  alumno"), sección clínica oculta sin el rol, y JSON inválido en un
  campo clínico bloqueando el submit sin llamar a la API.
- Tests nuevos: `dialog.test.tsx`, `multi-select.test.tsx` (abrir/
  cerrar, seleccionar/deseleccionar, chips correctos en el trigger).
  `table.tsx` no lleva test propio — son wrappers triviales sin
  lógica.
- Manejo de errores: mismo patrón ya existente (mensaje en español al
  `catch`), sin cambios de fondo.
- Antes de cerrar: `npm run lint`, `npm run typecheck`, `npm run
  test`, `npm run build` (`web/`), como exige `CLAUDE.md`.

## Explícitamente fuera de alcance

- Historial completo de membresías de grupo por alumno (solo año
  actual en esta pasada).
- Upload real de `photo_url`/`related_documents` (quedan como texto/
  URL manual).
- Cualquier UI para Accommodation, Barrier, AnnualPlan, Unit,
  Assessment, ClassSession, Calendar, CalendarEvent,
  CurricularFramework/Catalog/Item, o administración de `User` — sin
  endpoint todavía.
- Editor estructurado para los campos clínicos (siguen siendo JSON
  crudo hasta que el backend defina su forma en una sesión futura).
- Migrar a la CLI de shadcn/ui para estos componentes.
