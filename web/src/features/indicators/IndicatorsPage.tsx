import { useCallback, useEffect, useState } from "react"
import { useSearchParams } from "react-router-dom"
import { PageHeader } from "@/components/ui/page-header"
import { useAuth } from "@/features/auth/AuthContext"
import { canOpenIndicators } from "@/lib/permissions"
import { NoAccessPage } from "@/pages/NoAccessPage"
import { CrossChartTab } from "./CrossChartTab"
import { CrossingsTab } from "./CrossingsTab"
import { DataTab } from "./DataTab"
import { IndicatorsTab } from "./IndicatorsTab"
import { fetchIndicatorData, type IndicatorDataCatalog } from "./indicatorsApi"

type Tab = "data" | "indicators" | "crossings" | "chart"

const tabLabels: Record<Tab, string> = {
  data: "Datos",
  indicators: "Indicadores",
  crossings: "Cruces",
  chart: "Gráfico de cruces",
}

/** `?solapa=` value of each tab. */
const tabParams: Record<Tab, string> = {
  data: "datos",
  indicators: "indicadores",
  crossings: "cruces",
  chart: "grafico",
}

const TABS = Object.keys(tabLabels) as Tab[]

/**
 * Screen 30 «Indicadores y cruces» (ClickUp 1dfd3mc4bgp, first delivery: the
 * four tabs). The school decides what is calculated from its data, how each
 * result reads and what a reading says when two data cross. Psicopedagogía
 * and Dirección only (P30-*-01); the AI takes no part (P30-DEC-04). UX gate
 * here; the server decides (IndicatorPolicy, CrossingPolicy,
 * ExternalMeasurePolicy).
 *
 * Red line: no indicator orders students and no group view puts a score next
 * to a name — previews count per group; the chart shows a name only on hover.
 */
export function IndicatorsPage() {
  const { user } = useAuth()
  if (!canOpenIndicators(user)) return <NoAccessPage />
  return <IndicatorsScreen />
}

function IndicatorsScreen() {
  const [searchParams, setSearchParams] = useSearchParams()
  const tab = TABS.find((key) => tabParams[key] === searchParams.get("solapa")) ?? "data"
  const [catalog, setCatalog] = useState<IndicatorDataCatalog | null>(null)
  const [error, setError] = useState<string | null>(null)

  const loadCatalog = useCallback(async () => {
    try {
      setCatalog(await fetchIndicatorData())
      setError(null)
    } catch {
      setError("No pudimos cargar los datos disponibles.")
    }
  }, [])

  useEffect(() => {
    void loadCatalog()
  }, [loadCatalog])

  return (
    <div className="grid gap-6">
      <PageHeader
        title="Indicadores y cruces"
        description="El colegio decide qué se calcula, cómo se lee y qué dice cada lectura"
      />

      <div role="tablist" aria-label="Solapas de Indicadores y cruces" className="flex flex-wrap gap-2">
        {TABS.map((key) => (
          <button
            key={key}
            type="button"
            role="tab"
            aria-selected={tab === key}
            onClick={() => setSearchParams({ solapa: tabParams[key] })}
            className={
              "rounded-md px-3 py-1.5 text-sm font-medium transition-colors " +
              (tab === key ? "bg-primary text-primary-foreground" : "bg-muted text-muted-foreground")
            }
          >
            {tabLabels[key]}
          </button>
        ))}
      </div>

      {error ? (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      ) : catalog === null ? (
        <p className="text-sm text-muted-foreground">Cargando…</p>
      ) : tab === "data" ? (
        <DataTab catalog={catalog} onChanged={loadCatalog} />
      ) : tab === "indicators" ? (
        <IndicatorsTab catalog={catalog} onChanged={loadCatalog} />
      ) : tab === "crossings" ? (
        <CrossingsTab catalog={catalog} />
      ) : (
        <CrossChartTab catalog={catalog} />
      )}
    </div>
  )
}
