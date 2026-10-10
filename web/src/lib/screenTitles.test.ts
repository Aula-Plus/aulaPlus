import { describe, expect, it } from "vitest"
import { screenTitleFor, screenTitles } from "./screenTitles"

describe("screenTitleFor", () => {
  it.each([
    ["/", "Inicio"],
    ["/alumnos", "Alumnos"],
    ["/alumnos/nuevo", "Nuevo alumno"],
    ["/alumnos/3", "Editar alumno"],
    ["/alumnos/3/seguimiento", "Perfil del alumno"],
    ["/clases/12/evaluaciones", "Evaluaciones"],
    ["/clases/12/seguimiento", "Perfil del grupo"],
    ["/clases", "Grupos"],
    ["/clases/nueva", "Nuevo grupo"],
    ["/clases/12", "Editar grupo"],
    ["/configuracion/alertas", "Configurar alertas"],
    ["/alumnos/3/seguimiento/", "Perfil del alumno"],
  ])("names %s as «%s»", (pathname, title) => {
    expect(screenTitleFor(pathname)).toBe(title)
  })

  it("only uses titles the API accepts as screen_name", () => {
    // Mirrors the regex in api/app/Http/Requests/StoreSupportMessageRequest.php.
    for (const { title } of screenTitles) {
      expect(title).toMatch(/^[\p{L}\p{N} ()\-—]+$/u)
      expect(title.length).toBeLessThanOrEqual(100)
    }
  })

  it("returns null for unknown or missing paths", () => {
    expect(screenTitleFor("/no-existe")).toBeNull()
    expect(screenTitleFor("/alumnos/3/otra-cosa")).toBeNull()
    expect(screenTitleFor(null)).toBeNull()
    expect(screenTitleFor("")).toBeNull()
  })
})
