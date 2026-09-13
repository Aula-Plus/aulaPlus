# Sesión 10 (backend) — Línea de tiempo de desempeño del alumno

**Depende de:** Sesión 6 (`13-evaluaciones-resultados.md` — `AssessmentResult`
es la línea principal), Sesión 8 (`18-ajustes-categoria-instancia.md` —
`AccommodationInstanceOverride` es una de las marcas), Sesión 9
(`19-comentarios-alcance.md` — filtra comentarios "concerning" con
`author_only`/`visible_to`).
**Bloquea a:** Sesión 14 (frontend, "Perfil de alumno" — el gráfico).
**Contexto persistente:** ya cargado desde `CLAUDE.md`. Ver también
`aulaplus-documento-vivo/documento_aulaplus.html`, pantalla 4, decisiones
"G12 · Galia" y "G6 + Eitán".

## Objetivo

El endpoint agregador que arma el gráfico de línea de desempeño con marcas:
junta lo construido en las tres sesiones anteriores (más `Barrier` y
`CalendarEvent`, ya existentes) en una sola vista de solo lectura.

**Fuera de alcance** (dependen de Bloque 2, no construido):
- Rúbrica/puntajes ítem por ítem sobre una evaluación generada por IA
  (pantalla 4, decisión "G11 · Eitán") — depende de la Sesión 5.
- "Bandas de período" en el gráfico (los rangos de `Unit`) — `Unit` no tiene
  API todavía.
- Resultado de pruebas de sondeo en la ficha — depende de la Sesión 13
  (pruebas de sondeo), todavía no construida.

## 1. Endpoint

`GET /api/v1/students/{student}/performance-timeline?from=&to=` — nuevo
endpoint agregador (mismas reglas de autorización que
`GET /students/{student}/tracking`, Sesión 4). Devuelve:

```jsonc
{
  "results": [ /* de AssessmentResult, Sesión 6: { assessment_id, assessment_type, administered_at, score } */ ],
  "marks": [
    { "type": "accommodation_activated", "date": "...", "accommodation_id": 1 },
    { "type": "accommodation_deactivated", "date": "...", "accommodation_id": 1 },
    { "type": "accommodation_instance_override", "date": "...", "accommodation_id": 1, "assessment_id": 9, "reason": "..." },
    { "type": "barrier_registered", "date": "...", "barrier_id": 2 },
    { "type": "concerning_comment", "date": "...", "comment_id": 3 },
    { "type": "calendar_event", "date": "...", "calendar_event_id": 4, "title": "..." }
  ]
}
```

Reglas de armado:

- `results`: reusar la lógica de `GET /students/{student}/results` (Sesión
  6) filtrada por `administered_at` entre `from`/`to`.
- `accommodation_activated`: `Accommodation.created_at` de las accommodations
  del alumno.
- `accommodation_deactivated`: **no existe una columna `deactivated_at`** —
  se deriva de `AuditLog` (`auditable_type = Accommodation`,
  `action = updated`, donde el diff de `changes` incluye `active` pasando de
  `true` a `false`). Reusar `audit_logs` (Sesión 3) en vez de agregar una
  columna redundante.
- `accommodation_instance_override`: de la Sesión 8, `created_at`.
- `barrier_registered`: `Barrier.created_at` de las barreras del alumno.
- `concerning_comment`: comentarios del alumno con `tone = concerning`,
  respetando `visible_to`/`author_only` (Sesión 9) para el usuario que pide
  el endpoint.
- `calendar_event`: todos los `CalendarEvent` del colegio en el rango
  (`CalendarEvent` no está vinculado a alumno/grupo en el modelo actual — es
  a nivel de colegio, ej. "recreo largo", "período de exámenes"; no inventar
  un vínculo que no existe).
- **Todo el bloque `marks` de accommodations/barriers se omite por completo**
  (no un array vacío con footnote — se omite la clave) si el usuario no pasa
  `Gate::allows('view-clinical-profile', $student)`, igual que el resto del
  dominio clínico.

## 2. Tests

- `performance-timeline`: un teacher sin `view-clinical-profile` no recibe
  `marks` de accommodations/barriers pero sí recibe `results` y
  `calendar_event`.
- `accommodation_deactivated` aparece en `marks` cuando `active` pasa de
  `true` a `false`, con la fecha del `AuditLog` correspondiente, y no
  aparece si la accommodation nunca se desactivó.
- `concerning_comment` respeta `author_only`: un comentario privado del autor
  no aparece para otro usuario aunque sea `concerning`.
- Filtrado correcto por `from`/`to` en las seis fuentes.

## 3. Criterios de aceptación

- [ ] `GET /students/{student}/performance-timeline` implementado con las
      seis fuentes de `marks` de la sección 1.
- [ ] Todos los tests de la sección 2 en verde; `./vendor/bin/sail test`
      pasa completo (sin romper Sesiones 1-4, 6, 8, 9).
- [ ] `sail bin pint` limpio.
