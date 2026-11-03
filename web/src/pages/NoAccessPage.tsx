import { NoticeScreen } from "@/components/NoticeScreen"

/**
 * Shown when the signed-in user can't open a screen (Límites, grades
 * visibility, AI in reports, the adoption dashboard). Same shape as
 * NotFoundPage, without naming who can open it: «cuenta», not «rol», because
 * direction can open an Administración screen to a single person. UX only;
 * the server enforces access.
 */
export function NoAccessPage() {
  return <NoticeScreen title="Esta pantalla no está disponible para tu cuenta" />
}
