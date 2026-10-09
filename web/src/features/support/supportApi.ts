import { api } from "@/lib/api"
import type { SupportMessageInput } from "@/types"

/** Send an "Ayuda y sugerencias" message to the Aula+ team. */
export async function sendSupportMessage(input: SupportMessageInput): Promise<void> {
  await api.post("/api/v1/support-messages", input)
}
