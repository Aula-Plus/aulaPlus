import { useCallback, useEffect, useState } from "react"
import { HeartHandshake } from "lucide-react"
import { useAuth } from "@/features/auth/AuthContext"
import { isSchoolWideStaff } from "@/lib/permissions"
import { Button } from "@/components/ui/button"
import { EmptyState } from "@/components/ui/empty-state"
import { SectionCard } from "@/components/ui/section-card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { accommodationCategoryLabels, type GroupAccommodationSummaryEntry } from "@/types"
import { AIInputReviewDialog } from "./AIInputReviewDialog"
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
 * the clinical "Ajustes vigentes" section of StudentTrackingPage. Adding a
 * `canAccessClinicalProfile` gate here would be more restrictive than necessary
 * (spec §6). Authorization stays the backend's job anyway (CLAUDE.md).
 *
 * «Ver lo que recibiría la IA» (ClickUp 1dfd3mc2wx2, P03-AJU-03) opens the
 * review of «Lo que recibe la IA» for Psicopedagogía (who marks lines) and
 * Dirección (read only). Teachers never see the button; the endpoint refuses
 * them anyway (AIInputMarkPolicy).
 */
export function GroupAccommodationsSummary({ groupId }: GroupAccommodationsSummaryProps) {
  const [entries, setEntries] = useState<GroupAccommodationSummaryEntry[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [reviewOpen, setReviewOpen] = useState(false)
  const { user } = useAuth()
  const canReview = isSchoolWideStaff(user)

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
    <SectionCard
      title="Ajustes activos"
      action={
        canReview ? (
          <Button type="button" size="sm" variant="outline" onClick={() => setReviewOpen(true)}>
            Ver lo que recibiría la IA
          </Button>
        ) : undefined
      }
    >
      {canReview && <AIInputReviewDialog groupId={groupId} open={reviewOpen} onOpenChange={setReviewOpen} />}
      {error ? (
        <p className="text-sm text-destructive">{error}</p>
      ) : entries === null ? (
        <p className="text-muted-foreground">Cargando…</p>
      ) : entries.length === 0 ? (
        <EmptyState icon={HeartHandshake} message="El grupo no tiene ajustes activos." />
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
