import { useCallback, useEffect, useRef, useState } from "react"
import { useSearchParams } from "react-router-dom"
import { PageHeader } from "@/components/ui/page-header"
import { useAuth } from "@/features/auth/AuthContext"
import { canOpenPsychopedagogySpace, canWorkInPsychopedagogySpace } from "@/lib/permissions"
import { cn, formatLongDate } from "@/lib/utils"
import type { PsychopedagogyOverview } from "@/types"
import { AgendaTab } from "./AgendaTab"
import { CompanionsTab } from "./CompanionsTab"
import { SheetsTab } from "./SheetsTab"
import { fetchOverview } from "./psychopedagogySpaceApi"

type Tab = "agenda" | "companions" | "sheets"

const TABS: { id: Tab; label: string; param: string }[] = [
  { id: "agenda", label: "Agenda", param: "agenda" },
  { id: "companions", label: "Acompañantes", param: "acompanantes" },
  { id: "sheets", label: "Planillas", param: "planillas" },
]

/**
 * «Espacio de psicopedagogía» (screen 10, ClickUp 1dfd3mc4bge), route
 * `/espacio-psicopedagogia`: the team's agenda, the therapeutic companions
 * and the meeting sheets.
 *
 * Psicopedagogía works here. Dirección gets its limited view: the week's
 * workload by type, the current companions (no history) and that each sheet
 * existed. No teacher enters (P10-AGE-01). All of this is UX only: the server
 * answers 403 to a teacher and leaves out of Dirección's payloads what it
 * may not read.
 */
export function PsychopedagogySpacePage() {
  const { user } = useAuth()
  const [params, setParams] = useSearchParams()
  const tab = TABS.find((item) => item.param === params.get("solapa"))?.id ?? "agenda"
  const [overview, setOverview] = useState<PsychopedagogyOverview | null>(null)
  const allowed = canOpenPsychopedagogySpace(user)
  const works = canWorkInPsychopedagogySpace(user)

  // Reloaded after every change in a tab (scheduling, marking done,
  // companions); only the latest answer counts, so a slow earlier one never
  // puts back an old count.
  const overviewRequest = useRef(0)
  const loadOverview = useCallback(() => {
    if (!allowed) return
    const mine = ++overviewRequest.current
    fetchOverview().then(
      (next) => {
        if (mine === overviewRequest.current) setOverview(next)
      },
      () => {
        if (mine === overviewRequest.current) setOverview(null)
      },
    )
  }, [allowed])

  useEffect(() => {
    loadOverview()
  }, [loadOverview])

  if (!allowed) {
    return (
      <div className="flex flex-col items-center gap-3 py-16 text-center">
        <h1 className="text-2xl font-semibold">Este espacio no es tuyo</h1>
        <p className="max-w-xl text-sm text-muted-foreground">
          Es el espacio interno del equipo de Psicopedagogía. Si te citaron a una reunión o una planilla
          habla de un alumno tuyo, te llega una notificación y lo que te corresponde queda en la ficha del
          alumno.
        </p>
      </div>
    )
  }

  function selectTab(next: Tab) {
    const param = TABS.find((item) => item.id === next)?.param ?? "agenda"
    setParams({ solapa: param }, { replace: true })
  }

  return (
    <div className="grid gap-6">
      <PageHeader title="Espacio de psicopedagogía" description={formatLongDate(new Date())} />
      {overview && (
        <div className="flex flex-wrap gap-2 text-sm" data-testid="space-counts">
          <span className="rounded-md border px-3 py-1">
            {overview.meetings_this_week === 1 ? "1 encuentro" : `${overview.meetings_this_week} encuentros`} esta semana
          </span>
          <span className="rounded-md border px-3 py-1">
            {overview.active_companions === 1
              ? "1 acompañante activo"
              : `${overview.active_companions} acompañantes activos`}
          </span>
        </div>
      )}
      <div role="tablist" aria-label="Solapas del espacio de psicopedagogía" className="flex gap-1 border-b">
        {TABS.map((item) => (
          <button
            key={item.id}
            type="button"
            role="tab"
            id={`tab-${item.id}`}
            aria-selected={tab === item.id}
            aria-controls={`panel-${item.id}`}
            onClick={() => selectTab(item.id)}
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
      <div role="tabpanel" id={`panel-${tab}`} aria-labelledby={`tab-${tab}`} className="grid gap-6">
        {tab === "agenda" && <AgendaTab works={works} overview={overview} onChanged={loadOverview} />}
        {tab === "companions" && <CompanionsTab works={works} onChanged={loadOverview} />}
        {tab === "sheets" && <SheetsTab works={works} />}
      </div>
    </div>
  )
}
