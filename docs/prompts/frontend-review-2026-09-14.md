# Revisión profunda de frontend — 2026-09-14

Revisión automatizada y no supervisada de las ocho ramas de frontend
(Sesiones 10–17) que quedaron con PR abierto contra `develop` y ninguna
mergeada. Por cada rama se corrieron las cuatro puertas de CI desde `web/`
(`npm ci` → `npm run lint`, `npm run typecheck`, `npm run test -- --run`,
`npm run build`), se hizo revisión profunda contra el spec de la sesión y
contra los guardrails de `CLAUDE.md`, se corrigieron defectos concretos y
optimizaciones de bajo riesgo, y se dejó un comentario en el PR con el
detalle. **Ninguna rama fue mergeada.** Cada rama con cambios se re-validó
(las cuatro puertas en verde) antes de commitear y pushear.

## Alcance y método

- **Ramas objetivo (8):** todas existen en `origin` — **ninguna faltante**.
- **Guardrails aplicados (frontend):** Spanish-en-UI / English-en-código;
  role-gating en la UI es solo UX y **nunca** el borde de seguridad (el chequeo
  real es el backend Policy); HTML generado por IA se sanitiza con el helper
  real DOMPurify (`src/lib/sanitize.ts`), nunca con regex casera; nunca exponer
  PII/campos clínicos del alumno que el backend no envió; tipos de `types.ts`
  deben reflejar el `ResourceJSON` real (nombres + nullabilidad); role-gating
  vía los helpers de `src/lib/permissions.ts`, no chequeos de rol inline;
  manejo de estados loading/empty/error.
- **Contraste con el backend:** cada tipo/endpoint del frontend se verificó
  contra los Resources y Controllers reales bajo `api/app/Http/`.

## Resumen ejecutivo

| Sesión | Rama | PR | Puertas antes | Puertas después | Defectos corregidos | Commit pusheado |
|---|---|---|---|---|---|---|
| 10 | `feature/frontend-sesion-10-evaluaciones-resultados` | #68 | 4/4 ✅ | 4/4 ✅ | 0 | — |
| 11 | `feature/frontend-sesion-11-seguimiento-programado` | #69 | 4/4 ✅ | 4/4 ✅ | 0 | — |
| 12 | `feature/frontend-sesion-12-ajustes-categoria-instancia` | #70 | 4/4 ✅ | 4/4 ✅ | 1 | `f353fc2` |
| 13 | `feature/frontend-sesion-13-comentarios-alcance` | #71 | 4/4 ✅ | 4/4 ✅ | 0 | — |
| 14 | `feature/frontend-sesion-14-perfil-de-alumno` | #72 | 4/4 ✅ | 4/4 ✅ | 1 | `793c3f0` |
| 15 | `feature/frontend-sesion-15-perfil-de-grupo` | #73 | 4/4 ✅ | 4/4 ✅ | 0 | — |
| 16 | `feature/frontend-sesion-16-grupos-listado` | #74 | 4/4 ✅ | 4/4 ✅ | 0 | — |
| 17 | `feature/frontend-sesion-17-pruebas-de-sondeo` | #75 | 4/4 ✅ | 4/4 ✅ | 0 | — |

- **Las 8 ramas** entraron a la revisión con las cuatro puertas ya en verde.
- **2 ramas** recibieron un fix concreto (ambos de tipado de contrato, sin
  cambio de comportamiento en runtime); **6 ramas** estaban limpias y no
  requirieron cambios.
- **Ningún** guardrail de seguridad fue violado por ninguna sesión: sin
  `dangerouslySetInnerHTML` sin sanitizar, sin regex de sanitización casera,
  sin role-gating inline (todo vía `permissions.ts`), sin fabricación ni
  filtrado client-side de PII/campos clínicos (el backend omite los campos
  sensibles y el frontend los trata como opcionales/ausentes), código en
  inglés y UI en español en todas.
- **Warnings pre-existentes y benignos** presentes en todas: un warning
  fast-refresh en `components/ui/button.tsx` (lint) y un warning de tamaño de
  chunk `>500 kB` (build). Ninguno introducido por estas sesiones.

---

