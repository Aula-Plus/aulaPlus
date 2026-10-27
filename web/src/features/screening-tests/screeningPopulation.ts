import type { ScreeningTestType } from "@/types"

/**
 * A screening without a population applies to nobody until one is chosen
 * (ClickUp 86e3t4qjh): it counts no group in its impact view and cannot be
 * applied. Shared copy for the screenings list, the impact view and «Aplicar».
 */
export function hasPopulation(type: Pick<ScreeningTestType, "levels">): boolean {
  return type.levels.length > 0
}

export const NO_POPULATION_LABEL = "Sin población seleccionada"

export const NO_POPULATION_NOTE =
  "Sin población seleccionada: este screening no aplica a ningún grupo hasta que se elija a qué niveles se aplica."
