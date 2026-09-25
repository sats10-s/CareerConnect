import { Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider } from '@/context/AuthContext'
import { useAuth } from '@/hooks/useAuth'
import { Spinner } from '@/components/ui/Spinner'
import { PlaceholderPage } from '@/components/common/PlaceholderPage'
import { ProtectedRoute } from '@/routes/ProtectedRoute'
import { AuthLayout } from '@/layouts/AuthLayout'
import { StudentLayout } from '@/layouts/StudentLayout'
import { FacultyLayout } from '@/layouts/FacultyLayout'
import { AdminLayout } from '@/layouts/AdminLayout'
import { SuperAdminLayout } from '@/layouts/SuperAdminLayout'
import { LoginPage } from '@/pages/auth/LoginPage'
import { RegisterPage } from '@/pages/auth/RegisterPage'
import { StudentDashboardPage } from '@/pages/student/Dashboard'
import { StudentProfilePage } from '@/pages/student/Profile'
import { StudentNotificationsPage } from '@/pages/student/Notifications'
import { FacultyDashboardPage } from '@/pages/faculty/Dashboard'
import { AdminDashboardPage } from '@/pages/admin/Dashboard'
import { AdminNotificationsPage } from '@/pages/admin/Notifications'
import { SuperAdminDashboardPage } from '@/pages/superadmin/Dashboard'
import { NotFoundPage } from '@/pages/NotFound'
import { NAV_ITEMS } from '@/utils/navConfig'
import { dashboardPathForRole } from '@/utils/roles'
import type { Role } from '@/types/auth'

/** Renders every non-dashboard nav item for a role as a PlaceholderPage. */
function placeholderRoutesFor(role: Role) {
  return NAV_ITEMS[role]
    .filter((item) => !item.implemented)
    .map((item) => {
      const relativePath = item.path.split('/').slice(2).join('/')
      return <Route key={item.path} path={relativePath} element={<PlaceholderPage title={item.label} icon={item.icon} />} />
    })
}

function RootRedirect() {
  const { isAuthenticated, isLoading, user } = useAuth()

  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-gray-50">
        <Spinner className="h-8 w-8" />
      </div>
    )
  }

  if (isAuthenticated && user) {
    return <Navigate to={dashboardPathForRole(user.role)} replace />
  }

  return <Navigate to="/login" replace />
}

export default function App() {
  return (
    <AuthProvider>
      <Routes>
        <Route path="/" element={<RootRedirect />} />

        <Route element={<AuthLayout />}>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/register" element={<RegisterPage />} />
        </Route>

        <Route element={<ProtectedRoute allowedRoles={['STUDENT']} />}>
          <Route path="/student" element={<StudentLayout />}>
            <Route path="dashboard" element={<StudentDashboardPage />} />
            <Route path="profile" element={<StudentProfilePage />} />
            <Route path="notifications" element={<StudentNotificationsPage />} />
            {placeholderRoutesFor('STUDENT')}
          </Route>
        </Route>

        <Route element={<ProtectedRoute allowedRoles={['FACULTY']} />}>
          <Route path="/faculty" element={<FacultyLayout />}>
            <Route path="dashboard" element={<FacultyDashboardPage />} />
            {placeholderRoutesFor('FACULTY')}
          </Route>
        </Route>

        <Route element={<ProtectedRoute allowedRoles={['PLACEMENT_ADMIN']} />}>
          <Route path="/admin" element={<AdminLayout />}>
            <Route path="dashboard" element={<AdminDashboardPage />} />
            <Route path="notifications" element={<AdminNotificationsPage />} />
            {placeholderRoutesFor('PLACEMENT_ADMIN')}
          </Route>
        </Route>

        <Route element={<ProtectedRoute allowedRoles={['SUPER_ADMIN']} />}>
          <Route path="/super-admin" element={<SuperAdminLayout />}>
            <Route path="dashboard" element={<SuperAdminDashboardPage />} />
            {placeholderRoutesFor('SUPER_ADMIN')}
          </Route>
        </Route>

        <Route path="*" element={<NotFoundPage />} />
      </Routes>
    </AuthProvider>
  )
}
