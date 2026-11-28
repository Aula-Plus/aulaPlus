import type { ReactNode } from "react"
import { Sidebar } from "@/components/Sidebar"
import { GlobalSearch } from "@/features/search/GlobalSearch"

export function AppLayout({ children }: { children: ReactNode }) {
  return (
    <div className="flex min-h-svh">
      <Sidebar />
      {/* pt-14 clears the fixed mobile top bar; on desktop the sidebar is in flow. */}
      <main className="min-w-0 flex-1 pt-14 lg:pt-0">
        {/* «Buscar en Aula+» (PA-134): outside the sidebar, above the content
            and next to it, sticky so it stays at hand when the sidebar is
            collapsed or closed. Ctrl+K / ⌘K opens it too. */}
        <div className="sticky top-14 z-20 max-w-6xl bg-background/95 px-6 pt-4 pb-2 backdrop-blur lg:top-0 lg:px-8 lg:pt-6">
          <GlobalSearch />
        </div>
        {/* Left-aligned (no mx-auto) so content anchors to the sidebar instead
            of floating in the middle of the viewport with a gap on each side. */}
        <div className="max-w-6xl p-6 pt-4 lg:p-8 lg:pt-4">{children}</div>
      </main>
    </div>
  )
}
