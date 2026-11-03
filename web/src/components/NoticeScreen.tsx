import { Link } from "react-router-dom"
import { Button } from "@/components/ui/button"

/**
 * A full-screen notice with a single way back home. Shared on purpose by
 * NotFoundPage and NoAccessPage (ClickUp 1dfd3mc3qj8): both are a closed door,
 * so they look the same.
 */
export function NoticeScreen({ title }: { title: string }) {
  return (
    <div className="flex flex-col items-center justify-center gap-4 py-16 text-center">
      <h1 className="text-2xl font-semibold">{title}</h1>
      <Button asChild>
        <Link to="/">Volver al inicio</Link>
      </Button>
    </div>
  )
}
