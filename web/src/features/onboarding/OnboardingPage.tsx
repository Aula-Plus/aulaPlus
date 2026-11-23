import { useEffect, useState, type FormEvent } from "react"
import { useSearchParams } from "react-router-dom"
import { useAuth } from "@/features/auth/AuthContext"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { EmptyState } from "@/components/ui/empty-state"
import { Label } from "@/components/ui/label"
import { PageHeader } from "@/components/ui/page-header"
import { SectionCard } from "@/components/ui/section-card"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { Textarea } from "@/components/ui/textarea"
import { canViewOnboarding } from "@/lib/permissions"
import { cn, formatShortDate } from "@/lib/utils"
import { NoAccessPage } from "@/pages/NoAccessPage"
import * as onboardingApi from "./onboardingApi"
import { levelRegimeLabels } from "./onboardingApi"
import type { OnboardingRecord, OnboardingStepStatus, SupportChannels } from "./onboardingApi"

type Tab = "import" | "regime" | "log"

const TABS: { id: Tab; label: string }[] = [
  { id: "import", label: "Importación" },
  { id: "regime", label: "Régimen, oferta y plantillas" },
  { id: "log", label: "Bitácora" },
]

const statusLabels: Record<OnboardingStepStatus, { label: string; tone: "neutral" | "warning" | "success" }> = {
  pending: { label: "Pendiente", tone: "neutral" },
  in_progress: { label: "En curso", tone: "warning" },
  done: { label: "Hecho", tone: "success" },
}

/** Steps of the fixed instructivo (P29-ARR-02, PA-131): same text for every school. */
const INSTRUCTIVE_STEPS: { step: string; school: string }[] = [
  {
    step: "Entrega de datos",
    school: "El colegio entrega la exportación de su sistema de gestión y las planillas con el historial.",
  },
  {
    step: "Carga del régimen y la oferta",
    school: "Aula+ carga el régimen, la oferta, las plantillas y la biblioteca curricular. El colegio no carga nada.",
  },
  { step: "Migración", school: "Aula+ pasa alumnos, grupos, docentes e historial. El colegio no mueve un dato." },
  {
    step: "Validación conjunta",
    school: "El colegio revisa con Aula+ una muestra de 20 alumnos antes de abrir.",
  },
  {
    step: "Capacitación a docentes",
    school: "El colegio participa de la capacitación: instructivo escrito y una sesión.",
  },
  { step: "Apertura a los tres roles", school: "Docente, Psicopedagogía y Dirección empiezan a usar Aula+." },
]

function dateOrDash(value: string | null): string {
  return value ? formatShortDate(value) : "—"
}

/**
 * Screen 29 «Puesta en marcha» (ClickUp 1dfd3mc4bh8): the record of how the
 * school's information came in. Dirección only reads it; the Aula+ team loads
 * it on the server (`app:onboarding-*`). «Solicitar corrección» goes through
 * «Ayuda y sugerencias» as «Corrección de régimen» at top urgency.
 */
export function OnboardingPage() {
  const { user } = useAuth()
  const [record, setRecord] = useState<OnboardingRecord | null>(null)
  const [error, setError] = useState(false)
  // «¿Algo no coincide con tu colegio?» in Colegio y estructura links here
  // with `?solapa=regimen` to open the regime correction directly.
  const [searchParams] = useSearchParams()
  const [tab, setTab] = useState<Tab>(searchParams.get("solapa") === "regimen" ? "regime" : "import")
  const [instructiveOpen, setInstructiveOpen] = useState(false)
  const allowed = canViewOnboarding(user)

  useEffect(() => {
    if (!allowed) return
    let cancelled = false
    onboardingApi
      .fetchOnboarding()
      .then((loaded) => !cancelled && setRecord(loaded))
      .catch(() => !cancelled && setError(true))
    return () => {
      cancelled = true
    }
  }, [allowed])

  if (!allowed) return <NoAccessPage />

  return (
    <div className="grid gap-6">
      <PageHeader
        title="Puesta en marcha"
        description="Cómo entró la información del colegio, quién la cargó y qué revisó el colegio antes de abrir."
      >
        <Button type="button" variant="outline" onClick={() => setInstructiveOpen(true)}>
          Ver instructivo
        </Button>
      </PageHeader>

      {error ? (
        <p role="alert" className="text-sm text-destructive">
          No pudimos cargar la puesta en marcha.
        </p>
      ) : record === null ? (
        <p className="text-sm text-muted-foreground">Cargando…</p>
      ) : (
        <>
          <SectionCard title="Estado">
            <ul className="grid gap-1 text-sm">
              <li>Importación completada · {dateOrDash(record.import_completed_on)}</li>
              <li>Régimen configurado por Aula+ · {dateOrDash(record.regime_configured_on)}</li>
              <li>Sistema abierto · {dateOrDash(record.opened_on)}</li>
            </ul>
          </SectionCard>

          <div role="tablist" aria-label="Solapas de Puesta en marcha" className="flex flex-wrap gap-1 border-b">
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

          <div role="tabpanel" id={`panel-${tab}`} aria-labelledby={`tab-${tab}`} className="grid gap-4">
            {tab === "import" && <ImportTab record={record} schoolName={user?.school?.name} />}
            {tab === "regime" && <RegimeTab record={record} />}
            {tab === "log" && <LogTab record={record} onInstructive={() => setInstructiveOpen(true)} />}
          </div>
        </>
      )}

      <Dialog open={instructiveOpen} onOpenChange={setInstructiveOpen}>
        <DialogContent className="max-h-[85vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Instructivo de la puesta en marcha</DialogTitle>
            <DialogDescription>
              Plazo: 15 días hábiles desde la entrega de los datos hasta la apertura.
            </DialogDescription>
          </DialogHeader>
          <ol className="grid list-decimal gap-2 pl-5 text-sm">
            {INSTRUCTIVE_STEPS.map((item) => (
              <li key={item.step}>
                <span className="font-medium">{item.step}.</span> {item.school}
              </li>
            ))}
          </ol>
          <ContactLines record={record} failed={error} />
        </DialogContent>
      </Dialog>
    </div>
  )
}

