import { useState } from "react"
import { useAuth } from "@/features/auth/AuthContext"
import { canManageCurricularStructure } from "@/lib/permissions"
import { PageHeader } from "@/components/ui/page-header"
import { cn } from "@/lib/utils"
import { CurricularOfferingTab } from "./CurricularOfferingTab"
import { PlannersTab } from "./PlannersTab"
import { NoAccessPage } from "@/pages/NoAccessPage"

type Tab = "offering" | "planners"

const TABS: { id: Tab; label: string }[] = [
  { id: "offering", label: "Oferta curricular" },
  { id: "planners", label: "Planificadores" },
]

/**
 * «Colegio y estructura» (screen 23 of the living document), route `/colegio`.
 * For now only the two tabs the cycle needs (ClickUp 1dfd3mc4bgf): «Oferta
 * curricular» and «Planificadores». Institución, Grupos and Recursos
 * disponibles come later. Director only — UX gate; the server enforces it
 * (CurricularOfferingPolicy).
 */
export function SchoolStructurePage() {
  const { user } = useAuth()
  const [tab, setTab] = useState<Tab>("offering")

  // The tab title comes from the route (src/lib/screenTitles.ts).
  if (!canManageCurricularStructure(user)) {
    return <NoAccessPage />
  }

  return (
    <div className="grid gap-6">
      <PageHeader
        title="Colegio y estructura"
        description="Se configura una vez y el resto del colegio lo hereda: qué programa sigue cada materia y cómo se llama cada campo del planificador."
      />
      <div role="tablist" aria-label="Solapas de Colegio y estructura" className="flex gap-1 border-b">
        {TABS.map((item) => (
          <button
            key={item.id}
            type="button"
            role="tab"
            id={`tab-${item.id}`}
            aria-selected={tab === item.id}
            aria-controls={`panel-${item.id}`}
            onClick={() => setTab(item.id)}
            className={cn(
              "-mb-px border-b-2 px-3 py-2 text-sm font-medium",
              tab === item.id
                ? "border-primary text-foreground"
                : "border-transparent text-muted-foreground hover:text-foreground",
            )}
          >
            {item.label}
          </button>
        ))}
      </div>
      <div role="tabpanel" id={`panel-${tab}`} aria-labelledby={`tab-${tab}`}>
        {tab === "offering" ? <CurricularOfferingTab /> : <PlannersTab />}
      </div>
    </div>
  )
}
