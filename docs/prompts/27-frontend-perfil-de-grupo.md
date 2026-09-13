# Sesión 15 (frontend) — Perfil de grupo en la UI

**Depende de:** Sesión 11 (frontend, `ScheduledFollowUp` — reusa el tipo y
`scheduledFollowUpsApi.ts`), Sesión 12 (frontend, `AccommodationCategory` y
sus labels), Sesión 13 (frontend, `comments_count` condicional en
`GroupTracking`), Sesión 14 (frontend, patrón de gráfico con Recharts —
esta sesión lo reusa, no lo reinventa), y del backend de
`docs/prompts/21-perfil-de-grupo.md` (Sesión 11 backend) ya mergeado.
**Bloquea a:** nada dentro de este set.
**Contexto persistente:** ya cargado desde `CLAUDE.md`. Ver también
`aulaplus-documento-vivo/documento_aulaplus.html`, pantalla 3 ("Perfil de
grupo").

## Objetivo

Los tres agregados nuevos de `21-perfil-de-grupo.md` sobre `GroupTrackingPage`
(ya existente desde la Sesión 8): ajustes activos por tipo, línea de tiempo
de desempeño del grupo, y seguimientos programados vencidos. Ninguno es una
pantalla nueva — los tres son secciones agregadas a `GroupTrackingPage`.

**Fuera de alcance** (igual que su contraparte backend): solapa "Programa"
(cobertura curricular), sondeo consolidado, PTP/enriquecimiento — ninguno
tiene datos que consumir todavía.

## 1. Contrato de API

Verificar contra el código real del backend de la Sesión 11 antes de tipar.

| Método | Ruta | Query | Notas |
|---|---|---|---|
| GET | `/api/v1/groups/{group}/accommodations-summary` | — | Visible para cualquier rol que vea el grupo, sin gating clínico — nunca nombra alumnos |
| GET | `/api/v1/groups/{group}/performance-timeline` | `from`, `to` | Mismas reglas de autorización que `GET /groups/{group}/tracking` |
| GET | `/api/v1/groups/{group}/scheduled-follow-ups` | `overdue=true` opcional | Reusa el tipo `ScheduledFollowUp` de la Sesión 11 (frontend) |

## 2. Tipos nuevos (`web/src/types.ts`)

```ts
export interface GroupAccommodationSummaryEntry {
  type: string
  category: AccommodationCategory
  student_count: number
}

export interface GroupPerformanceResultPoint {
  assessment_id: number
  assessment_type: AssessmentType
  administered_at: string
  average_score: number
  results_count: number
}

export type GroupPerformanceMarkType =
  | "accommodation_activated"
  | "accommodation_deactivated"
  | "barrier_registered"
  | "concerning_comment"
  | "calendar_event"

export const groupPerformanceMarkTypeLabels: Record<GroupPerformanceMarkType, string> = {
  accommodation_activated: "Adaptaciones activadas",
  accommodation_deactivated: "Adaptaciones desactivadas",
  barrier_registered: "Barreras registradas",
  concerning_comment: "Comentarios preocupantes",
  calendar_event: "Evento de calendario",
}

interface GroupPerformanceMarkBase {
  date: string
}

export type GroupPerformanceMark =
  | (GroupPerformanceMarkBase & {
      type: "accommodation_activated"
      accommodation_type: string
      count: number
    })
  | (GroupPerformanceMarkBase & {
      type: "accommodation_deactivated"
      accommodation_type: string
      count: number
    })
  | (GroupPerformanceMarkBase & { type: "barrier_registered"; count: number })
  | (GroupPerformanceMarkBase & { type: "concerning_comment"; count: number })
  | (GroupPerformanceMarkBase & {
      type: "calendar_event"
      calendar_event_id: number
      title: string
    })

export interface GroupPerformanceTimeline {
  results: GroupPerformanceResultPoint[]
  marks: GroupPerformanceMark[]
}
```

Nota: a diferencia de `PerformanceMark` (alumno, Sesión 14), acá **no** hay
variante `accommodation_instance_override` — el backend de la Sesión 11 no
la incluye en el agregado de grupo (son solo cinco tipos, no seis; ver JSON
de `21-perfil-de-grupo.md` §2). Ningún tipo trae `student_id` ni identificador
de alumno — son conteos agregados por `(tipo, fecha)`.

## 3. `web/src/features/tracking/trackingApi.ts` — función nueva

```ts
export async function fetchGroupAccommodationsSummary(
  groupId: number,
): Promise<GroupAccommodationSummaryEntry[]>
```

## 4. `web/src/features/tracking/performanceApi.ts` — función nueva

```ts
export async function fetchGroupPerformanceTimeline(
  groupId: number,
  range?: { from?: string; to?: string },
): Promise<GroupPerformanceTimeline>
```

## 5. `web/src/features/tracking/scheduledFollowUpsApi.ts` — función nueva

```ts
export async function fetchGroupScheduledFollowUps(
  groupId: number,
  overdue?: boolean,
): Promise<ScheduledFollowUp[]>
```

## 6. Secciones nuevas en `GroupTrackingPage`

- **"Ajustes activos"**: tabla simple `type` / `category` (con
  `accommodationCategoryLabels`) / `student_count`, sin ningún control de
  visibilidad adicional — el endpoint ya es seguro para cualquier rol que
  vea el grupo (a diferencia de la sección de adaptaciones de
  `StudentTrackingPage`, que sí es clínica). No agregar un gate de
  `canAccessClinicalProfile` acá, sería más restrictivo de lo necesario.
- **"Desempeño del grupo"**: mismo patrón visual que `StudentPerformanceChart`
  (Sesión 14) — línea de `average_score` por `administered_at`, tooltip con
  `results_count` (ej. "7.4 (22 de 25 alumnos)"), y las marcas agregadas
  como `ReferenceLine`/`ReferenceDot` con tooltip usando
  `groupPerformanceMarkTypeLabels[mark.type]` + `count` (ej. "Adaptaciones
  activadas: 2"). Extraer la lógica compartida con `StudentPerformanceChart`
  a un componente/hook base si simplifica ambos; si no, duplicar el armado
  del `ComposedChart` es aceptable — decisión del implementador, no una
  obligación de refactor.
- **"Seguimientos vencidos"**: lista de `ScheduledFollowUp` con
  `?overdue=true`, mostrando `description`, `due_date` y un link al alumno
  (`/alumnos/{student_id}/seguimiento`) — a diferencia de las otras dos
  secciones, esta sí incluye `student_id` por entrada (no es un agregado, es
  un listado operativo que ya viene filtrado por
  `ScheduledFollowUpPolicy::view` por alumno, así que mostrar a qué alumno
  corresponde cada fila no expone nada que el viewer no pudiera ya ver
  entrando a ese alumno directamente).

## 7. Tests

- La tabla de "Ajustes activos" se muestra igual para `teacher` que para
  roles school-wide (sin gate adicional) — test explícito de que no se
  oculta por rol.
- El gráfico de desempeño de grupo muestra `results_count` en el tooltip de
  cada punto.
- Las marcas del gráfico de grupo nunca muestran un `student_id` ni datos
  de un alumno individual (test que recorre las cinco variantes de
  `GroupPerformanceMark` y confirma que ninguna tiene esa forma).
- "Seguimientos vencidos" linkea cada fila al `StudentTrackingPage`
  correspondiente.

## 8. Criterios de aceptación

- [ ] Las tres secciones nuevas (ajustes activos, desempeño de grupo,
      seguimientos vencidos) están en `GroupTrackingPage`, consumiendo los
      tres endpoints de la Sesión 11 backend.
- [ ] El gráfico de grupo reusa el patrón de Recharts de la Sesión 14, sin
      una segunda librería de gráficos.
- [ ] `npm run lint`, `npm run typecheck`, `npm run test`, `npm run build`
      pasan (desde `web/`).
