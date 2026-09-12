# Sesión 10 (frontend 4) — Evaluaciones y resultados (carga mínima)

**Depende de:** Sesión 7 (frontend, `permissions.ts`), Sesión 8 (frontend,
convenciones de `features/*/*.Api.ts`), y del backend de
`docs/prompts/13-evaluaciones-resultados.md` (Sesión 6 backend) ya mergeado.
**Bloquea a:** Sesión 11 (frontend, "Perfil de alumno") — el gráfico de esa
sesión consume `GET /students/{student}/results`, que esta sesión no muestra
todavía pero cuyo dato ya deja cargable.
**Contexto persistente:** ya cargado desde `CLAUDE.md`.

## Objetivo

UI mínima para que un docente cree una evaluación sobre su grupo y cargue la
nota de cada alumno. **No** es el asistente de IA de Bloque 2 — es un
formulario llano. Sin esta sesión, Perfil de alumno no tendría forma de
mostrar datos reales en el gráfico de desempeño (no hay otra pantalla en el
producto hoy donde se cargue una nota).

## 1. Contrato de API

| Método | Ruta |
|---|---|
| GET / POST | `/api/v1/groups/{group}/assessments` |
| PATCH / DELETE | `/api/v1/assessments/{assessment}` |
| POST | `/api/v1/assessments/{assessment}/results` (array upsert) |
| GET | `/api/v1/assessments/{assessment}/results` |

Verificar contra el código real del backend de la Sesión 6 antes de tipar.

## 2. Tipos nuevos (`web/src/types.ts`)

```ts
export interface Assessment {
  id: number
  group_id: number
  teacher_id: number
  type: AssessmentType // reusar si ya existe un tipo equivalente; si no, crear
  purpose: string | null
  duration_minutes: number | null
  administered_at: string // date
}

export interface AssessmentResult {
  student_id: number
  score: number
  feedback: string | null
}
```

Labels en español para `AssessmentType` (`written→"Escrita"`,
`assignment→"Trabajo"`, `project→"Proyecto"`, `oral→"Oral"`,
`submission→"Entrega"`) en el mismo estilo que `roleLabels`.

## 3. Permisos

`canManageAssessments(user, group)` en `permissions.ts` — requiere además
`user.id === group` ownership real, que el frontend no puede validar del todo
sin los datos de vínculo docente-grupo ya usados en otras pantallas (mismo
patrón que el resto de `permissions.ts`: esto es capa de UX, el límite real es
el Controller de la Sesión 6).

## 4. Pantalla

Nueva página `AssessmentsPage` en `web/src/features/assessments/`, ruta
`/grupos/:id/evaluaciones`, link desde `GroupProfilePage`/`GroupTrackingPage`.

- Formulario "Nueva evaluación": tipo (`<select>` de `AssessmentType`), fecha
  (`administered_at`), propósito (opcional). Sin campos de Bloque 2
  (contenido, ítems curriculares, variante).
- Listado de evaluaciones del grupo, ordenado por `administered_at`
  descendente.
- Por evaluación: tabla de alumnos del grupo con un input numérico de nota +
  feedback opcional; "Guardar" envía el array completo a
  `POST .../results` (upsert).

## 5. Tests

- El formulario de nueva evaluación envía el payload correcto.
- La tabla de carga de resultados hace upsert (un solo POST con el array, no
  N requests).
- Un usuario sin `canManageAssessments` no ve el botón de crear ni los inputs
  de carga (solo lectura, si es que ve la pantalla).

## 6. Criterios de aceptación

- [ ] Crear evaluación y cargar notas funciona end-to-end contra el backend
      de la Sesión 6.
- [ ] `npm run lint`, `npm run typecheck`, `npm run test`, `npm run build`
      pasan (desde `web/`).
