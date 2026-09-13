# Sesión 11 (backend) — Perfil de grupo

**Depende de:** Sesión 4 (`GroupTrackingController`), Sesión 6
(`AssessmentResult`), Sesión 7 (`ScheduledFollowUp`), Sesión 8
(`Accommodation.category`, `AccommodationInstanceOverride`), Sesión 9
(`comments_count` corregido, `Comment.author_only`).
**Bloquea a:** Sesión 15 (frontend, "Perfil de grupo").
**Contexto persistente:** ya cargado desde `CLAUDE.md`. Ver también
`aulaplus-documento-vivo/documento_aulaplus.html`, pantalla 3 ("Perfil de
grupo").

## Objetivo

Los tres endpoints agregadores nuevos que necesita Perfil de grupo, además
del `GroupTrackingController` ya existente (Sesión 4, al que la Sesión 9 le
corrigió `comments_count`).

**Fuera de alcance** (dependen de otro bloque, no construido):
- Solapa "Programa" (cobertura curricular por materia) — depende de
  `AnnualPlan`/`Unit` (Bloque 2, sin API).
- Sondeo consolidado — depende de la Sesión 13 (pruebas de sondeo).
- PTP/enriquecimiento — pantalla 9, fuera de alcance (ver `CLAUDE.md`).

## 1. Ajustes activos, agregado por tipo

`GET /api/v1/groups/{group}/accommodations-summary` — para **todos** los
roles que pueden ver el grupo, sin gating clínico adicional: nunca nombra
alumnos, así que no expone nada que el docente no debería ver (línea roja de
la pantalla 3 — "agregado por tipo, nunca nómina").

Respuesta: lista agrupada por `type` (el string libre de `Accommodation`,
no por `category` — dos ajustes pueden compartir `category` pero ser
distintos ajustes concretos):

```jsonc
[
  { "type": "tiempo extra", "category": "access", "student_count": 3 },
  { "type": "ortografía no puntúa", "category": "criteria", "student_count": 1 }
]
```

Solo cuenta `Accommodation` donde `isEffective()` es `true`, de alumnos del
grupo. `student_count` cuenta alumnos distintos, no filas (un alumno con dos
accommodations del mismo `type` cuenta una vez).

## 2. Línea de tiempo de desempeño del grupo

`GET /api/v1/groups/{group}/performance-timeline?from=&to=` — mismas reglas
de autorización que `GET /groups/{group}/tracking` (Sesión 4).

```jsonc
{
  "results": [
    { "assessment_id": 9, "assessment_type": "written", "administered_at": "...", "average_score": 7.4, "results_count": 22 }
  ],
  "marks": [
    { "type": "accommodation_activated", "date": "...", "accommodation_type": "tiempo extra", "count": 2 },
    { "type": "accommodation_deactivated", "date": "...", "accommodation_type": "tiempo extra", "count": 1 },
    { "type": "barrier_registered", "date": "...", "count": 1 },
    { "type": "concerning_comment", "date": "...", "count": 3 },
    { "type": "calendar_event", "date": "...", "calendar_event_id": 4, "title": "..." }
  ]
}
```

Reglas de armado:

- `results`: un punto **por `Assessment`** del grupo (no por ventana de
  tiempo) — `average_score` es el promedio de sus `AssessmentResult`;
  `results_count` es cuántos alumnos tienen nota cargada para esa evaluación
  (puede ser menor al tamaño del grupo si falta cargar alguna). Un
  `Assessment` sin ningún `AssessmentResult` todavía no genera punto.
- Marcas de accommodation/barrier/comentario: **conteo agregado por tipo y
  fecha**, nunca un punto por alumno individual — ni un `student_id`
  identificable en la respuesta (sigue "los ajustes se marcan uno por uno,
  con cuántos alumnos los tienen", G6·Galia, no un punto por alumno). Mismas
  fuentes que la Sesión 10 (`AuditLog` para desactivación, `tone=concerning`
  para comentarios respetando `author_only`/`visible_to`), pero agrupadas
  por `(tipo, fecha)` en vez de listadas una por una.
- `calendar_event`: igual que en la Sesión 10, sin agregación (ya es a nivel
  de colegio, no por alumno).
- Todo el bloque `marks` de accommodation/barrier se omite completo (no
  vacío) si el usuario no pasa `view-clinical-profile` para *ningún* alumno
  del grupo — si lo pasa para al menos uno (ej. teacher que dicta a algunos
  alumnos con perfil clínico visible), el conteo agregado igual no expone
  nada nuevo porque ya es agregado; no hace falta filtrar por alumno acá
  como si fuera detalle individual.

## 3. Sin ranking

Confirmar (y corregir si no es así) que el listado de alumnos que ya arma
`GroupTrackingController` está ordenado alfabéticamente por `full_name`, no
por ningún indicador (alertas abiertas, accommodations). **Verificado ahora
contra el código real: no lo está** — el método hace
`$group->students()->get()` sin ningún `orderBy`, así que el orden depende
del join sobre el pivot `group_student` (efectivamente orden de inserción).
Corregir agregando `orderBy('full_name')` (o el mecanismo equivalente sobre
la relación) y dejar un test que lo confirme explícitamente para que no se
rompa sin querer más adelante.

## 4. Seguimientos programados del grupo

`GET /api/v1/groups/{group}/scheduled-follow-ups?overdue=true` — lista los
`ScheduledFollowUp` (Sesión 7) de los alumnos del grupo, filtrados por
`is_overdue` cuando se pasa el query param. Cada entrada solo se incluye si
el usuario pasa `ScheduledFollowUpPolicy::view` para ese alumno puntual —
para un teacher esto naturalmente se reduce a los alumnos que dicta.

## 5. Tests

- `accommodations-summary`: cuenta alumnos distintos, no filas; solo incluye
  accommodations `isEffective()`.
- `performance-timeline` de grupo: un `Assessment` sin resultados cargados
  no aparece; `average_score` correcto con un set de datos conocido.
- Las marcas de `performance-timeline` de grupo nunca incluyen `student_id`
  ni ningún identificador de alumno.
- El listado de alumnos del grupo está alfabético.
- `scheduled-follow-ups` de grupo: un teacher que dicta el grupo (aparece en
  el pivot `group_teacher` para ese grupo) ve los seguimientos de **todos**
  los alumnos del grupo. **Verificado contra el modelo real: no existe hoy
  el escenario de "grupo compartido donde el docente dicta a algunos
  alumnos y a otros no"** — `teachesGroup`/`teachesStudent` son chequeos a
  nivel de grupo completo (`group_teacher` no tiene columna `subject`, y
  `group_student` obliga `unique(student_id, school_year)`, es decir un
  alumno pertenece a un solo grupo por año). El test relevante es más simple
  de lo que sugería una versión anterior de este archivo: un teacher que
  **no** dicta el grupo en absoluto (no está en `group_teacher` para ese
  grupo) no puede pedir el endpoint sobre él (403, `GroupPolicy`/`view`), y
  uno que sí lo dicta ve los seguimientos de todos sus alumnos.

## 6. Criterios de aceptación

- [ ] Los tres endpoints de las secciones 1, 2 y 4 implementados.
- [ ] Orden alfabético del listado de alumnos confirmado (o corregido) con
      test.
- [ ] Todos los tests de la sección 5 en verde; `./vendor/bin/sail test`
      pasa completo (sin romper Sesiones 1-4, 6-9).
- [ ] `sail bin pint` limpio.
