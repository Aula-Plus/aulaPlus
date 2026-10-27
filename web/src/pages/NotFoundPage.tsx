import { Link } from "react-router-dom"
import { Button } from "@/components/ui/button"

/** Shown for any address that matches no screen, instead of silently opening home. */
export function NotFoundPage() {
  return (
    <div className="flex flex-col items-center justify-center gap-4 py-16 text-center">
      <h1 className="text-2xl font-semibold">No encontramos esa página</h1>
      <Button asChild>
        <Link to="/">Volver al inicio</Link>
      </Button>
    </div>
  )
}
