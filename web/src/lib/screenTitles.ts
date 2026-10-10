import { matchPath } from "react-router-dom"

/**
 * Generic, human-readable names for the app's screens, keyed by the route
 * patterns declared in `App.tsx`. Used by "Ayuda y sugerencias" so the support
 * email can say «Perfil del alumno (/alumnos/3/seguimiento)» instead of a bare
 * path.
 *
 * The names are deliberately static: several page headers embed a student's or
 * group's name (e.g. "Perfil del alumno — <nombre>"), and that must never leave
 * the app in a support message (CLAUDE.md rule 11). Order matters: the first
 * matching pattern wins, so literal segments ("nuevo") go before ":id".
 */
const screenTitles: { pattern: string; title: string }[] = [
  { pattern: "/", title: "Inicio" },
  { pattern: "/clases", title: "Clases" },
  { pattern: "/clases/nueva", title: "Nueva clase" },
  { pattern: "/clases/:id", title: "Editar clase" },
  { pattern: "/clases/:id/seguimiento", title: "Perfil del grupo" },
  { pattern: "/clases/:id/evaluaciones", title: "Evaluaciones" },
  { pattern: "/clases/:id/pruebas-de-sondeo", title: "Screenings del grupo" },
  { pattern: "/alumnos", title: "Alumnos" },
  { pattern: "/alumnos/nuevo", title: "Nuevo alumno" },
  { pattern: "/alumnos/:id", title: "Editar alumno" },
  { pattern: "/alumnos/:id/seguimiento", title: "Perfil del alumno" },
  { pattern: "/alumnos/:id/historial", title: "Historial del alumno" },
  { pattern: "/alertas", title: "Alertas" },
  { pattern: "/mis-seguimientos", title: "Mis seguimientos" },
  { pattern: "/pruebas-de-sondeo/tipos", title: "Diseño de screenings" },
  { pattern: "/usuarios", title: "Usuarios" },
  { pattern: "/usuarios/nueva", title: "Invitar usuario" },
  { pattern: "/usuarios/:id", title: "Editar usuario" },
  { pattern: "/materias", title: "Materias" },
  { pattern: "/configuracion/alertas", title: "Configurar alertas" },
  { pattern: "/ajustes/comentarios", title: "Configurar comentarios" },
  { pattern: "/adopcion", title: "Tablero de adopción" },
  { pattern: "/ayuda", title: "Ayuda y sugerencias" },
]

/** The generic screen name for a pathname, or `null` when it is not a known screen. */
export function screenTitleFor(pathname: string | null | undefined): string | null {
  if (!pathname) return null
  return screenTitles.find(({ pattern }) => matchPath(pattern, pathname))?.title ?? null
}