## Detalle por rama

### Sesión 10 — Evaluaciones y resultados · PR #68
`feature/frontend-sesion-10-evaluaciones-resultados` — spec `docs/prompts/14-frontend-evaluaciones-resultados.md`

- **Puertas antes:** lint ✅ · typecheck ✅ · test ✅ (16 archivos / 133) · build ✅
- **Puertas después:** idénticas — 4/4 ✅ (sin cambios de código).
- **Defectos corregidos:** ninguno. Rama limpia. `types.ts` (`Assessment` /
  `AssessmentResult`) refleja `AssessmentResource` / `AssessmentResultResource`
  campo por campo; la capa API (`features/assessments/assessmentsApi.ts`)
  coincide con `routes/api.php` (`/api/v1`), los controllers y el payload
  upsert `{ results: [...] }` de `StoreAssessmentResultsRequest`; role-gating
  vía el helper puro `canManageAssessments`; sin `dangerouslySetInnerHTML`, sin
  PII fabricada, identificadores en inglés, UI en español; upsert de un solo
  POST, estados loading/empty/error/read-only y labels de a11y correctos, sin
  refetch redundante.
- **Optimizaciones aplicadas:** ninguna (ya memoiza el payload, batchea el
  upsert y evita refetches).
- **Recomendaciones abiertas:**
  1. Un fallo de mutación setea `error` a nivel página y reemplaza toda la
     pantalla (se pierde la lista) — preferir error/toast inline.
  2. Label de `assignment` es "Tarea" vs. "Trabajo" del spec (se dejó por la
     regla de no traducir textos de UI).
  3. `today()` usa `toISOString()` en UTC — posible off-by-one cerca de
     medianoche en TZ negativa.
  4. Ruta `/clases/:id/evaluaciones` sigue la convención de la app en vez de
     `/grupos/...` del spec (decisión correcta, consistente con la app).
  5. Chunk de build `>500 kB` pre-existente — considerar code-splitting.

### Sesión 11 — Seguimiento programado · PR #69
`feature/frontend-sesion-11-seguimiento-programado` — spec `docs/prompts/23-frontend-seguimiento-programado.md`

- **Puertas antes:** lint ✅ · typecheck ✅ · test ✅ (17 archivos / 140) · build ✅
- **Puertas después:** idénticas — 4/4 ✅ (sin cambios de código).
- **Defectos corregidos:** ninguno. `types.ts` `ScheduledFollowUp` coincide con
  `ScheduledFollowUpResource` campo por campo; sin lógica de rol inline
  (correctamente sin helper nuevo — el Controller del backend es el borde, per
  spec §3); sin `dangerouslySetInnerHTML`; muestra "Usuario #N" (sin fabricar
  PII); estados loading/empty/error presentes; badge de vencido pintado desde
  `is_overdue` del server; resolver actualiza en el lugar sin refetch;
  `resolution_note` omitido cuando está vacío. Tests cubren los 5 casos del §6.
- **Optimizaciones aplicadas:** ninguna (ya usa `useCallback` y consume las
  respuestas del server en el lugar).
- **Recomendaciones abiertas:** comentario de `handleCreated` cosméticamente
  inexacto; el toggle no resetea la lista a estado loading (filas viejas por un
  instante — consistente con `BarrierAccommodationsPanel`); colores de badge
  `bg-red-100 text-red-800` crudos (convención ya establecida en el codebase).
- **Nota:** la rama está apilada sobre la Sesión 10 (contiene el commit
  `f21d198` de assessments) por diseño del PR (mergear la Sesión 10 primero);
  fuera de alcance de esta revisión, no modificado.

### Sesión 12 — Categoría de ajuste y desactivar por instancia · PR #70
`feature/frontend-sesion-12-ajustes-categoria-instancia` — spec `docs/prompts/24-frontend-ajustes-categoria-instancia.md`

