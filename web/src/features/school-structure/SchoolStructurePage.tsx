import { useState } from "react"
import { useAuth } from "@/features/auth/AuthContext"
import { canManageCurricularStructure } from "@/lib/permissions"
import { PageHeader } from "@/components/ui/page-header"
import { cn } from "@/lib/utils"
import { CurricularOfferingTab } from "./CurricularOfferingTab"
import { InstitutionTab } from "./InstitutionTab"
import { PlannersTab } from "./PlannersTab"
import { SchoolRegimeCard } from "./SchoolRegimeCard"
import { SchoolResourcesTab } from "./SchoolResourcesTab"
import { NoAccessPage } from "@/pages/NoAccessPage"

type Tab = "institution" | "offering" | "planners" | "resources"

const TABS: { id: Tab; label: string }[] = [
  { id: "institution", label: "Institución" },
  { id: "offering", label: "Oferta curricular" },
  { id: "planners", label: "Planificadores" },
  { id: "resources", label: "Recursos disponibles" },
]

/**
 * «Colegio y estructura» (screen 23 of the living document), route `/colegio`.
 * «Oferta curricular» and «Planificadores» (ClickUp 1dfd3mc4bgf), «Recursos
 * disponibles» (ClickUp 1dfd3mc4bh4) and «Institución»: the meeting sheet
 * types (ClickUp 1dfd3mc4bge) and, read-only, the «Régimen ANEP por nivel»
 * with its link to the correction request of Puesta en marcha (ClickUp
 * 1dfd3mc4bh4, moved here once «Institución» existed, as Eitán asked). It
 * still opens on «Oferta curricular». Grupos comes later. Director only — UX
 * gate; the server enforces it (CurricularOfferingPolicy,
 * SchoolPolicy::viewOnboarding).
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
        {tab === "institution" && (
          <div className="space-y-6">
            <SchoolRegimeCard />
            <InstitutionTab />
          </div>
        )}
        {tab === "offering" && <CurricularOfferingTab />}
        {tab === "planners" && <PlannersTab />}
        {tab === "resources" && <SchoolResourcesTab />}
      </div>
    </div>
  )
}
