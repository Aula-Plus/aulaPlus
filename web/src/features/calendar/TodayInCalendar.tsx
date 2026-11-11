import { useEffect, useId, useState } from "react"
import { Link } from "react-router-dom"
import { CalendarDays } from "lucide-react"
import { EmptyState } from "@/components/ui/empty-state"
import { SectionCard } from "@/components/ui/section-card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { isTeacher } from "@/lib/permissions"
import { calendarItemTypeLabels, type CalendarItem, type CalendarItemType, type User } from "@/types"
import { fetchCalendar } from "./calendarApi"
import { toDay } from "./calendarDates"
import { calendarItemLink, calendarItemWhat } from "./calendarItemText"

/**
 * Teachers: every date of their day except tasks/follow-ups, which keep their
 * own card below (P01-HOY-01).
 */
const TEACHER_TYPES: readonly CalendarItemType[] = [
  "class",
  "assessment",
  "meeting",
  "period_closing",
  "project_stage",
  "institutional",
]

/**
 * Psychopedagogy and Direction: meetings, closings and institutional events
 * (P01-HOY-01) — not the school's assessment load, which is for the Calendar.
 */
const STAFF_TYPES: readonly CalendarItemType[] = ["meeting", "period_closing", "institutional"]

/**
 * «Hoy en tu calendario» at the top of the Inicio (P01-HOY-01; PA-089; ClickUp
 * 1dfd3mc4bgh). It reads the same feed as the Calendar for today, so what each
 * role sees is decided server-side. A teacher gets Hora | Qué | Grupo | Salón
 * (a class without a time is listed without one); Psychopedagogy and Direction
 * get Hora | Qué | Dónde.
 */
export function TodayInCalendar({ user }: { user: User }) {
  const headingId = useId()
  const teacher = isTeacher(user)
  const [items, setItems] = useState<CalendarItem[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const today = toDay(new Date())
    fetchCalendar(today, today, teacher ? TEACHER_TYPES : STAFF_TYPES)
      .then(setItems)
      .catch(() => setError("No pudimos cargar tu calendario de hoy."))
  }, [teacher])

  return (
    <section aria-labelledby={headingId} className="grid gap-6">
      <div>
        <h2 id={headingId} className="text-xl font-semibold">
          Hoy en tu calendario
        </h2>
      </div>
      {error ? (
        <p className="text-sm text-destructive">{error}</p>
      ) : (
        <SectionCard
          title="Eventos del día"
          headingLevel={3}
          bare
          action={
            <Link to="/calendario" className="text-sm text-primary underline-offset-4 hover:underline">
              Ver calendario
            </Link>
          }
        >
          {items === null ? (
            <p className="px-6 text-muted-foreground">Cargando…</p>
          ) : items.length === 0 ? (
            <div className="px-6">
              <EmptyState icon={CalendarDays} message="Hoy no hay nada en tu calendario." />
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="pl-6">Hora</TableHead>
                  <TableHead>Qué</TableHead>
                  {teacher ? (
                    <>
                      <TableHead>Grupo</TableHead>
                      <TableHead>Salón</TableHead>
                    </>
                  ) : (
                    <TableHead>Dónde</TableHead>
                  )}
                  <TableHead className="pr-6">
                    <span className="sr-only">Abrir</span>
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {items.map((item) => {
                  const link = calendarItemLink(item)
                  return (
                    <TableRow key={item.key}>
                      <TableCell className="pl-6 tabular-nums">{item.time ?? "—"}</TableCell>
                      <TableCell>
                        <span className="font-medium">{calendarItemWhat(item)}</span>
                        {item.type !== "class" && (
                          <span className="ml-2 text-xs text-muted-foreground">{calendarItemTypeLabels[item.type]}</span>
                        )}
                      </TableCell>
                      {teacher ? (
                        <>
                          <TableCell>{item.group?.name ?? "—"}</TableCell>
                          <TableCell>{item.place ?? "—"}</TableCell>
                        </>
                      ) : (
                        <TableCell>{item.place ?? "—"}</TableCell>
                      )}
                      <TableCell className="pr-6 text-right">
                        {link && (
                          <Link to={link} className="text-sm text-primary underline-offset-4 hover:underline">
                            Abrir
                          </Link>
                        )}
                      </TableCell>
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          )}
        </SectionCard>
      )}
    </section>
  )
}