- **Puertas antes:** lint ✅ · typecheck ✅ · test ✅ (145/145) · build ✅
- **Puertas después:** 4/4 ✅ (145/145).
- **Defectos corregidos (1):**
  - `web/src/types.ts` — `Accommodation.category` estaba tipado no-nulo, pero
    `AccommodationResource` emite `$this->category?->value` (nulo para filas
    pre-existentes). Mismatch de nullabilidad (guardrail #5). Se amplió el tipo
    de lectura a `AccommodationCategory | null`; `AccommodationInput` sigue
    requerido. Cero cambio de runtime (la SPA ya defendía contra `null` en
    ambos consumidores). Commit **`f353fc2`**.
- **Optimizaciones aplicadas:** ninguna más allá del fix; el diff ya es mínimo
  e idiomático.
- **Verificado (sin cambio):** rutas, bodies y envelopes `{ data }` de los tres
  endpoints coinciden con `trackingApi.ts`; `category` requerido en store y
  update; el form de override toma de `tracking.recent_assessments` sin fetch
  extra y muestra 403/422 en español; `canManageAccommodations` vía helper (sin
  rol inline); sin PII, sin `dangerouslySetInnerHTML`; tests cubren los 5
  requisitos del §6.
- **Recomendaciones abiertas:** UX opcional — mostrar un chip "Sin categoría"
  para ajustes con `category === null` (filas legacy).

### Sesión 13 — Alcance de comentarios · PR #71
`feature/frontend-sesion-13-comentarios-alcance` — spec `docs/prompts/25-frontend-comentarios-alcance.md`

- **Puertas antes:** lint ✅ · typecheck ✅ · test ✅ (17 archivos / 150) · build ✅
- **Puertas después:** idénticas — 4/4 ✅ (sin cambios de código).
- **Defectos corregidos:** ninguno. Implementación fiel al spec.
  `CommentResource` devuelve `author_only:boolean` + `visible_to:Role[]|null`,
  coincide con `types.ts`; `buildScopeFields` mapea las 4 opciones de UI al
  payload correctamente (`everyone` **omite** ambos campos — nunca
  `visible_to: []`; `author_only` envía solo `author_only`; nunca ambos juntos)
  con test por opción + aserción de exclusión mutua; `comments_count` opcional
  en `GroupTracking` coincide con el `when(schoolWide)` del backend, la card se
  oculta cuando es `undefined`; badge "Privado" para `author_only`;
  `CommentsPanel` reutilizado en las pantallas de alumno y grupo; sin rol
  inline; `content` renderizado como texto plano (sin `dangerouslySetInnerHTML`);
  código inglés / UI español.
- **Optimizaciones aplicadas:** ninguna (ya mínimo y consistente).
- **Recomendaciones abiertas:** las opciones "Solo dirección" / "Solo
  psicopedagogía" se ofrecen a todos los roles incluyendo `teacher` — el
  backend valida, así que no es defecto; cualquier restricción futura de las
  opciones de alcance por rol debería ir vía `permissions.ts`, no inline.

### Sesión 14 — Perfil de alumno: gráfico de desempeño · PR #72
`feature/frontend-sesion-14-perfil-de-alumno` — spec `docs/prompts/26-frontend-perfil-de-alumno.md`

- **Puertas antes:** lint ✅ · typecheck ✅ · test ✅ (159/159) · build ✅.
  Recharts `^3.10.1` confirmado en `package.json` + lock; el build lo resuelve.
- **Puertas después:** 4/4 ✅ (159/159).
- **Defectos corregidos (1):**
  - `web/src/features/tracking/StudentPerformanceChart.tsx` — el fetch por rango
    no tenía guarda contra respuestas fuera de orden: cambiar `from` y luego
    `to` dispara dos fetches y el más lento podía pisar la selección más nueva.
    Movido al `useEffect` con flag `ignore` en el cleanup (mismo patrón que
    `AuthProvider`); se removió el `useCallback` ahora innecesario. Commit
    **`793c3f0`**.
- **Optimizaciones aplicadas:** ninguna más allá del fix (la memoización de
  transformaciones del chart se juzgó no claramente beneficiosa — arrays
  pequeños, componente hijo — se dejó como recomendación).
- **Verificado (contrato real):** `StudentPerformanceTimelineController`
  devuelve `{ results, marks }` plano (sin envelope `{ data }`) y
  `performanceApi.ts` coincide; `StudentAssessmentResultResource` ↔
  `PerformanceResultPoint` coinciden; `administered_at` es `date` NOT NULL, el
  tipo no-nulo es correcto; las 6 variantes de `PerformanceMark` coinciden con
  `PerformanceTimelineBuilder`. **PII (#3):** el chart renderiza exactamente los
  `marks` de la respuesta, nunca fabrica marcas clínicas; el gating
  `view-clinical-profile` y la visibilidad de comentarios se resuelven
  server-side; `reason`/`title` van como texto en `<title>` SVG (sin HTML/XSS);
  sin rol inline ni filtrado client-side.
- **Recomendaciones abiertas:** agregar nombre accesible al SVG del chart
  (`role="img"` / `aria-label`); el `tickFormatter` parsea `YYYY-MM-DD` como
  medianoche UTC (posible off-by-one en TZ negativa — bajo impacto en UTC-3);
  memoización opcional si los datasets crecen.

### Sesión 15 — Perfil de grupo · PR #73
`feature/frontend-sesion-15-perfil-de-grupo` — spec `docs/prompts/27-frontend-perfil-de-grupo.md`

- **Puertas antes:** lint ✅ · typecheck ✅ · test ✅ (21 archivos / 170) · build ✅
- **Puertas después:** idénticas — 4/4 ✅ (sin cambios de código).
- **Defectos corregidos:** ninguno. Implementación correcta y completa.
  `types.ts` ↔ Resources reales de los tres endpoints (`GroupProfileController`,
  `ScheduledFollowUpController::indexForGroup`, `ScheduledFollowUpResource`);
  **solo agregado** — ninguna de las 5 variantes de `GroupPerformanceMark` lleva
  `student_id` y un test explícito recorre las cinco y asegura que no hay fuga
  por alumno; sin role-gating inline (la tabla "Ajustes activos" sin gate es
  correcta según spec y probada como agnóstica de rol); sin
  `dangerouslySetInnerHTML` ni regex casera; código inglés / UI español; estados
  loading/empty/error en las tres secciones; reutiliza Recharts (sin segunda
  librería).
- **Optimizaciones aplicadas:** ninguna.
- **Recomendaciones abiertas:** `GroupAccommodationSummaryEntry.category`
  (`types.ts`) está tipado no-nulo pero el backend puede emitir `category: null`
  para un ajuste legacy (columna nullable, `category?->value`). Renderiza celda
  en blanco, sin crash. Es la **misma** cuestión que corrigió la Sesión 12 en el
  tipo hermano `Accommodation.category` — ver la nota de consistencia
  cross-branch abajo.

### Sesión 16 — Columna "Seguimiento activo" en Grupos · PR #74
`feature/frontend-sesion-16-grupos-listado` — spec `docs/prompts/28-frontend-grupos-listado.md`

- **Puertas antes:** lint ✅ · typecheck ✅ · test ✅ (21 archivos / 172) · build ✅
- **Puertas después:** idénticas — 4/4 ✅ (sin cambios de código).
- **Defectos corregidos:** ninguno. El commit tipa `active_tracking_count?:
  number` como **opcional** con `?? 0` al renderizar, divergiendo del spec §2
  (que pedía campo requerido). Se inspeccionó el backend real y se confirmó que
  la **implementación es correcta y el spec está desactualizado**:
  `GroupResource.php:31-34` emite el campo con
  `$this->when(... getAttribute('active_tracking_count') !== null ...)`, presente
  solo en el index y omitido en show/store/update; `GroupController.php:33` en
  el index siempre lo setea (`0` si no hay). Guardrail #5 manda reflejar el
  Resource real, así que el tipo opcional es lo correcto. Además: sin mismatch de
  `colSpan` (loading/empty/error usan `<p>`, no filas de tabla), sin
  `dangerouslySetInnerHTML`, sin PII (solo conteo agregado), sin gate/permiso
  nuevo, columna ubicada entre "Docentes" y las acciones; tests cubren
  teacher-sin-gate y `0`-renderiza-`0`.
- **Optimizaciones aplicadas:** ninguna.
- **Recomendaciones abiertas:** corregir el spec `docs/prompts/28-frontend-grupos-listado.md`
  §2 — dice erróneamente que el campo está siempre presente; el backend real lo
  protege con `when()` (solo en el index). Corregir el spec evita que una sesión
  futura reintroduzca un tipo requerido.

### Sesión 17 — Pruebas de sondeo · PR #75
`feature/frontend-sesion-17-pruebas-de-sondeo` — spec `docs/prompts/12-frontend-pruebas-de-sondeo.md`

- **Puertas antes:** lint ✅ · typecheck ✅ · test ✅ (25 archivos / 197) · build ✅
- **Puertas después:** idénticas — 4/4 ✅ (sin cambios de código).
- **Defectos corregidos:** ninguno. Feature preciso en contrato y limpio en
  guardrails. `types.ts` refleja cada `ScreeningTest*Resource` incluyendo
  nullabilidad; el campo de roster es correctamente `full_name` (nullable) en
  `types.ts` y en `ScreeningTestRosterSheet.tsx`, coincidiendo con
  `ScreeningTestApplicationController::roster` (no el `student_full_name` del
  borrador del spec); role-gating solo vía helpers de `permissions.ts`
  (`canManageScreeningTests`, `canApproveScreeningTestDesign`), cada uno citando
  su Policy, sin `roles.includes` inline; **cuatro ojos:**
  `ScreeningTestDesignCard.tsx` **oculta** (no deshabilita) Aprobar/Rechazar a
  menos que esté pendiente Y sea director, coincidiendo con
  `ScreeningTestDesignPolicy` (con test); **sin fuga de PII:** el editor de
  resultados se indexa solo por `code`, con test que asegura que no se renderiza
  ningún nombre (`ScreeningTestResultResource` no envía `student_id`/nombre);
  hoja de impresión con `@media print` (sin librería PDF); sin
  `dangerouslySetInnerHTML`/regex; sin identificadores en español.
- **Optimizaciones aplicadas:** ninguna que valga el churn/riesgo.
- **Recomendaciones abiertas:** (1) los directores no pueden ver diseños
  pendientes de otras sesiones — necesitaría un endpoint backend de
  pending-designs; (2) el `today()` por defecto es UTC (cosmético en UTC-3);
  (3) la regla de corte de Zod es `<` estricto mientras el backend permite
  `gte` — intencional per spec §4.

---

## Nota de consistencia cross-branch

Las Sesiones 12 y 15 tocan el mismo concepto de dominio (`category` de un
ajuste) en dos tipos distintos:

- **Sesión 12** corrigió `Accommodation.category` → `AccommodationCategory | null`
  (commit `f353fc2`), porque `AccommodationResource` emite `category?->value`.
- **Sesión 15** dejó `GroupAccommodationSummaryEntry.category` como no-nulo,
  registrado como recomendación (no defecto en su rama, ya que renderiza celda
  vacía sin crash).

Cuando ambas ramas se integren a `develop`, conviene unificar el criterio:
tipar **ambos** como `… | null` con un label de fallback ("Sin categoría").
No se cambió en la rama de la Sesión 15 para no exceder su alcance, pero queda
señalado aquí para resolverlo de una vez al mergear.

## Recomendaciones transversales

- **Fecha en UTC** (`toISOString()` / `new Date('YYYY-MM-DD')`): aparece en las
  Sesiones 10, 14 y 17. Bajo impacto en Uruguay (UTC-3) pero conviene un helper
  común de fecha local si el patrón se sigue reusando.
- **A11y de gráficos** (Sesión 14): agregar nombre accesible al SVG; aplicable
  también a Recharts en la Sesión 15 al integrar.
- **Correcciones de spec** (Sesión 16): el spec §2 describe el campo como
  siempre presente cuando el backend lo protege con `when()`.

## Estado final

- **Puertas:** 4/4 en verde en las 8 ramas, antes y después.
- **Pushes:** `f353fc2` (Sesión 12) y `793c3f0` (Sesión 14), ambos confirmados
  en `origin`.
- **Comentarios de PR:** publicados en los 8 PRs (#68–#75).
- **Merges:** ninguno — todas las ramas quedan como PR abierto contra `develop`
  para revisión humana.
