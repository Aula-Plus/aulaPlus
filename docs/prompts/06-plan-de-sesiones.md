# Plan de sesiones — Aula+

Este documento no es un prompt de implementación — es la guía de cómo usar los 5 anteriores (`01` a `05`) con Claude Code, en corridas nocturnas.

## 1. Orden de ejecución (no es opcional)

```
01-modelo-dominio-multitenancy.md
        ↓
02-roles-permisos.md
        ↓
03-flujos-aprobacion-trazabilidad.md
        ↓
04-seguimiento-institucional.md
        ↓
05-asistente-ia-docente.md
```

Cada sesión asume que la(s) anterior(es) están mergeadas y sus tests pasan. No paralelizar — aunque `04` y `05` parezcan independientes entre sí, `05` usa endpoints y patrones que se construyen en `04` (el perfil de seguimiento como contexto del prompt), así que van en serie igual.

## 2. Cómo correr cada sesión

Por sesión:

1. Confirmar que la rama de la sesión anterior está mergeada a `main` (o a la rama de integración que uses) y que `./vendor/bin/sail test` pasa en `main` antes de arrancar la siguiente.
2. Crear rama nueva: `feature/sesion-0N-<nombre-corto>`.
3. Iniciar la sesión de Claude Code con `/clear` (contexto limpio) — `CLAUDE.md` se carga solo por estar en la raíz del repo.
4. Pegar como prompt inicial el contenido completo del archivo `0N-....md` correspondiente.
5. Si vas a usar `schedule` para que corra de noche sin supervisión, agregá al final del prompt una instrucción explícita de cierre, por ejemplo:

   > "Cuando termines, corré la suite de tests completa, hacé commit de todo con mensajes descriptivos, y dejá un resumen en `docs/prompts/06-plan-de-sesiones.md` bajo la sesión correspondiente (sección 4 de este archivo). No hagas merge a main vos mismo — dejá la rama en un PR listo para revisión."

6. A la mañana siguiente: revisar el diff, correr los tests localmente vos también (no confiar ciegamente en que "pasaron" según el resumen de la sesión), y recién ahí mergear.

## 3. Recomendación de cadencia

No metas más de una sesión por noche las primeras veces, aunque el contenido de una sesión parezca correr rápido — `01` en particular es grande (19 entidades + multi-tenancy) y conviene que la revises con calma antes de construir las siguientes cuatro sesiones encima de una base que todavía no verificaste vos mismo.

Si `01` te queda muy grande para una sola corrida nocturna, se puede partir en dos:
- `01a`: solo migraciones + modelos + relaciones (secciones 1-3 del archivo).
- `01b`: solo factories, seeders y tests de aislamiento (secciones 4-5).

El resto de las sesiones (`02` a `05`) están dimensionadas para entrar en una corrida nocturna cada una.

## 4. Checklist de progreso

Marcar acá a medida que cada sesión se completa y mergea. Cada sesión debería agregar 3-5 líneas de resumen (qué se hizo, qué quedó pendiente/asumido) antes de cerrar, siguiendo los pasos de "Working in this repo" de `CLAUDE.md` (lint/typecheck/test/build según corresponda, PR limpio para CI).

- [ ] **Sesión 1** — Modelo de dominio (sobre el skeleton de tenancy existente)
  Resumen:
- [ ] **Sesión 2** — Roles y permisos
  Resumen:
- [ ] **Sesión 3** — Flujos de aprobación y trazabilidad
  Resumen:
- [ ] **Sesión 4** — Seguimiento institucional
  Resumen:
- [ ] **Sesión 5** — Asistente de IA docente
  Resumen:

## 5. Explícitamente fuera de este plan

No hay sesiones para (ver también la sección "Out of scope for now" de `CLAUDE.md`):

- Integración con SIGED.
- Billing / planes / entitlements por módulo.
- Boletín, Indicador de Progreso, Proyecto.
- Frontend (React + Vite) — este set de prompts es solo API. El frontend necesita su propio set de prompts una vez que la API de estas 5 sesiones esté estable, para no estar generando UI contra un contrato que todavía se mueve.

Cuando quieras encarar alguno de estos frentes, generamos el set de markdowns correspondiente siguiendo el mismo formato.
