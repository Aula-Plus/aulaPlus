# Sesión 17 (frontend) — Pruebas de sondeo en la UI

**Depende de:** Sesión 7 (frontend, `permissions.ts`), Sesión 8 (frontend,
convenciones de `features/tracking/*Api.ts` y páginas), y del backend de
`docs/prompts/11-pruebas-de-sondeo.md` (Sesión 13 backend) ya mergeado.
**Bloquea a:** nada dentro de este set — es la última sesión frontend
planificada hasta ahora, en la cola detrás de Evaluaciones/Perfil de
alumno/Perfil de grupo (Sesiones 10-16).
**Contexto persistente:** ya cargado desde `CLAUDE.md`. Ver también
`aulaplus-documento-vivo/documento_aulaplus.html`, pantallas 7 y 8.

## Objetivo

UI para el subsistema de pruebas de sondeo: pantalla de diseño (crear tipo,
armar/editar diseño, aprobar/rechazar) y pantalla de aplicación (crear
aplicación sobre un grupo, hoja imprimible con el mapeo código→alumno, carga
de puntaje por código con semáforo en vivo).

**Fuera de alcance de esta sesión:** mostrar resultados de sondeo dentro de
`StudentTrackingPage`/`GroupTrackingPage` — el backend de esta sesión todavía
no expone ese dato en los aggregates de seguimiento (ver §"Fuera de alcance"
de `11-pruebas-de-sondeo.md`).

## 1. Contrato de API

| Método | Ruta | Rol |
|---|---|---|
| GET | `/api/v1/screening-test-types` | psicopedagogía, dirección |
| POST | `/api/v1/screening-test-types` | psicopedagogía |
| POST | `/api/v1/screening-test-types/{type}/designs` | psicopedagogía |
| POST | `/api/v1/screening-test-designs/{design}/approve` | dirección |
| POST | `/api/v1/screening-test-designs/{design}/reject` | dirección |
| POST | `/api/v1/groups/{group}/screening-test-applications` | psicopedagogía |
| GET | `/api/v1/groups/{group}/screening-test-applications` | psicopedagogía, dirección |
| GET | `/api/v1/screening-test-applications/{application}/roster` | psicopedagogía únicamente |
| GET | `/api/v1/screening-test-applications/{application}/results` | psicopedagogía |
| PATCH | `/api/v1/screening-test-results/{result}` | psicopedagogía |

Verificar contra el código real del backend de la Sesión 13 antes de tipar —
no asumir nombres de campo sin confirmarlos, siguiendo la práctica ya usada en
`09-frontend-flujos-aprobacion-trazabilidad.md`.

## 2. Tipos nuevos (`web/src/types.ts`)

```ts
export type ScreeningColor = "red" | "yellow" | "green"

export interface ScreeningTestType {
  id: number
  name: string
  active: boolean
  current_design: ScreeningTestDesign | null // diseño aprobado vigente, si existe
}

export interface ScreeningTestDesign {
  id: number
  screening_test_type_id: number
  cutoff_low: number
  cutoff_high: number
  meaning_red: string
  meaning_yellow: string
  meaning_green: string
  approved: boolean | null
}

export interface ScreeningTestApplication {
  id: number
  screening_test_type_id: number
  group_id: number
  application_date: string
}

export interface ScreeningTestResult {
  id: number
  code: string
  score: number | null
  color: ScreeningColor | null
}

export interface ScreeningTestRosterEntry {
  code: string
  student_id: number
  student_full_name: string
}
```

Labels en español para `ScreeningColor` (`rojo`/`amarillo`/`verde`) van en
`roleLabels`-style map en `types.ts`, no hardcodeados en los componentes.

## 3. Permisos (`web/src/lib/permissions.ts`)

- `canManageScreeningTests(user)` — psicopedagogía. Cubre crear tipo, crear
  diseño, crear aplicación, cargar resultado, ver roster.
- `canApproveScreeningTestDesign(user)` — dirección. Espejo de
  `canApproveAccommodation`.

Ningún helper habilita a `teacher` — este módulo no tiene vista de docente
(mismo criterio de "línea roja" ya aplicado a PTP/ajustes en sesiones
anteriores).

## 4. Pantalla de diseño (pantalla 8)

Nueva página `ScreeningTestDesignPage` en `web/src/features/screening-tests/`,
ruta `/pruebas-de-sondeo/tipos`, visible en nav solo si
`canManageScreeningTests(user) || canApproveScreeningTestDesign(user)`.

- Lista de `ScreeningTestType` del colegio con su `current_design` (o "sin
  diseño aprobado").
- Formulario "Nuevo tipo" (psicopedagogía).
- Por tipo: formulario de diseño (`cutoff_low`, `cutoff_high`,
  `meaning_red/yellow/green`) — solo psicopedagogía puede enviarlo; validación
  Zod de que `cutoff_low < cutoff_high` (UX únicamente, el backend es la
  frontera real).
- Si hay un diseño con `approved === null`, mostrar badge "Pendiente de
  aprobación" y, solo si `canApproveScreeningTestDesign(user)`, botones
  "Aprobar"/"Rechazar".

## 5. Pantalla de aplicación (pantalla 7)

Nueva página `ScreeningTestApplicationPage`, ruta
`/grupos/:id/pruebas-de-sondeo`, link desde `GroupProfilePage`/`GroupsListPage`
visible solo si `canManageScreeningTests(user)`.

- Formulario "Nueva aplicación": `<select>` de `ScreeningTestType` **filtrado
  a los que tienen `current_design` no nulo** (no se puede aplicar un sondeo
  sin diseño aprobado — replicar esa regla también en el cliente, aunque el
  backend la exija, para no ofrecer una opción que va a fallar) + fecha.
- Listado de aplicaciones existentes del grupo.
- Por aplicación: botón "Hoja para aplicar" que abre una vista con estilo de
  impresión (`@media print`, sin librería de PDF) listando código + espacio en
  blanco para puntaje — usa el endpoint de roster. Esta vista y su acceso son
  exclusivos de psicopedagogía; no reutilizar el componente en ningún lugar
  que pueda verlo otro rol.
- Formulario de carga: tabla por código (**no nombre** — usa el endpoint de
  resultados, no el de roster) con un input numérico de puntaje por fila; al
  perder foco o enviar, `PATCH` el resultado y pintar el semáforo
  (`ScreeningColor`) que devuelve la respuesta — no calcular el color en el
  cliente, usar el que confirma el backend.

## 6. Tests

- `canManageScreeningTests`/`canApproveScreeningTestDesign`: casos por rol.
- El botón "Aprobar"/"Rechazar" del diseño no aparece si `approved !== null`
  o si el usuario no es dirección.
- El `<select>` de tipo en "Nueva aplicación" excluye tipos sin
  `current_design`.
- La tabla de carga de resultados nunca renderiza `student_full_name` (solo
  `code`) — test explícito de que ese campo no llega ni se usa en ese
  componente.
- Un `teacher` no ve el link a ninguna de las dos pantallas.

## 7. Criterios de aceptación

- [ ] Diseño: crear tipo, crear/editar diseño, aprobar/rechazar, todo
      respetando los roles de la sección 3.
- [ ] Aplicación: crear aplicación solo sobre tipos con diseño aprobado, hoja
      imprimible con código+nombre exclusiva de psicopedagogía, carga de
      resultado por código sin exponer nombre.
- [ ] `npm run lint`, `npm run typecheck`, `npm run test`, `npm run build`
      pasan (desde `web/`).