function ContactLines({ record, failed }: { record: OnboardingRecord | null; failed: boolean }) {
  // Until the record arrives (or if it failed) we cannot say there is no contact.
  if (record === null) {
    return (
      <p className="text-sm text-muted-foreground">
        {failed ? "No pudimos cargar el contacto de Aula+." : "Cargando el contacto de Aula+…"}
      </p>
    )
  }
  const contact = record.contact
  const channels = record.support_channels
  return (
    <div className="grid gap-1 text-sm">
      {contact ? (
        <p>
          Tu contacto en Aula+: {contact.name ?? "—"}
          {contact.channel ? ` · ${contact.channel}` : ""}
        </p>
      ) : (
        <p className="text-muted-foreground">Todavía no hay una persona de Aula+ asignada al colegio.</p>
      )}
      {channels && <ChannelLinks channels={channels} />}
    </div>
  )
}

function ChannelLinks({ channels }: { channels: SupportChannels }) {
  if (!channels.whatsapp && !channels.email) return null
  const whatsappDigits = channels.whatsapp?.replace(/\D/g, "") ?? ""
  return (
    <p className="flex flex-wrap gap-3">
      {channels.whatsapp && whatsappDigits !== "" && (
        <a className="underline" href={`https://wa.me/${whatsappDigits}`} target="_blank" rel="noopener noreferrer">
          Escribir por WhatsApp ({channels.whatsapp})
        </a>
      )}
      {channels.email && (
        <a className="underline" href={`mailto:${channels.email}`}>
          Escribir a {channels.email}
        </a>
      )}
    </p>
  )
}

