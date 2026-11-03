import { NoticeScreen } from "@/components/NoticeScreen"

/** Shown for any address that matches no screen, instead of silently opening home. */
export function NotFoundPage() {
  return <NoticeScreen title="No encontramos esa página" />
}
