import { Link } from "react-router-dom"
import { Button } from "@/components/ui/button"

/**
 * Shown when the signed-in user's role can't open a screen (Límites, grades
 * visibility, AI in reports, the adoption dashboard). Same shape as
 * NotFoundPage: a short notice and a way back home, without naming who can
 * open it. UX only; the server enforces access.
 */
export function NoAccessPage() {
  return (
    <div className="flex flex-col items-center justify-center gap-4 py-16 text-center">
      <h1 className="text-2xl font-semibold">Esta pantalla no está habilitada para tu cuenta</h1>
      <Button asChild>
        <Link to="/">Volver al inicio</Link>
      </Button>
    </div>
  )
}
