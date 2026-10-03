import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { SectionCard } from "@/components/ui/section-card";
import type { AdoptionTeacherRow, LastLoginRange } from "@/types";
import {
  fetchAdoptionTeachers,
  fetchTeacherLastLoginRange,
} from "./adoptionApi";

const lastLoginLabels: Record<LastLoginRange, string> = {
  this_week: "esta semana",
  within_10_days: "en los últimos 10 días",
  over_10_days: "hace más de 10 días",
  never: "sin ingresos registrados",
};

function plural(count: number, one: string, many: string) {
  return `${count} ${count === 1 ? one : many}`;
}

function summarize(counts: AdoptionTeacherRow["month_counts"]) {
  const parts = [
    counts.class_sessions > 0 &&
      plural(counts.class_sessions, "clase", "clases"),
    counts.assessments > 0 &&
      plural(counts.assessments, "evaluación", "evaluaciones"),
    counts.annual_plans > 0 &&
      plural(counts.annual_plans, "programa", "programas"),
  ].filter(Boolean);
  return parts.length > 0
    ? `${parts.join(" y ")} este mes`
    : "Sin contenido creado este mes";
}

/**
 * Director-only "Por docente" tab (living document, screen 28). Alphabetical
 * counts only — no sorting controls, no per-person score, and the last login
 * is shown solely on demand as a coarse range, never as a day and time.
 */
export function AdoptionTeachersTab({ schoolId }: { schoolId: number }) {
  const [teachers, setTeachers] = useState<AdoptionTeacherRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetchAdoptionTeachers(schoolId)
      .then(setTeachers)
      .catch(() => setError("No pudimos cargar el uso por docente."));
  }, [schoolId]);

  if (error) return <p className="text-sm text-destructive">{error}</p>;
  if (!teachers) return <p className="text-muted-foreground">Cargando…</p>;

  return (
    <SectionCard title="Uso por docente">
      {teachers.length === 0 ? (
        <EmptyState message="No hay docentes en el colegio." />
      ) : (
        <ul className="divide-y">
          {teachers.map((teacher) => (
            <TeacherRow
              key={teacher.id}
              schoolId={schoolId}
              teacher={teacher}
            />
          ))}
        </ul>
      )}
    </SectionCard>
  );
}

function TeacherRow({
  schoolId,
  teacher,
}: {
  schoolId: number;
  teacher: AdoptionTeacherRow;
}) {
  const [range, setRange] = useState<LastLoginRange | null>(null);
  const [failed, setFailed] = useState(false);

  function showDetails() {
    setFailed(false);
    fetchTeacherLastLoginRange(schoolId, teacher.id)
      .then(setRange)
      .catch(() => setFailed(true));
  }

  const subjects =
    teacher.subjects.length > 0 ? ` — ${teacher.subjects.join(", ")}` : "";

  return (
    <li className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
      <div>
        <p className="font-medium">
          {teacher.name}
          <span className="font-normal text-muted-foreground">{subjects}</span>
        </p>
        <p className="text-muted-foreground">
          {summarize(teacher.month_counts)}
        </p>
        {range && (
          <p className="text-muted-foreground">
            Último ingreso: {lastLoginLabels[range]}
          </p>
        )}
        {failed && (
          <p className="text-destructive">
            No pudimos cargar los detalles de uso.
          </p>
        )}
      </div>
      {range === null && (
        <Button variant="outline" size="sm" onClick={showDetails}>
          Ver detalles de uso
        </Button>
      )}
    </li>
  );
}
