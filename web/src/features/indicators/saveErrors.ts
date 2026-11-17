import { isAxiosError } from "axios"

/**
 * Spanish messages for the 422s of screen 30 (the backend's validation
 * messages are English and never shown as-is). Keys only: the server says
 * which field failed, the screen says what to do.
 */

type Errors = Record<string, string[]>

function validationErrors(error: unknown): Errors | null {
  if (!isAxiosError(error) || error.response?.status !== 422) return null
  return ((error.response.data as { errors?: Errors } | undefined)?.errors ?? {}) as Errors
}

const quoted = (names: string[]) => names.map((name) => `«${name}»`).join(", ")

/**
 * A band a crossing uses cannot disappear (CrossingBandUsage on the server):
 * the 422 lists the crossings under `used_by_crossings`. Shared by the
 * indicator form and the screening design editor (screen 08).
 */
export function crossingsUsingBandsMessage(error: unknown): string | null {
  const names = validationErrors(error)?.used_by_crossings
  if (!names || names.length === 0) return null
  return names.length === 1
    ? `El cruce ${quoted(names)} usa una franja que cambiaste o sacaste. Cambiá primero ese cruce en «Indicadores y cruces» y después guardá.`
    : `Los cruces ${quoted(names)} usan franjas que cambiaste o sacaste. Cambiá primero esos cruces en «Indicadores y cruces» y después guardá.`
}

function gradesProblem(errors: Errors): boolean {
  return Object.keys(errors).some((key) => key.includes(".grades"))
}

export function indicatorSaveError(
  error: unknown,
  fallback = "No pudimos guardar el indicador. Revisá los datos y las franjas.",
): string {
  const errors = validationErrors(error)
  if (!errors) return fallback
  const crossings = crossingsUsingBandsMessage(error)
  if (crossings) return crossings
  if (gradesProblem(errors)) return "Elegí qué calificaciones usa cada materia (y la categoría, cuando la regla la pide)."
  if (Object.keys(errors).some((key) => /^bands\.\d+\.name$/.test(key))) return "Dos franjas no pueden llamarse igual."
  if (Object.keys(errors).some((key) => /^inputs\.\d+\.id$/.test(key))) {
    return "Uno de los datos ya no existe, o usa este mismo indicador (directamente o a través de otro)."
  }
  const inputs = errors.inputs?.[0] ?? ""
  if (inputs.includes("twice")) return "El mismo dato está dos veces."
  if (inputs.includes("scale")) {
    return "No se puede combinar un dato con escala con uno sin escala fija (un puntaje Z, una diferencia)."
  }
  if (errors.inputs) return "La cantidad de datos no corresponde a lo que se calcula."
  return fallback
}

export function crossingSaveError(error: unknown, fallback = "No pudimos guardar el cruce. Revisá las condiciones."): string {
  const errors = validationErrors(error)
  if (!errors) return fallback
  if (gradesProblem(errors)) return "Elegí qué calificaciones usa cada materia (y la categoría, cuando la regla la pide)."
  if (Object.keys(errors).some((key) => /^conditions\.\d+\.source\.id$/.test(key))) return "Uno de los datos ya no existe."
  if (Object.keys(errors).some((key) => /^conditions\.\d+\.value$/.test(key))) {
    return "La franja o el nivel elegido ya no existe en ese dato. Elegilo de nuevo."
  }
  return fallback
}
