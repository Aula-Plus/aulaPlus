import { render, screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { beforeEach, describe, expect, it, vi } from "vitest"
import { OnboardingPage } from "./OnboardingPage"
import * as onboardingApi from "./onboardingApi"
import type { OnboardingRecord } from "./onboardingApi"

vi.mock("./onboardingApi")
vi.mock("@/features/auth/AuthContext", () => ({
  useAuth: () => ({ user: { id: 1, name: "Dir", email: "d@x.com", roles, school: { id: 1, name: "Colegio Modelo" } } }),
}))
vi.mock("@/pages/NoAccessPage", () => ({ NoAccessPage: () => <p>Esta pantalla no está disponible para tu cuenta</p> }))

let roles = ["director"]

const record: OnboardingRecord = {
  import_completed_on: "2026-02-28",
  regime_configured_on: "2026-02-20",
  opened_on: "2026-03-02",
  offering_confirmed_on: null,
  contact: { name: "Tiago", channel: "Por mail" },
  regimes: [
    { level_key: "initial", level_label: "Inicial", regime: "authorized", fixed: false, note: "Programa propio" },
    { level_key: "primary", level_label: "Primaria", regime: null, fixed: false, note: null },
    { level_key: "lower_secondary", level_label: "Ciclo Básico", regime: "enabled", fixed: true, note: null },
  ],
  sources: [{ id: 1, source: "Sistema de gestión", content: "Alumnos y grupos", format: "CSV", status: "done" }],
  log: [{ id: 1, happened_on: "2026-02-27", step: "Validación conjunta", status: "done" }],
  support_channels: { whatsapp: "+598 99 000 000", email: null },
}

describe("OnboardingPage", () => {
  beforeEach(() => {
    vi.clearAllMocks()
    roles = ["director"]
    vi.mocked(onboardingApi.fetchOnboarding).mockResolvedValue(record)
  })

  it("shows what was received and the log, read-only", async () => {
    render(<OnboardingPage />)
    expect(await screen.findByText("Sistema de gestión")).toBeInTheDocument()
    expect(screen.getByText("Importado")).toBeInTheDocument()
    expect(screen.getByText("Qué se recibió de Colegio Modelo")).toBeInTheDocument()

    await userEvent.click(screen.getByRole("tab", { name: "Bitácora" }))
    expect(screen.getByText("Validación conjunta")).toBeInTheDocument()
  })

  it("shows the regime per level and sends a correction, then offers the configured channels", async () => {
    vi.mocked(onboardingApi.requestRegimeCorrection).mockResolvedValue({
      id: 9,
      support_channels: { whatsapp: "+598 99 000 000", email: null },
    })
    render(<OnboardingPage />)
    await userEvent.click(await screen.findByRole("tab", { name: "Régimen, oferta y plantillas" }))
    expect(screen.getByText("Autorizado · Programa propio")).toBeInTheDocument()
    expect(screen.getByText("Sin cargar por Aula+")).toBeInTheDocument()
    expect(screen.getByText("Habilitado · Fijo: todos los colegios privados lo están")).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText("Qué no coincide con tu colegio"), "Primaria es autorizada")
    await userEvent.click(screen.getByRole("button", { name: "Solicitar corrección" }))

    await waitFor(() => expect(onboardingApi.requestRegimeCorrection).toHaveBeenCalledWith("Primaria es autorizada"))
    expect(await screen.findByText(/Recibimos tu pedido de corrección/)).toBeInTheDocument()
    expect(screen.getByRole("link", { name: /WhatsApp/ })).toHaveAttribute("href", "https://wa.me/59899000000")
    expect(screen.queryByRole("link", { name: /Escribir a/ })).not.toBeInTheDocument()
  })

  it("opens the fixed instructivo with the 15 working days and the contact", async () => {
    render(<OnboardingPage />)
    await screen.findByText("Sistema de gestión")
    await userEvent.click(screen.getAllByRole("button", { name: "Ver instructivo" })[0])
    expect(await screen.findByText(/15 días hábiles/)).toBeInTheDocument()
    expect(screen.getByText(/muestra de 20 alumnos/)).toBeInTheDocument()
    expect(screen.getByText("Tu contacto en Aula+: Tiago · Por mail")).toBeInTheDocument()
  })

  it("does not say there is no contact when the record failed to load", async () => {
    vi.mocked(onboardingApi.fetchOnboarding).mockRejectedValue(new Error("network"))
    render(<OnboardingPage />)
    await screen.findByText("No pudimos cargar la puesta en marcha.")
    await userEvent.click(screen.getAllByRole("button", { name: "Ver instructivo" })[0])
    expect(await screen.findByText("No pudimos cargar el contacto de Aula+.")).toBeInTheDocument()
    expect(screen.queryByText(/Todavía no hay una persona de Aula\+ asignada/)).not.toBeInTheDocument()
  })

  it("is not for other roles", async () => {
    roles = ["psychopedagogue"]
    render(<OnboardingPage />)
    expect(screen.getByText("Esta pantalla no está disponible para tu cuenta")).toBeInTheDocument()
    expect(onboardingApi.fetchOnboarding).not.toHaveBeenCalled()
  })
})
