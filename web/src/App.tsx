import { BrowserRouter, Navigate, Route, Routes } from "react-router-dom"
import { AuthProvider } from "@/features/auth/AuthProvider"
import { ProtectedLayout } from "@/components/ProtectedLayout"
import { LoginPage } from "@/pages/LoginPage"
import { DashboardPage } from "@/pages/DashboardPage"
import { GroupsListPage } from "@/features/groups/GroupsListPage"
import { GroupFormPage } from "@/features/groups/GroupFormPage"
import { StudentsListPage } from "@/features/students/StudentsListPage"
import { StudentFormPage } from "@/features/students/StudentFormPage"
import { StudentTrackingPage } from "@/features/tracking/StudentTrackingPage"
import { StudentHistoryPage } from "@/features/tracking/StudentHistoryPage"
import { GroupTrackingPage } from "@/features/tracking/GroupTrackingPage"
import { AdoptionDashboardPage } from "@/features/tracking/AdoptionDashboardPage"

function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
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
            path="/clases/:id/seguimiento"
            element={
              <ProtectedLayout>
                <GroupTrackingPage />
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
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}

export default App
