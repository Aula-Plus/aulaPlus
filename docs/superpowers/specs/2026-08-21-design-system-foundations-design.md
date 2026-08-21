# Design system — fundaciones visuales (color, tipografía, navegación)

> Hasta ahora `web/` usaba la paleta neutra por defecto de shadcn/ui
> (grises sin color de marca) en `web/src/index.css`. Este documento
> define la identidad visual de Aula+ desde cero (no hay marca previa
> que respetar) y su aplicación al código real, no solo a
> documentación. Decidido en sesión de brainstorming con compañero
> visual (`.superpowers/brainstorm/`), iterando sobre paleta, tipografía
> y navegación con el usuario.

## Contexto

- Producto B2B: la compra la decide dirección de escuela, el uso
  diario es de docentes/psicopedagogos/directores — nunca alumnos
  (ver `CLAUDE.md`, "Students are records, not users").
- El producto maneja datos sensibles de menores (perfiles clínicos,
  barreras, acomodaciones) con acceso restringido por rol
  (`viewClinicalProfile` en `StudentPolicy`).
- Pilar de IA: "siempre propone, el docente decide" — es un requisito
  de negocio, no solo de estilo, que el contenido generado por IA sea
  visualmente distinguible del cargado por el docente.
- UDL (Universal Design for Learning) es la base pedagógica del
  producto — el propio sistema de diseño debe sostener accesibilidad
  AA como piso, no como aspiración.

## Decisiones de personalidad y alcance

- **Personalidad:** profesional y cálida. Seria/confiable para vender
  a directivos, sin sentirse clínica ni fría.
- **Alcance de esta pasada:** definir Y aplicar al código
  (`index.css`, componentes reales), no solo documentar.
- **Módulos (seguimiento institucional vs. asistente de IA):**
  experiencia unificada, un solo sistema visual. El color no separa
  "mundos"; se reserva para significar cosas puntuales (marca, estado,
  contenido de IA).
- **Modo oscuro:** no prioritario en esta pasada. Se diseña solo modo
  claro; el bloque `.dark` existente en `index.css` queda sin diseño
  fino (no se elimina, pero tampoco se mantiene actualizado con los
  tokens nuevos).

## Paleta de color

Punto de partida: terracota como color de marca (elegido explorando
10 variantes en el compañero visual — más carácter que un azul/verde
edtech genérico, evita leerse como "gestión escolar aburrida", sin
cruzar a "energético" que hubiera restado seriedad institucional).

### Marca

| Token | Valor | Uso | Contraste vs. blanco |
|---|---|---|---|
| `--primary` | `#A85A35` | Botones primarios, links, foco, wordmark | ~5:1 (AA) |
| `--primary-hover` | `#8C4A2A` | Hover de lo anterior | — |
| `--primary-foreground` | `#FBF8F5` | Texto sobre `--primary` | — |

Deliberadamente en el extremo **mate/apagado** del espectro explorado
(no la variante más vívida): calma, seguro en contraste, no compite
con los colores semánticos.

### Neutrales (cálidos, no gris puro)

| Token | Valor | Uso |
|---|---|---|
| `--background` | `#FBF8F5` | Fondo de página |
| `--foreground` | `#241E1B` | Texto principal |
| `--card` | `#FFFFFF` | Fondo de card/superficie elevada |
| `--border` / `--input` | `#E7DFD8` | Bordes, separadores |
| `--muted` | `#F3EDE7` | Fondos sutiles (hover de filas, chips) |
| `--muted-foreground` | `#7A6F68` | Texto secundario |
| `--secondary` | `#F1EAE3` | Botones/badges secundarios |
| `--secondary-foreground` | `#241E1B` | Texto sobre `--secondary` |
| `--accent` | `#F3EDE7` | Fondo hover de ghost buttons / items de menú |
| `--ring` | `#A85A35` @ 50% | Anillo de foco (mismo primario, no gris) |

Nota: usar neutrales con matiz cálido (no `oklch(x 0 0)` puro como el
shadcn por defecto) para que no choquen con el primario terracota.

### Semántica (estados)

Definida como escala flexible — el producto todavía no fija los
niveles exactos de severidad de las alertas tempranas del dashboard de
seguimiento (fuera de alcance de esta pasada). Los tokens quedan
listos para mapearse cuando esa lógica se defina.

| Token | Valor | Bg tint | Uso previsto |
|---|---|---|---|
| `--success` | `#3F8F5F` | `#DFF0E4` | Estado "ok" |
| `--warning` | `#C98A1F` | `#FBEFD9` | Estado "atención" |
| `--destructive` | `#C23A3A` | `#F8DEDE` | Errores, acciones destructivas (ya existía como `--destructive`; se realinea el valor) |
| `--info` | `#2C6C8C` | `#DCEAF0` | Mensajes informativos **y** contenido generado por IA (mismo tono: ambos son "generado por el sistema, no por el docente") |

