import { isAxiosError } from "axios"

/**
 * Screen 26: the server answers 403 `code: school_year_closed` to any write on
 * a group of a closed year (its data and its structure) unless direction
 * enabled loading in it. One Spanish message for every screen that writes.
 */
export function closedYearError(error: unknown): string | null {
  if (!isAxiosError(error)) return null
  return error.response?.data?.code === "school_year_closed"
    ? "El año de este grupo está cerrado: el grupo es solo para leer. Dirección puede habilitar la carga en Año y calendario."
    : null
}
