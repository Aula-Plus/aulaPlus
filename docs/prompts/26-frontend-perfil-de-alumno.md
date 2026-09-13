# Sesión 14 (frontend) — Perfil de alumno: gráfico de desempeño

**Depende de:** Sesión 11 (frontend, `23-frontend-seguimiento-programado.md`
— reusa la convención de paneles embebidos en `StudentTrackingPage`) y
Sesión 13 (frontend, `25-frontend-comentarios-alcance.md` — `author_only` ya
filtrado server-side en `concerning_comment`), y del backend de
`docs/prompts/20-linea-tiempo-alumno.md` (Sesión 10 backend) ya mergeado.
**Bloquea a:** Sesión 15 (frontend, "Perfil de grupo") — reusa el patrón de
gráfico y la paleta de marcas que fija esta sesión, no lo reinventa.
**Contexto persistente:** ya cargado desde `CLAUDE.md`. Ver también
`aulaplus-documento-vivo/documento_aulaplus.html`, pantalla 4, decisiones
"G12 · Galia" y "G6 + Eitán".

## Objetivo

El gráfico de línea de desempeño con marcas de `StudentTrackingPage` (pantalla
4, "Perfil de alumno") — consume el agregador de la Sesión 10 backend
(`GET /students/{student}/performance-timeline`). Introduce **Recharts**
como primera librería de gráficos del frontend.

**Fuera de alcance** (igual que su contraparte backend, `20-linea-tiempo-
alumno.md` §Objetivo): rúbrica ítem por ítem de una evaluación generada por
IA, "bandas de período" (rangos de `Unit`), resultado de pruebas de sondeo
en el gráfico — ninguno de los tres tiene datos que consumir todavía.

## 1. Contrato de API

Verificar contra el código real del backend de la Sesión 10 antes de tipar.

| Método | Ruta | Query | Notas |
|---|---|---|---|
| GET | `/api/v1/students/{student}/performance-timeline` | `from`, `to` (fechas, opcionales) | Mismas reglas de autorización que `GET /students/{student}/tracking` |

`marks` es un único array, **siempre presente** en la respuesta — lo que
varía por autorización es qué tipos de marca contiene (ver
`20-linea-tiempo-alumno.md` §1, corregido): las de accommodation/barrier
faltan por completo si el viewer no tiene `view-clinical-profile`;
`concerning_comment` depende de la visibilidad propia del comentario
(`visible_to`/`author_only`, Sesión 13), no del gate clínico; `calendar_event`
siempre está. El cliente **no** debe asumir que `marks` puede venir ausente
como clave — si no hay marcas visibles para el usuario, es un array vacío o
uno que solo trae `calendar_event`.

## 2. Tipos nuevos (`web/src/types.ts`)

```ts
export interface PerformanceResultPoint {
  assessment_id: number
  assessment_type: AssessmentType
  administered_at: string
  score: number
}

export type PerformanceMarkType =
  | "accommodation_activated"
  | "accommodation_deactivated"
  | "accommodation_instance_override"
  | "barrier_registered"
  | "concerning_comment"
  | "calendar_event"

export const performanceMarkTypeLabels: Record<PerformanceMarkType, string> = {
  accommodation_activated: "Adaptación activada",
  accommodation_deactivated: "Adaptación desactivada",
  accommodation_instance_override: "Adaptación desactivada para esta evaluación",
  barrier_registered: "Barrera registrada",
  concerning_comment: "Comentario preocupante",
  calendar_event: "Evento de calendario",
}

interface PerformanceMarkBase {
  date: string
}

export type PerformanceMark =
  | (PerformanceMarkBase & { type: "accommodation_activated"; accommodation_id: number })
  | (PerformanceMarkBase & { type: "accommodation_deactivated"; accommodation_id: number })
  | (PerformanceMarkBase & {
      type: "accommodation_instance_override"
      accommodation_id: number
      assessment_id: number
      reason: string
    })
  | (PerformanceMarkBase & { type: "barrier_registered"; barrier_id: number })
  | (PerformanceMarkBase & { type: "concerning_comment"; comment_id: number })
  | (PerformanceMarkBase & { type: "calendar_event"; calendar_event_id: number; title: string })

export interface StudentPerformanceTimeline {
  results: PerformanceResultPoint[]
  marks: PerformanceMark[]
}
```