Diseño deliberado: el `--info`/IA es un azul frío, lejos en el círculo
cromático del terracota de marca — para que un badge "Sugerido por
IA" nunca se confunda visualmente con una acción o elemento de marca.

### Dato sensible (perfil clínico)

| Token | Valor | Uso |
|---|---|---|
| `--sensitive-background` | `#EFF1F2` | Fondo de sección con datos clínicos/restringidos |
| `--sensitive-border` | `#DADFE2` | Borde de esa sección |
| `--sensitive-foreground` | `#2B3A42` | Encabezado/ícono |
| `--sensitive-muted` | `#4A5559` | Texto de cuerpo dentro de la sección |

Deliberadamente **neutro-frío**, no ámbar/rojo: comunica "acceso
restringido", no "advertencia/error". Aplica a secciones de perfil
clínico cuando el rol del usuario ya tiene acceso (el control de quién
ve qué lo sigue resolviendo la Policy del backend — esto es solo
refuerzo visual, nunca el límite de seguridad).

### Navegación

| Token | Valor | Uso |
|---|---|---|
| `--nav-background` | `#2B3A42` | Fondo del header (`AppLayout`) |
| `--nav-foreground` | `rgba(255,255,255,0.62)` | Links inactivos |
| `--nav-accent` | `#E8946A` | Link activo, wordmark — terracota **claro**, no `--primary` (el primario base no tiene contraste suficiente sobre `--nav-background`; ~2.3:1. Esta variante clara da ~5:1) |
| `--nav-border` | `rgba(255,255,255,0.3)` | Borde del botón "Cerrar sesión" |

Header oscuro minimal, explorado contra dos alternativas (header
blanco sin color, y barra 100% terracota) — elegido porque da
presencia de marca sin competir con los badges de estado del
contenido, que también usan color.

## Tipografía

**Manrope**, self-hosted vía `@fontsource/manrope` (no CDN de Google
Fonts en producción — evita la request a un tercero y el flash de
fuente sin control de `font-display`). Reemplaza el system-font-stack
actual. Elegida sobre Inter por sumar calidez (terminales más
redondeados) sin perder legibilidad en tablas/formularios densos,
que es el uso dominante de la app.

Pesos usados: 400 (texto), 500 (labels), 600 (botones/subtítulos), 700
(títulos).

## Radio y otros tokens

`--radius` se mantiene en `0.625rem` (10px) — ya es redondeado,
coherente con "cálido", no requiere cambio.

## Aplicación al código

1. **`web/src/index.css`**: reemplazar el bloque `:root` con los
   tokens de esta tabla (light mode). El bloque `.dark` queda
   intacto pero sin actualizar (fuera de alcance). Agregar los tokens
   nuevos (`--success`, `--warning`, `--info`, `--sensitive-*`,
   `--nav-*`) tanto en `:root` como en `@theme inline` (mismo patrón
   que ya usa el archivo para `--primary`, `--destructive`, etc.), para
   que generen utilidades Tailwind (`bg-success`, `text-nav-accent`,
   etc.).
2. **Fuente**: agregar dependencia `@fontsource/manrope`, importarla
   en `index.css`, y setear `--font-sans` en `@theme inline`.
3. **`AppLayout.tsx`**: aplicar el header oscuro (`--nav-*`), agregar
   wordmark "Aula+" (hoy no existe ningún logo/nombre en el header).
   No se toca la estructura de links/roles accesibles — los tests
   existentes (`AppLayout.test.tsx`) verifican por rol/texto, no por
   clases, así que no deberían requerir cambios.
4. **Componentes existentes** (`Button`, `Input`, `Card`, `Dialog`,
   `Table`, `ConfirmDialog`, `MultiSelect`, `Select`, `Textarea`,
   `Label`): no requieren cambios de código — ya consumen las
   variables semánticas de Tailwind (`bg-primary`, `border-input`,
   etc.), así que heredan la paleta nueva automáticamente al cambiar
   `index.css`.
5. **Explícitamente fuera de esta pasada**: no se agrega ningún badge
   de "sugerido por IA", indicador de severidad, ni sección de dato
   sensible a página real alguna (`GroupsListPage`, `StudentFormPage`,
   etc.) — esas son features de negocio no implementadas todavía
   (severidad de alertas, marcado de contenido IA), fuera del alcance
   actual del repo según `CLAUDE.md`. Los tokens quedan definidos y
   disponibles para cuando esas features se construyan, pero no se
   inventa UI que no tiene lógica de datos real detrás.
6. **`web/design-previews/*.html`**: actualizar los 10 previews ya
   subidos a DesignSync (proyecto "Aula+ Design System") para reflejar
   la paleta y tipografía nuevas, y volver a sincronizarlos.

## Verificación

- `npm run lint` / `npm run typecheck` / `npm run test` / `npm run
  build` en `web/` deben seguir en verde (igual que exige
  `CLAUDE.md`).
- Chequeo manual de contraste AA para cada par texto/fondo definido
  arriba (ya verificado por cálculo durante el brainstorming; ver
  tabla de contraste de marca).