function ImportTab({ record, schoolName }: { record: OnboardingRecord; schoolName?: string }) {
  return (
    <>
      <SectionCard title={`Qué se recibió${schoolName ? ` de ${schoolName}` : ""}`} bare>
        {record.sources.length === 0 ? (
          <EmptyState className="m-4" message="El equipo de Aula+ todavía no registró lo recibido." />
        ) : (
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Fuente</TableHead>
                  <TableHead>Contenido</TableHead>
                  <TableHead>Formato</TableHead>
                  <TableHead>Estado</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {record.sources.map((source) => (
                  <TableRow key={source.id}>
                    <TableCell className="font-medium">{source.source}</TableCell>
                    <TableCell>{source.content}</TableCell>
                    <TableCell>{source.format ?? "—"}</TableCell>
                    <TableCell>
                      <Badge tone={statusLabels[source.status].tone}>
                        {source.status === "done" ? "Importado" : statusLabels[source.status].label}
                      </Badge>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </SectionCard>
      <SectionCard title="Es migración, no sincronización">
        <p className="text-sm">Los datos entraron una vez. Desde la apertura, todo se edita en Aula+.</p>
      </SectionCard>
    </>
  )
}

function RegimeTab({ record }: { record: OnboardingRecord }) {
  return (
    <>
      <SectionCard title="Régimen, oferta y plantillas por programa">
        <div className="grid gap-4 text-sm">
          <dl className="grid gap-2">
            {record.regimes.map((level) => (
              <div key={level.level_key} className="grid gap-0.5">
                <dt className="font-medium">{level.level_label}</dt>
                <dd className="text-muted-foreground">
                  {level.regime ? levelRegimeLabels[level.regime] : "Sin cargar por Aula+"}
                  {level.fixed ? " · Fijo: todos los colegios privados lo están" : ""}
                  {level.note ? ` · ${level.note}` : ""}
                </dd>
              </div>
            ))}
          </dl>
          <div className="grid gap-0.5">
            <p className="font-medium">Oferta por grupo y materia</p>
            <p className="text-muted-foreground">
              {record.offering_confirmed_on
                ? `Confirmada por el colegio el ${formatShortDate(record.offering_confirmed_on)} (Colegio y estructura › Oferta curricular)`
                : "Se configura en Colegio y estructura › Oferta curricular."}
            </p>
          </div>
          <div className="grid gap-0.5">
            <p className="font-medium">Plantillas por programa</p>
            <p className="text-muted-foreground">
              Preparadas por Aula+; el colegio ajusta lo que se le permite en Evaluación y juicios.
            </p>
          </div>
        </div>
      </SectionCard>
      <CorrectionCard />
    </>
  )
}

/** «¿Algo no coincide?» → «Solicitar corrección» (P29-REG-02). */
function CorrectionCard() {
  const [message, setMessage] = useState("")
  const [sending, setSending] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [sent, setSent] = useState<SupportChannels | null>(null)

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (message.trim().length < 5) {
      setError("Contanos qué no coincide (al menos 5 caracteres).")
      return
    }
    setSending(true)
    setError(null)
    try {
      const result = await onboardingApi.requestRegimeCorrection(message.trim())
      setSent(result.support_channels)
      setMessage("")
    } catch {
      setError("No pudimos enviar el pedido. Probá de nuevo.")
    } finally {
      setSending(false)
    }
  }

  return (
    <SectionCard title="¿Algo no coincide?">
      {sent ? (
        <div role="status" className="grid gap-2 text-sm">
          <p>Recibimos tu pedido de corrección. El equipo de Aula+ lo ve como urgente.</p>
          {(sent.whatsapp || sent.email) && (
            <>
              <p>Si querés, escribinos también por acá:</p>
              <ChannelLinks channels={sent} />
            </>
          )}
          <div>
            <Button type="button" variant="outline" size="sm" onClick={() => setSent(null)}>
              Enviar otro pedido
            </Button>
          </div>
        </div>
      ) : (
        <form className="grid gap-3" onSubmit={submit}>
          <div className="grid gap-1.5">
            <Label htmlFor="regime-correction">Qué no coincide con tu colegio</Label>
            <Textarea
              id="regime-correction"
              value={message}
              maxLength={3000}
              onChange={(event) => setMessage(event.target.value)}
              aria-describedby="regime-correction-help"
            />
            <p id="regime-correction-help" className="text-xs text-muted-foreground">
              Llega al equipo de Aula+ como urgente. No incluyas datos de alumnos.
            </p>
          </div>
          {error && (
            <p role="alert" className="text-sm text-destructive">
              {error}
            </p>
          )}
          <div>
            <Button type="submit" disabled={sending}>
              {sending ? "Enviando…" : "Solicitar corrección"}
            </Button>
          </div>
        </form>
      )}
    </SectionCard>
  )
}

function LogTab({ record, onInstructive }: { record: OnboardingRecord; onInstructive: () => void }) {
  return (
    <>
      <SectionCard title="Bitácora de la puesta en marcha" bare>
        {record.log.length === 0 ? (
          <EmptyState className="m-4" message="El equipo de Aula+ todavía no registró pasos." />
        ) : (
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Fecha</TableHead>
                  <TableHead>Paso</TableHead>
                  <TableHead>Estado</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {record.log.map((entry) => (
                  <TableRow key={entry.id}>
                    <TableCell className="whitespace-nowrap">{formatShortDate(entry.happened_on)}</TableCell>
                    <TableCell>{entry.step}</TableCell>
                    <TableCell>
                      <Badge tone={statusLabels[entry.status].tone}>{statusLabels[entry.status].label}</Badge>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </SectionCard>
      <SectionCard title="Instructivo" action={<Button type="button" size="sm" variant="outline" onClick={onInstructive}>Ver instructivo</Button>}>
        <p className="text-sm text-muted-foreground">
          Los pasos de la puesta en marcha, lo que le toca al colegio en cada uno y el plazo de 15 días hábiles.
        </p>
      </SectionCard>
    </>
  )
}
