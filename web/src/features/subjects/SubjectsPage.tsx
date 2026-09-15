import { useEffect, useState } from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { BookOpen } from "lucide-react"
import { useAuth } from "@/features/auth/AuthContext"
import { canManageSubjects } from "@/lib/permissions"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { ConfirmDialog } from "@/components/ui/confirm-dialog"
import { EmptyState } from "@/components/ui/empty-state"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { PageHeader } from "@/components/ui/page-header"
import type { Subject } from "@/types"
import * as subjectsApi from "./subjectsApi"

const subjectSchema = z.object({
  name: z.string().min(1, "Ingresá el nombre"),
  short_code: z.string().optional(),
})

type SubjectValues = z.infer<typeof subjectSchema>

/**
 * Subjects ("materias") admin screen, route `/materias`. Director-only CRUD of
 * the school's subject catalog (backend Sesión 1). Role gating here is UX only.
 */
export function SubjectsPage() {
  const { user } = useAuth()
  const canManage = canManageSubjects(user)

  const [subjects, setSubjects] = useState<Subject[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<SubjectValues>({
    resolver: zodResolver(subjectSchema),
    defaultValues: { name: "", short_code: "" },
  })

  function load() {
    return subjectsApi
      .fetchSubjects()
      .then(setSubjects)
      .catch(() => setError("No pudimos cargar las materias."))
  }

  useEffect(() => {
    load()
  }, [])

  async function onCreate(values: SubjectValues) {
    setFormError(null)
    try {
      await subjectsApi.createSubject({
        name: values.name.trim(),
        short_code: values.short_code?.trim() ? values.short_code.trim() : null,
      })
      reset({ name: "", short_code: "" })
      await load()
    } catch {
      setFormError("No pudimos crear la materia. ¿Ya existe una con ese nombre?")
    }
  }

  async function onDelete(id: number) {
    setError(null)
    try {
      await subjectsApi.deleteSubject(id)
      await load()
    } catch {
      setError("No pudimos eliminar la materia.")
    }
  }

  if (error) return <p className="text-sm text-destructive">{error}</p>
  if (!subjects) return <p className="text-muted-foreground">Cargando…</p>

  return (
    <div className="grid gap-6">
      <PageHeader title="Materias" />

      {canManage && (
        <Card>
          <CardHeader>
            <CardTitle>Nueva materia</CardTitle>
          </CardHeader>
          <CardContent>
            <form onSubmit={handleSubmit(onCreate)} className="grid gap-4 sm:grid-cols-2" noValidate>
              <div className="grid gap-2">
                <Label htmlFor="name">Nombre</Label>
                <Input id="name" {...register("name")} />
                {errors.name && <p className="text-sm text-destructive">{errors.name.message}</p>}
              </div>
              <div className="grid gap-2">
                <Label htmlFor="short_code">Código (opcional)</Label>
                <Input id="short_code" {...register("short_code")} />
              </div>
              {formError && (
                <p role="alert" className="text-sm text-destructive sm:col-span-2">
                  {formError}
                </p>
              )}
              <div className="sm:col-span-2">
                <Button type="submit" disabled={isSubmitting}>
                  {isSubmitting ? "Creando…" : "Crear materia"}
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>
      )}

      {subjects.length === 0 ? (
        <EmptyState icon={BookOpen} message="Todavía no hay materias." />
      ) : (
        <section className="grid gap-3">
          {subjects.map((subject) => (
            <Card key={subject.id}>
              <CardContent className="flex items-center justify-between py-4">
                <div>
                  <p className="font-medium">{subject.name}</p>
                  {subject.short_code && (
                    <p className="text-sm text-muted-foreground">{subject.short_code}</p>
                  )}
                </div>
                {canManage && (
                  <ConfirmDialog
                    trigger={
                      <Button type="button" variant="destructive" size="sm">
                        Eliminar
                      </Button>
                    }
                    title="Eliminar materia"
                    description="Esta acción no se puede deshacer."
                    confirmLabel="Eliminar"
                    onConfirm={() => onDelete(subject.id)}
                  />
                )}
              </CardContent>
            </Card>
          ))}
        </section>
      )}
    </div>
  )
}
