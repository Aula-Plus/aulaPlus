import { useEffect, useState } from "react"
import { Link } from "react-router-dom"
import { Button } from "@/components/ui/button"
import { SectionCard } from "@/components/ui/section-card"
import { canOpenAccommodationsInbox } from "@/lib/permissions"
import type { User } from "@/types"
import { fetchPendingAnnualReviews } from "./accommodationsPtpApi"

/**
 * Psychopedagogy's «Revisión anual» task in Inicio (P01-DEC-06, P06-DEC-04;
 * ClickUp 1dfd3mc4bgc): a single row with how many students with
 * accommodations in force still lack this school year's review, opening the
 * «Revisión anual» tab of «Ajustes y PTP». It is a projection of those
 * records (P01-DEC-02): with nothing pending, the row is not there.
 */
export function AnnualReviewTask({ user }: { user: User }) {
  const allowed = canOpenAccommodationsInbox(user)
  const [count, setCount] = useState<number | null>(null)

  useEffect(() => {
    if (!allowed) return
    let cancelled = false
    fetchPendingAnnualReviews().then(
      (value) => {
        if (!cancelled) setCount(value)
      },
      () => {
        if (!cancelled) setCount(null)
      },
    )
    return () => {
      cancelled = true
    }
  }, [allowed])

  if (!allowed || !count) return null

  return (
    <SectionCard title="Tareas">
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 text-sm">
        <div>
          <p className="font-medium">
            Revisión anual: {count === 1 ? "1 alumno" : `${count} alumnos`}
          </p>
          <p className="text-muted-foreground">
            Tienen ajustes vigentes y no se marcó la revisión en el año lectivo.
          </p>
        </div>
        <Button asChild size="sm" variant="outline">
          <Link to="/ajustes-y-ptp?solapa=revision-anual">Abrir la revisión anual</Link>
        </Button>
      </div>
    </SectionCard>
  )
}
