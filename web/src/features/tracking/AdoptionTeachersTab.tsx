import { useEffect, useState } from "react"
import { Button } from "@/components/ui/button"
import { EmptyState } from "@/components/ui/empty-state"
import { SectionCard } from "@/components/ui/section-card"
import { formatShortDate } from "@/lib/utils"
import { NOT_AVAILABLE_LABEL } from "@/types"
import type { AdoptionTeacherRow, ContentCounts, JourneyDataSince, LastLoginRange } from "@/types"
import { fetchAdoptionTeachers, fetchTeacherLastLoginRange, type AdoptionTeachers } from "./adoptionApi"
import { TeacherJourneysDialog } from "./TeacherJourneysDialog"

const lastLoginLabels: Record<LastLoginRange, string> = {
  this_week: "esta semana",
  within_10_days: "en los últimos 10 días",
  over_10_days: "hace más de 10 días",
  never: "sin ingresos registrados",
}

function plural(count: number, one: string, many: string) {
  return `${count} ${count === 1 ? one : many}`
}

function listCounts(counts: ContentCounts) {
  const parts = [
    counts.class_sessions > 0 && plural(counts.class_sessions, "clase", "clases"),
    counts.assessments > 0 && plural(counts.assessments, "evaluación", "evaluaciones"),
    counts.annual_plans > 0 && plural(counts.annual_plans, "programa", "programas"),
  ].filter((part): part is string => Boolean(part))
  if (parts.length === 0) return null
  // "4 clases, 1 evaluación y 2 programas" — commas, "y" only before the last.
  return parts.length === 1 ? parts[0] : `${parts.slice(0, -1).join(", ")} y ${parts.at(-1)}`
}

/** Confirmed creations this month (Guardar, or a confirmed AI proposal). */
function summarize(counts: ContentCounts) {
  const list = listCounts(counts)
  return list ? `${list} este mes` : "Sin creaciones confirmadas este mes"
}

/**
 * AI generation attempts this month (one per journey). `null` means the school
 * has no generation record yet: «dato no disponible», never 0 or «no intentó».
 */
function summarizeAttempts(attempts: ContentCounts<number | null>) {
  const { annual_plans, class_sessions, assessments } = attempts
  if (annual_plans === null || class_sessions === null || assessments === null) {
    return NOT_AVAILABLE_LABEL
  }
  return listCounts({ annual_plans, class_sessions, assessments }) ?? "ninguno este mes"
}

/**
 * Director-only "Por docente" tab (living document, screen 28). Alphabetical
 * counts only — no sorting controls, no per-person score, and the last login
 * is shown solely on demand as a coarse range, never as a day and time.
 * Attempts (AI only) and confirmed creations are separate figures, and the
 * journeys behind them open on demand (ClickUp 86e3qkxtc).
 */
export function AdoptionTeachersTab({ schoolId }: { schoolId: number }) {
  const [teachers, setTeachers] = useState<AdoptionTeachers | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    fetchAdoptionTeachers(schoolId)
      .then(setTeachers)
      .catch(() => setError("No pudimos cargar el uso por docente."))
  }, [schoolId])

  if (error) return <p className="text-sm text-destructive">{error}</p>
  if (!teachers) return <p className="text-muted-foreground">Cargando…</p>

  return (
    <SectionCard title="Uso por docente">
      <AttemptsNote dataSince={teachers.dataSince} />
      {teachers.rows.length === 0 ? (
        <EmptyState message="No hay docentes en el colegio." />
      ) : (
        <ul className="divide-y">
          {teachers.rows.map((teacher) => (
            <TeacherRow key={teacher.id} schoolId={schoolId} teacher={teacher} />
          ))}
        </ul>
      )}
    </SectionCard>
  )
}

function AttemptsNote({ dataSince }: { dataSince: JourneyDataSince }) {
  const since = dataSince.generation_requested
  return (
    <p className="mb-2 text-sm text-muted-foreground">
      Los intentos cuentan solo los pedidos al asistente: uno por recorrido (los reintentos y
      pedidos de cambio no suman). Lo creado a mano no tiene intentos: cuenta al guardar.{" "}
      {since
        ? `Datos de intentos desde ${formatShortDate(since)}.`
        : `Datos de intentos: ${NOT_AVAILABLE_LABEL}.`}
    </p>
  )
}

function TeacherRow({ schoolId, teacher }: { schoolId: number; teacher: AdoptionTeacherRow }) {
  const [range, setRange] = useState<LastLoginRange | null>(null)
  const [failed, setFailed] = useState(false)
  const [journeysOpen, setJourneysOpen] = useState(false)

  function showDetails() {
    setFailed(false)
    fetchTeacherLastLoginRange(schoolId, teacher.id)
      .then(setRange)
      .catch(() => setFailed(true))
  }

  const subjects = teacher.subjects.length > 0 ? ` — ${teacher.subjects.join(", ")}` : ""

  return (
    <li className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
      <div>
        <p className="font-medium">
          {teacher.name}
          <span className="font-normal text-muted-foreground">{subjects}</span>
        </p>
        <p className="text-muted-foreground">
          Creaciones confirmadas: {summarize(teacher.month_counts)}
        </p>
        <p className="text-muted-foreground">
          Intentos con el asistente: {summarizeAttempts(teacher.month_attempts)}
        </p>
        {range && <p className="text-muted-foreground">Último ingreso: {lastLoginLabels[range]}</p>}
        {failed && <p className="text-destructive">No pudimos cargar los detalles de uso.</p>}
      </div>
      <div className="flex flex-wrap gap-2">
        <Button variant="outline" size="sm" onClick={() => setJourneysOpen(true)}>
          Ver recorridos
        </Button>
        {range === null && (
          <Button variant="outline" size="sm" onClick={showDetails}>
            Ver detalles de uso
          </Button>
        )}
      </div>
      {journeysOpen && (
        <TeacherJourneysDialog
          schoolId={schoolId}
          teacherId={teacher.id}
          teacherName={teacher.name}
          onClose={() => setJourneysOpen(false)}
        />
      )}
    </li>
  )
}
