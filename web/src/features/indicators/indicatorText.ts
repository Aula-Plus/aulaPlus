import type {
  CrossingOperator,
  CrossingPreview,
  DataSource,
  IndicatorCalculation,
  IndicatorDisplayPlace,
  Population,
  Scale,
  SourceType,
  ZScorePopulation,
} from "./indicatorsApi"

/** Spanish labels of screen 30 (code values stay English, CLAUDE.md). */
export const sourceTypeLabels: Record<SourceType, string> = {
  screening: "Screening",
  subject: "Promedio del año",
  external: "Dato cargado",
  indicator: "Indicador",
  assessment: "Evaluación",
}

export const calculationLabels: Record<IndicatorCalculation, string> = {
  raw: "Dato tal cual",
  average: "Promedio",
  z_score: "Puntaje Z",
  difference: "Diferencia (A − B)",
}

export const zPopulationLabels: Record<ZScorePopulation, string> = {
  grade_year: "Mismo grado y año lectivo",
  group: "Su grupo",
  school: "Todo el colegio",
}

export const displayPlaceLabels: Record<IndicatorDisplayPlace, string> = {
  profile_bar: "Ficha · barra dentro del grupo",
  group_band_bars: "Grupo · barras por franja",
  cross_chart: "Gráfico de cruces",
  profile_bell: "Ficha · campana",
  profile_timeline: "Ficha · línea en el tiempo",
  profile_band: "Ficha · franja con su significado",
  group_points: "Grupo · puntos sin nombres",
}

export const operatorLabels: Record<CrossingOperator, string> = {
  band: "en la franja",
  level: "en el nivel de avance",
  below: "menor que",
  above: "mayor que",
}

const number = (value: number) => value.toLocaleString("es-UY", { maximumFractionDigits: 2 })

export function formatNumber(value: number): string {
  return number(value)
}

/** «Screening · Comprensión lectora», as in P30-DAT-02. */
export function sourceLabel(type: SourceType, name: string | null): string {
  return `${sourceTypeLabels[type]} · ${name ?? "(ya no existe)"}`
}

export function scaleText(scale: Scale | null, source?: Pick<DataSource, "number_coverage" | "level_coverage">): string {
  if (source && source.number_coverage === 0 && source.level_coverage > 0) return "Nivel de avance"
  if (!scale) return "Sin escala fija"
  return `${number(scale.min)} a ${number(scale.max)}`
}

/** «94 alumnos con dato», plus how the year is read. */
export function coverageText(source: DataSource): string {
  const count = `${source.coverage} ${source.coverage === 1 ? "alumno" : "alumnos"} con dato`
  if (source.type === "subject" && source.level_coverage > 0) {
    return source.number_coverage > 0
      ? `${count} (${source.level_coverage} por nivel de avance)`
      : `${count} · por nivel de avance`
  }
  if (source.taken_every_year === false && source.latest_year) return `${count} · última toma ${source.latest_year}`
  return count
}

/** The population a Z score was computed in, shown next to it (PO decision 4). */
export function populationText(population: Population): string {
  if (population.kind === "school") return `dentro de todo el colegio (${population.size})`
  if (population.kind === "group") {
    return `dentro de su grupo${population.label ? ` ${population.label}` : ""} (${population.size})`
  }
  return `dentro de ${population.label ?? "su grado"} y año lectivo (${population.size})`
}

/**
 * «3 alumnos de 5.°A · 1 de 5.°B» (PO decision 5): counts per group, never a
 * name.
 */
export function previewText(preview: CrossingPreview): string {
  if (preview.total === 0) return "Hoy no le aparece a ningún alumno"
  return preview.groups
    .map((group, index) => {
      const noun = index === 0 ? ` ${group.count === 1 ? "alumno" : "alumnos"}` : ""
      return `${group.count}${noun} de ${group.group_name}`
    })
    .join(" · ")
}
