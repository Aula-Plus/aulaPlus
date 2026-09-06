import { BrowserRouter, Navigate, Route, Routes } from "react-router-dom"
import { AuthProvider } from "@/features/auth/AuthProvider"
import { ProtectedLayout } from "@/components/ProtectedLayout"
import { LoginPage } from "@/pages/LoginPage"
import { DashboardPage } from "@/pages/DashboardPage"
import { GroupsListPage } from "@/features/groups/GroupsListPage"
import { GroupFormPage } from "@/features/groups/GroupFormPage"
import { StudentsListPage } from "@/features/students/StudentsListPage"
import { StudentFormPage } from "@/features/students/StudentFormPage"

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
          />
          <Route
            path="/clases/nueva"
            element={
              <ProtectedLayout>
                <GroupFormPage />
              </ProtectedLayout>
            }
          />
          <Route
            path="/clases/:id"
            element={
              <ProtectedLayout>
                <GroupFormPage />
              </ProtectedLayout>
            }
          />
          <Route
            path="/alumnos"
            element={
              <ProtectedLayout>
                <StudentsListPage />
              </ProtectedLayout>
            }
          />
          <Route
            path="/alumnos/nuevo"
            element={
              <ProtectedLayout>
                <StudentFormPage />
              </ProtectedLayout>
            }
          />
          <Route
            path="/alumnos/:id"
            element={
              <ProtectedLayout>
                <StudentFormPage />
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
