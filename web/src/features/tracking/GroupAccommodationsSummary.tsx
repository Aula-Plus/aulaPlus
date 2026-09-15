import { useCallback, useEffect, useState } from "react"
import { HeartHandshake } from "lucide-react"
import { EmptyState } from "@/components/ui/empty-state"
import { SectionCard } from "@/components/ui/section-card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { accommodationCategoryLabels, type GroupAccommodationSummaryEntry } from "@/types"
import { fetchGroupAccommodationsSummary } from "./trackingApi"

interface GroupAccommodationsSummaryProps {
  groupId: number
}

/**
 * Perfil de grupo — "Ajustes activos" section (docs/prompts/27-frontend-perfil-
 * de-grupo.md §6). A plain aggregated table (type / category / student_count)
 * of the group's active accommodations.
 *
 * Deliberately NO role gate: the endpoint is aggregate-only and never names a
 * student, so it is safe for any role that can already see the group — unlike
 * the clinical "Adaptaciones vigentes" section of StudentTrackingPage. Adding a
 * `canAccessClinicalProfile` gate here would be more restrictive than necessary
 * (spec §6). Authorization stays the backend's job anyway (CLAUDE.md).
 */
export function GroupAccommodationsSummary({ groupId }: GroupAccommodationsSummaryProps) {
  const [entries, setEntries] = useState<GroupAccommodationSummaryEntry[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setError(null)
    try {
      setEntries(await fetchGroupAccommodationsSummary(groupId))
    } catch {
      setError("No pudimos cargar los ajustes activos.")
      setEntries([])
    }
  }, [groupId])

  useEffect(() => {
    load()
  }, [load])

  return (
    <SectionCard title="Ajustes activos">
      {error ? (
        <p className="text-sm text-destructive">{error}</p>
      ) : entries === null ? (
        <p className="text-muted-foreground">Cargando…</p>
      ) : entries.length === 0 ? (
        <EmptyState icon={HeartHandshake} message="La clase no tiene ajustes activos." />
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Ajuste</TableHead>
              <TableHead>Categoría</TableHead>
              <TableHead>Alumnos</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {entries.map((entry) => (
              <TableRow key={entry.type}>
                <TableCell>{entry.type}</TableCell>
                <TableCell>{accommodationCategoryLabels[entry.category]}</TableCell>
                <TableCell>{entry.student_count}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </SectionCard>
  )
}
