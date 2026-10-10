import { describe, expect, it } from "vitest"
import { screenTitleFor } from "./screenTitles"

describe("screenTitleFor", () => {
  it.each([
    ["/", "Inicio"],
    ["/alumnos", "Alumnos"],
    ["/alumnos/nuevo", "Nuevo alumno"],
    ["/alumnos/3", "Editar alumno"],
    ["/alumnos/3/seguimiento", "Perfil del alumno"],
    ["/clases/12/evaluaciones", "Evaluaciones"],
    ["/clases/12/seguimiento", "Perfil del grupo"],
    ["/clases/nueva", "Nueva clase"],
    ["/configuracion/alertas", "Configurar alertas"],
    ["/alumnos/3/seguimiento/", "Perfil del alumno"],
  ])("names %s as «%s»", (pathname, title) => {
    expect(screenTitleFor(pathname)).toBe(title)
  })

  it("returns null for unknown or missing paths", () => {
    expect(screenTitleFor("/no-existe")).toBeNull()
    expect(screenTitleFor("/alumnos/3/otra-cosa")).toBeNull()
    expect(screenTitleFor(null)).toBeNull()
    expect(screenTitleFor("")).toBeNull()
  })
})
