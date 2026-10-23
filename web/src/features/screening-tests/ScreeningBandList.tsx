import type { ScreeningBand, ScreeningColor } from "@/types"

/** Dot colour per band colour (shared by the design and results views). */
export const screeningColorDot: Record<ScreeningColor, string> = {
  red: "bg-red-500",
  orange: "bg-orange-500",
  yellow: "bg-yellow-400",
  green: "bg-green-500",
  blue: "bg-blue-500",
}

/** "0 a 34", "35 a 64", "65 o más": the score range each band covers. */
export function bandRanges(bands: Pick<ScreeningBand, "max">[]): string[] {
  return bands.map((band, index) => {
    const previous = index > 0 ? bands[index - 1].max : null
    const from = previous === null ? null : `más de ${formatScore(previous)}`
    if (band.max === null) {
      return from ?? "cualquier puntaje"
    }
    return from ? `${from} hasta ${formatScore(band.max)}` : `hasta ${formatScore(band.max)}`
  })
}

function formatScore(value: number): string {
  return Number.isInteger(value) ? String(value) : value.toFixed(2).replace(/\.?0+$/, "")
}

/** A design's bands, lowest first, each with its range and meaning. */
export function ScreeningBandList({ bands }: { bands: ScreeningBand[] }) {
  const ranges = bandRanges(bands)
  return (
    <ul className="grid gap-2">
      {bands.map((band, index) => (
        <li key={`${band.name}-${index}`} className="rounded-md border p-3 text-sm">
          <p className="flex items-center gap-2 font-medium">
            <span className={`inline-block h-3 w-3 rounded-full ${screeningColorDot[band.color]}`} aria-hidden />
            {band.name} · {ranges[index]}
          </p>
          {band.meaning && <p className="mt-1 text-muted-foreground">«{band.meaning}»</p>}
        </li>
      ))}
    </ul>
  )
}
