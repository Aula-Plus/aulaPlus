import { useEffect, useState } from "react"
import { Link } from "react-router-dom"
import { SectionCard } from "@/components/ui/section-card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import * as onboardingApi from "@/features/onboarding/onboardingApi"
import { levelRegimeLabels } from "@/features/onboarding/onboardingApi"
import type { OnboardingRecord } from "@/features/onboarding/onboardingApi"

/**
 * «Régimen ANEP por nivel» of Colegio y estructura (screen 23 P23-INS-02,
 * P23-DEC-114 — ClickUp 1dfd3mc4bh4): read-only, loaded by Aula+. Reads the
 * same record as «Puesta en marcha» (screen 29, `SchoolPolicy::viewOnboarding`,
 * Dirección only) and «¿Algo no coincide con tu colegio?» takes Dirección to
 * its correction request. Shown in the «Institución» tab.
 */
export function SchoolRegimeCard() {
  const [regimes, setRegimes] = useState<OnboardingRecord["regimes"] | null>(null)
  const [error, setError] = useState(false)

  useEffect(() => {
    let cancelled = false
    onboardingApi
      .fetchOnboarding()
      .then((record) => !cancelled && setRegimes(record.regimes))
      .catch(() => !cancelled && setError(true))
    return () => {
      cancelled = true
    }
  }, [])

  return (
    <SectionCard title="Régimen ANEP por nivel">
      <div className="grid gap-3 text-sm">
        <p className="text-muted-foreground">Cargado por Aula+</p>
        {error ? (
          <p role="alert" className="text-destructive">
            No pudimos cargar el régimen.
          </p>
        ) : regimes === null ? (
          <p className="text-muted-foreground">Cargando…</p>
        ) : (
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Nivel</TableHead>
                  <TableHead>Régimen</TableHead>
                  <TableHead>Nota</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {regimes.map((level) => (
                  <TableRow key={level.level_key}>
                    <TableCell className="font-medium">{level.level_label}</TableCell>
                    <TableCell>{level.regime ? levelRegimeLabels[level.regime] : "Sin cargar por Aula+"}</TableCell>
                    <TableCell>
                      {level.fixed ? "Fijo: todos los colegios privados lo están" : (level.note ?? "—")}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
        <p>
          <Link className="underline" to="/administracion/puesta-en-marcha?solapa=regimen">
            ¿Algo no coincide con tu colegio?
          </Link>
        </p>
      </div>
    </SectionCard>
  )
}
