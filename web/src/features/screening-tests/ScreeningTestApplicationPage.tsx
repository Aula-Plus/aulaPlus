import { useCallback, useEffect, useState } from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { Link, useParams } from "react-router-dom"
import { useAuth } from "@/features/auth/AuthContext"
import { canManageScreeningTests } from "@/lib/permissions"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Select } from "@/components/ui/select"
import type { Group, ScreeningTestApplication, ScreeningTestType } from "@/types"
import * as groupsApi from "@/features/groups/groupsApi"
import * as screeningApi from "./screeningTestsApi"
import { ScreeningTestRosterSheet } from "./ScreeningTestRosterSheet"
import { ScreeningTestResultsEditor } from "./ScreeningTestResultsEditor"

const applicationSchema = z.object({
  screening_test_type_id: z.string().min(1, "Elegí un tipo"),
  application_date: z.string().optional(),
})

type ApplicationValues = z.infer<typeof applicationSchema>

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

/**
 * Application screen (pantalla 7), route `/clases/:id/pruebas-de-sondeo`
 * (following the product's real `/clases` grouping, not the `/grupos` the spec
 * §5 draft used). Create an application over the group, print the code→student
 * sheet, and load scores by code with a live traffic light.
 *
 * The whole psychopedagogy toolkit (create, roster, results) is gated behind
 * `canManageScreeningTests`; the roster and results endpoints are
 * psychopedagogy-only server-side and their components are never mounted for
 * another role.
 */
export function ScreeningTestApplicationPage() {
  const { id } = useParams<{ id: string }>()
  const groupId = Number(id)
  const { user } = useAuth()
  const canManage = canManageScreeningTests(user)

  const [group, setGroup] = useState<Group | null>(null)
  const [types, setTypes] = useState<ScreeningTestType[] | null>(null)
  const [applications, setApplications] = useState<ScreeningTestApplication[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [openSheetId, setOpenSheetId] = useState<number | null>(null)
  const [openResultsId, setOpenResultsId] = useState<number | null>(null)

  const loadApplications = useCallback(() => {
    return screeningApi
      .fetchGroupScreeningTestApplications(groupId)
      .then(setApplications)
      .catch(() => setError("No pudimos cargar las aplicaciones."))
  }, [groupId])

  useEffect(() => {
    groupsApi
      .fetchGroup(groupId)
      .then(setGroup)
      .catch(() => setError("No pudimos cargar la clase."))
    screeningApi
      .fetchScreeningTestTypes()
      .then(setTypes)
      .catch(() => setError("No pudimos cargar los tipos de prueba."))
    loadApplications()
  }, [groupId, loadApplications])

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<ApplicationValues>({
    resolver: zodResolver(applicationSchema),
    defaultValues: { screening_test_type_id: "", application_date: today() },
  })

  // A screening test cannot be applied without an approved design in force
  // (spec §5): only offer types whose `current_design` is non-null, so the UI
  // never presents an option the backend would 422.
  const applicableTypes = (types ?? []).filter((type) => type.current_design !== null)

  const typeName = useCallback(
    (typeId: number) => types?.find((type) => type.id === typeId)?.name ?? `Tipo #${typeId}`,
    [types],
  )

  async function onCreate(values: ApplicationValues) {
    setFormError(null)
    try {
      await screeningApi.createScreeningTestApplication(groupId, {
        screening_test_type_id: Number(values.screening_test_type_id),
        application_date: values.application_date || undefined,
      })
      reset({ screening_test_type_id: "", application_date: today() })
      await loadApplications()
    } catch {
      setFormError("No pudimos crear la aplicación.")
    }
  }

  if (error) {
    return <p className="text-sm text-destructive">{error}</p>
  }

  if (!group || !types || !applications) {
    return <p className="text-muted-foreground">Cargando…</p>
  }

  return (
    <div className="grid gap-6">
      <div>
        <Link
          className="text-sm text-primary underline-offset-4 hover:underline"
          to={`/clases/${groupId}/seguimiento`}
        >
          ← Volver al seguimiento
        </Link>
        <h1 className="mt-1 text-2xl font-semibold">Pruebas de sondeo — {group.name}</h1>
      </div>

      {canManage && (
        <Card>
          <CardHeader>
            <CardTitle>Nueva aplicación</CardTitle>
          </CardHeader>
          <CardContent>
            {applicableTypes.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                No hay tipos con un diseño aprobado vigente. Aprobá un diseño antes de aplicar una
                prueba.
              </p>
            ) : (
              <form
                onSubmit={handleSubmit(onCreate)}
                className="grid gap-4 sm:grid-cols-2"
                noValidate
              >
                <div className="grid gap-2">
                  <Label htmlFor="screening_test_type_id">Tipo de prueba</Label>
                  <Select id="screening_test_type_id" {...register("screening_test_type_id")}>
                    <option value="">Elegí un tipo…</option>
                    {applicableTypes.map((type) => (
                      <option key={type.id} value={type.id}>
                        {type.name}
                      </option>
                    ))}
                  </Select>
                  {errors.screening_test_type_id && (
                    <p className="text-sm text-destructive">
                      {errors.screening_test_type_id.message}
                    </p>
                  )}
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="application_date">Fecha</Label>
                  <Input id="application_date" type="date" {...register("application_date")} />
                </div>
                {formError && (
                  <p role="alert" className="text-sm text-destructive sm:col-span-2">
                    {formError}
                  </p>
                )}
                <div className="sm:col-span-2">
                  <Button type="submit" disabled={isSubmitting}>
                    {isSubmitting ? "Creando…" : "Crear aplicación"}
                  </Button>
                </div>
              </form>
            )}
          </CardContent>
        </Card>
      )}

      {applications.length === 0 ? (
        <p className="text-muted-foreground">Todavía no hay aplicaciones en esta clase.</p>
      ) : (
        <section className="grid gap-4">
          <h2 className="text-lg font-semibold">Aplicaciones</h2>
          {applications.map((application) => (
            <Card key={application.id}>
              <CardHeader>
                <div className="flex items-start justify-between gap-4">
                  <div>
                    <CardTitle>{typeName(application.screening_test_type_id)}</CardTitle>
                    <p className="text-sm text-muted-foreground">
                      {application.application_date ?? "Sin fecha"}
                      {application.results_count !== undefined &&
                        ` · ${application.results_count} códigos`}
                    </p>
                  </div>
                  {canManage && (
                    <div className="flex flex-wrap justify-end gap-2">
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                          setOpenSheetId((current) =>
                            current === application.id ? null : application.id,
                          )
                        }
                      >
                        {openSheetId === application.id ? "Ocultar hoja" : "Hoja para aplicar"}
                      </Button>
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                          setOpenResultsId((current) =>
                            current === application.id ? null : application.id,
                          )
                        }
                      >
                        {openResultsId === application.id
                          ? "Ocultar resultados"
                          : "Cargar resultados"}
                      </Button>
                    </div>
                  )}
                </div>
              </CardHeader>
              {canManage && (openSheetId === application.id || openResultsId === application.id) && (
                <CardContent className="grid gap-4">
                  {openSheetId === application.id && (
                    <ScreeningTestRosterSheet applicationId={application.id} />
                  )}
                  {openResultsId === application.id && (
                    <ScreeningTestResultsEditor applicationId={application.id} />
                  )}
                </CardContent>
              )}
            </Card>
          ))}
        </section>
      )}
    </div>
  )
}