Discriminada por `type` (patrón ya usado en el resto del dominio para
distinguir variantes, ej. `AuditAction`) para que cada rama tenga solo los
campos que le corresponden — evita un tipo con diez campos opcionales.

## 3. Dependencia nueva: Recharts

`npm install recharts` desde `web/` — **confirmar antes de instalar que no
está ya en `package.json`** (no lo está a la fecha de este archivo). Es la
primera librería de gráficos del frontend; no agregar una segunda (D3, Chart.js,
etc.) en sesiones futuras sin una razón concreta — Recharts ya cubre lo que
pide `21-perfil-de-grupo.md` (Sesión 15 frontend), que reusa este mismo
patrón.

## 4. `web/src/features/tracking/performanceApi.ts` (archivo nuevo)

```ts
export async function fetchStudentPerformanceTimeline(
  studentId: number,
  range?: { from?: string; to?: string },
): Promise<StudentPerformanceTimeline>
```

Mismo envoltorio `{ data: ... }` que el resto de los endpoints de
seguimiento. Archivo separado de `trackingApi.ts` por el mismo criterio que
`scheduledFollowUpsApi.ts` (Sesión 11): dominio separable, no seguir
agrandando un archivo que ya concentra comentarios/alertas/aprobación/
barreras.

## 5. `StudentPerformanceChart` (`web/src/features/tracking/StudentPerformanceChart.tsx`, nuevo)

Nueva sección "Desempeño" en `StudentTrackingPage`, después de "Evaluaciones
recientes":

- Controles de rango: dos `<Input type="date">` (`from`/`to`), con
  `getCurrentSchoolYear()` (`@/lib/schoolYear`, ya usado en
  `StudentFormPage`) como base para un rango por defecto razonable (ej. todo
  el año lectivo actual) — no dejar el gráfico vacío por defecto por falta
  de rango.
- Gráfico de línea (Recharts `LineChart`/`ComposedChart`), eje X por
  `administered_at`, eje Y por `score`, un punto por entrada de `results`.
- Cada entrada de `marks` se renderiza como una marca sobre el eje X en su
  `date` correspondiente (`ReferenceLine` o `ReferenceDot` de Recharts, a
  elección del implementador), con tooltip al pasar el mouse mostrando
  `performanceMarkTypeLabels[mark.type]` + la fecha + el detalle específico
  del tipo (ej. `reason` para `accommodation_instance_override`, `title`
  para `calendar_event`). Un color/ícono distinto por `type` (usar una
  paleta categórica consistente, no colores repetidos entre tipos).
- Si `results` está vacío, mostrar "Sin evaluaciones en este rango." en vez
  de un gráfico vacío.
- Nunca ocultar la sección completa por falta de `marks` de un tipo
  particular (ej. sin adaptaciones visibles) — el gráfico igual tiene
  sentido mostrando solo `results` y `calendar_event`.

## 6. Tests

- Cambiar el rango (`from`/`to`) dispara un nuevo fetch con esos parámetros.
- Cada `PerformanceMarkType` se renderiza con su label correcto en el
  tooltip — un test por tipo (discriminando por `type`, no por posición).
- `results` vacío muestra el mensaje de "sin evaluaciones", no un gráfico
  roto ni un chart vacío sin explicación.
- El componente no filtra ni oculta marcas por rol — renderiza exactamente
  lo que trae la respuesta (la responsabilidad de filtrar es del backend).

## 7. Criterios de aceptación

- [ ] El gráfico de desempeño consume `GET /students/{student}/performance-timeline`
      y muestra `results` + las seis variantes de `marks` con su label
      correspondiente.
- [ ] Recharts agregado como dependencia nueva, sin librería de gráficos
      duplicada.
- [ ] `npm run lint`, `npm run typecheck`, `npm run test`, `npm run build`
      pasan (desde `web/`).
