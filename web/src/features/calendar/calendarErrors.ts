import { isAxiosError } from "axios"

/**
 * Spanish messages for the Calendar's validation errors (the backend answers
 * in English, keyed by field): the first field that failed decides the text,
 * so «no esté repetido» never shows for a different cause.
 */
const FIELD_MESSAGES: Record<string, string> = {
  subject_id: "Ya no tenés asignado ese grupo y materia.",
  group_id: "Ya no tenés asignado ese grupo y materia.",
  start_time: "Ya tenés ese horario cargado, o la hora no es válida.",
  weekday: "Elegí un día de la semana.",
  room: "El salón puede tener hasta 60 caracteres.",
  title: "Escribí qué es, con hasta 200 caracteres.",
  date: "Elegí una fecha válida.",
  time: "La hora no es válida.",
  location: "El lugar puede tener hasta 120 caracteres.",
  description: "El detalle puede tener hasta 2000 caracteres.",
}

export function calendarErrorMessage(error: unknown, fallback: string): string {
  if (!isAxiosError(error) || error.response?.status !== 422) return fallback
  const fields = Object.keys((error.response.data as { errors?: Record<string, unknown> })?.errors ?? {})
  const field = fields.find((name) => FIELD_MESSAGES[name])
  return field ? FIELD_MESSAGES[field] : fallback
}
