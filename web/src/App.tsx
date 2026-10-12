import { BrowserRouter, Navigate, Route, Routes } from "react-router-dom"
import { AuthProvider } from "@/features/auth/AuthProvider"
import { ProtectedLayout } from "@/components/ProtectedLayout"
import { LoginPage } from "@/pages/LoginPage"
import { AcceptInvitationPage } from "@/features/auth/AcceptInvitationPage"
import { DashboardPage } from "@/pages/DashboardPage"
import { GroupsListPage } from "@/features/groups/GroupsListPage"
import { GroupFormPage } from "@/features/groups/GroupFormPage"
import { StudentsListPage } from "@/features/students/StudentsListPage"
import { StudentFormPage } from "@/features/students/StudentFormPage"
import { StudentTrackingPage } from "@/features/tracking/StudentTrackingPage"
import { StudentHistoryPage } from "@/features/tracking/StudentHistoryPage"
import { GroupTrackingPage } from "@/features/tracking/GroupTrackingPage"
import { GradesVisibilityPage } from "@/features/tracking/GradesVisibilityPage"
import { CommentSettingsPage } from "@/features/tracking/CommentSettingsPage"
import { MyFollowUpsPage } from "@/features/tracking/MyFollowUpsPage"
import { SupportPage } from "@/features/support/SupportPage"
import { AdoptionDashboardPage } from "@/features/tracking/AdoptionDashboardPage"
import { AssessmentsPage } from "@/features/assessments/AssessmentsPage"
import { ScreeningTestDesignPage } from "@/features/screening-tests/ScreeningTestDesignPage"
import { ScreeningTestApplicationPage } from "@/features/screening-tests/ScreeningTestApplicationPage"
import { SubjectsPage } from "@/features/subjects/SubjectsPage"
import { UsersListPage } from "@/features/users/UsersListPage"
import { UserFormPage } from "@/features/users/UserFormPage"
import { AlertSettingsPage } from "@/features/alerts/AlertSettingsPage"
import { MyAlertsPage } from "@/features/alerts/MyAlertsPage"

function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/aceptar-invitacion" element={<AcceptInvitationPage />} />
          <Route
            path="/"
            element={
              <ProtectedLayout>
                <DashboardPage />
              </ProtectedLayout>
            }
          />
          <Route
            path="/clases"
            element={
              <ProtectedLayout>
                <GroupsListPage />
              </ProtectedLayout>
            }
          >
            <Route path="nueva" element={<GroupFormPage />} />
            <Route path=":id" element={<GroupFormPage />} />
          </Route>
          <Route
            path="/alumnos"
            element={
              <ProtectedLayout>
                <StudentsListPage />
              </ProtectedLayout>
            }
          >
            <Route path="nuevo" element={<StudentFormPage />} />
            <Route path=":id" element={<StudentFormPage />} />
          </Route>
          <Route
            path="/alumnos/:id/seguimiento"
            element={
              <ProtectedLayout>
                <StudentTrackingPage />
              </ProtectedLayout>
            }
          />
          <Route
            path="/alumnos/:id/historial"
            element={
              <ProtectedLayout>
                <StudentHistoryPage />
              </ProtectedLayout>
            }
          />
          <Route
            path="/ajustes/comentarios"
            element={
              <ProtectedLayout>
                <CommentSettingsPage />
              </ProtectedLayout>
            }
          />
          <Route
            path="/mis-seguimientos"
            element={
              <ProtectedLayout>
                <MyFollowUpsPage />
              </ProtectedLayout>
            }
          />
          <Route
            path="/clases/:id/seguimiento"
            element={
              <ProtectedLayout>
                <GroupTrackingPage />
              </ProtectedLayout>
            }
          />
          <Route
            path="/clases/:id/evaluaciones"
            element={
              <ProtectedLayout>
                <AssessmentsPage />
              </ProtectedLayout>
            }
          />
          <Route
            path="/clases/:id/pruebas-de-sondeo"
            element={
              <ProtectedLayout>
                <ScreeningTestApplicationPage />
              </ProtectedLayout>
            }
          />
          <Route
            path="/pruebas-de-sondeo/tipos"
            element={
              <ProtectedLayout>
                <ScreeningTestDesignPage />
              </ProtectedLayout>
            }
          />
          <Route
            path="/materias"
            element={
              <ProtectedLayout>
                <SubjectsPage />
              </ProtectedLayout>
            }
          />
          <Route
            path="/usuarios"
            element={
              <ProtectedLayout>
                <UsersListPage />
              </ProtectedLayout>
            }
          >
            <Route path="nueva" element={<UserFormPage />} />
            <Route path=":id" element={<UserFormPage />} />
          </Route>
          <Route
            path="/ajustes/notas"
            element={
              <ProtectedLayout>
                <GradesVisibilityPage />
              </ProtectedLayout>
            }
          />
          <Route
            path="/alertas"
            element={
              <ProtectedLayout>
                <MyAlertsPage />
              </ProtectedLayout>
            }
          />
          <Route
            path="/configuracion/alertas"
            element={
              <ProtectedLayout>
                <AlertSettingsPage />
              </ProtectedLayout>
            }
          />
          <Route
            path="/adopcion"
            element={
              <ProtectedLayout>
                <AdoptionDashboardPage />
              </ProtectedLayout>
            }
          />
          <Route
            path="/ayuda"
            element={
              <ProtectedLayout>
                <SupportPage />
              </ProtectedLayout>
            }
          />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}

export default App
