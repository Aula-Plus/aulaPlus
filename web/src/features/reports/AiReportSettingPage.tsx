import { useEffect, useState } from "react"
import { useAuth } from "@/features/auth/AuthContext"
import { canManageAiReportSetting } from "@/lib/permissions"
import { Button } from "@/components/ui/button"
import { SectionCard } from "@/components/ui/section-card"
import type { AiReportSetting } from "@/types"
import * as reportsApi from "./reportsApi"

/**
 * School-wide switch for the AI reading of student reports (ClickUp
 * 86e3jujgx). Lives in «Usuarios y permisos › Límites» (`/usuarios/limites`);
 * the old `/ajustes/informes-ia` address redirects there. Director-only (UX
 * gate; the server enforces it). Off by default.
 */
export function AiReportSettingPanel() {
  const { user } = useAuth()
  const canManage = canManageAiReportSetting(user)
  const [setting, setSetting] = useState<AiReportSetting | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    reportsApi
      .fetchAiReportSetting()
      .then(setSetting)
      .catch(() => setError("No pudimos cargar la configuración."))
  }, [])

  async function toggle() {
    if (!setting) return
    setBusy(true)
    setError(null)
    try {
      setSetting(await reportsApi.updateAiReportSetting(!setting.enabled))
    } catch {
      setError("No pudimos guardar el cambio.")
    } finally {
      setBusy(false)
    }
  }

  if (!canManage) {
    return <p className="text-muted-foreground">Esta sección es solo para dirección.</p>
  }

  return (
    <div className="grid gap-6">
      <p className="text-sm text-muted-foreground">
        Decidí si Aula+ puede usar IA para identificar automáticamente los ajustes que indica el informe de cada
        alumno.
      </p>
      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
      <SectionCard title="Qué pasa con la información">
        <ul className="list-disc space-y-1 pl-5 text-sm">
          <li>No se envía nada que identifique al alumno: Aula+ borra el nombre y los datos identificables que detecta.</li>
          <li>Psicopedagogía revisa y corrige el texto anonimizado, y confirma cada envío antes de que salga.</li>
          <li>La IA solo prepara borradores: nada queda vigente hasta que Psicopedagogía lo aprueba.</li>
          <li>Psicopedagogía puede no usar la IA en un informe puntual aunque esté activada.</li>
          <li>Podés desactivarla cuando quieras. Si está apagada, los informes se cargan y se revisan sin IA.</li>
        </ul>
      </SectionCard>
      <SectionCard title="Para todo el colegio">
        {setting === null ? (
          <p className="text-sm text-muted-foreground">Cargando…</p>
        ) : (
          <div className="grid gap-3">
            <p className="text-sm">
              Estado:{" "}
              <strong>{setting.enabled ? "Activada" : "Desactivada"}</strong>
            </p>
            {setting.enabled && !setting.available && (
              <p role="note" className="rounded-md border bg-muted/40 p-3 text-sm">
                Aula+ tiene la función pausada por ahora para todos los colegios. Tu elección queda guardada y se
                aplica cuando se reactive.
              </p>
            )}
            <div>
              <Button type="button" disabled={busy} onClick={toggle}>
                {setting.enabled ? "Desactivar la IA en informes" : "Activar la IA en informes"}
              </Button>
            </div>
          </div>
        )}
      </SectionCard>
    </div>
  )
}
