import type { ReactNode } from "react"
import { Sidebar } from "@/components/Sidebar"

export function AppLayout({ children }: { children: ReactNode }) {
  return (
    <div className="flex min-h-svh">
      <Sidebar />
      {/* pt-14 clears the fixed mobile top bar; on desktop the sidebar is in flow. */}
      <main className="flex-1 pt-14 lg:pt-0">
        {/* Left-aligned (no mx-auto) so content anchors to the sidebar instead
            of floating in the middle of the viewport with a gap on each side. */}
        <div className="max-w-6xl p-6 lg:p-8">{children}</div>
      </main>
    </div>
  )
}
