import { isAxiosError } from "axios"

/**
 * Maps the field that failed on the server to a Spanish message (the
 * backend's validation messages are English and never shown as-is).
 */
export function createPlanError(err: unknown): string {
  const errors: Record<string, unknown> =
    (isAxiosError(err) ? (err.response?.data as { errors?: Record<string, unknown> } | undefined)?.errors : undefined) ??
    {}
  if (errors.subject_id) {
    return "Ya tenés un programa para ese grupo, materia y año, o no das esa materia en ese grupo."
  }
  if (errors.curricular_framework_id) {
    return "Ese marco curricular ya no está disponible para el colegio."
  }
  return "No pudimos crear el programa. Probá de nuevo."
}
