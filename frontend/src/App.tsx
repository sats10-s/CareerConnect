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
import { LandingPage } from '@/pages/public/LandingPage'
import { LoginPage } from '@/pages/auth/LoginPage'
import { RegisterPage } from '@/pages/auth/RegisterPage'
import { StudentDashboardPage } from '@/pages/student/Dashboard'
import { StudentProfilePage } from '@/pages/student/Profile'
import { StudentNotificationsPage } from '@/pages/student/Notifications'
import { StudentApplicationsPage } from '@/pages/student/Applications'
import { ApplicationDetailPage } from '@/pages/student/ApplicationDetail'
import { StudentJobsPage } from '@/pages/student/Jobs'
import { StudentInternshipsPage } from '@/pages/student/Internships'
import { JobDetailPage } from '@/pages/student/JobDetail'
import { InternshipDetailPage } from '@/pages/student/InternshipDetail'
import { MyInternshipPage } from '@/pages/student/MyInternship'
import { StudentInterviewsPage } from '@/pages/student/Interviews'
import { FacultyDashboardPage } from '@/pages/faculty/Dashboard'
import { FacultyStudentsPage } from '@/pages/faculty/Students'
import { FacultyInternshipsPage } from '@/pages/faculty/Internships'
import { FacultyAttendancePage } from '@/pages/faculty/Attendance'
import { FacultyProgressPage } from '@/pages/faculty/Progress'
import { FacultyDocumentsPage } from '@/pages/faculty/Documents'
import { FacultyEvaluationsPage } from '@/pages/faculty/Evaluations'
import { FacultyReportsPage } from '@/pages/faculty/Reports'
import { AdminDashboardPage } from '@/pages/admin/Dashboard'
import { AdminNotificationsPage } from '@/pages/admin/Notifications'
import { AdminJobsPage } from '@/pages/admin/Jobs'
import { AdminInternshipsPage } from '@/pages/admin/Internships'
import { AdminExcelSyncPage } from '@/pages/admin/ExcelSync'
import { AdminReportsPage } from '@/pages/admin/Reports'
import { AdminFacultyPage } from '@/pages/admin/Faculty'
import { AdminStudentsPage } from '@/pages/admin/Students'
import { AdminCompaniesPage } from '@/pages/admin/Companies'
import { SuperAdminDepartmentsPage, SuperAdminBatchesPage } from '@/pages/superadmin/MetaLists'
import { AdminApplicationsPage } from '@/pages/admin/Applications'
import { AdminInterviewsPage } from '@/pages/admin/Interviews'
import { AdminPlacementDrivesPage } from '@/pages/admin/PlacementDrives'
import { SuperAdminDashboardPage } from '@/pages/superadmin/Dashboard'
import { SuperAdminUsersPage } from '@/pages/superadmin/Users'
import { SuperAdminRolesPage } from '@/pages/superadmin/Roles'
import { SuperAdminAuditLogsPage } from '@/pages/superadmin/AuditLogs'
import { SuperAdminSecurityPage } from '@/pages/superadmin/Security'
import { SuperAdminSettingsPage } from '@/pages/superadmin/Settings'
import { SuperAdminStudentDataPage } from '@/pages/superadmin/StudentData'
import { NotFoundPage } from '@/pages/NotFound'
import { ToastViewport } from '@/components/ui/Toast'
import { NAV_ITEMS } from '@/utils/navConfig'
import { dashboardPathForRole } from '@/utils/roles'
import type { Role } from '@/types/auth'

/** Renders every non-dashboard nav item for a role as a PlaceholderPage. */
function placeholderRoutesFor(role: Role) {
  return NAV_ITEMS[role]
    .filter((item) => !item.implemented)
    .map((item) => {
      const relativePath = item.path.split('/').slice(2).join('/')
      return <Route key={item.path} path={relativePath} element={<PlaceholderPage title={item.label} icon={item.icon} note={item.note} />} />
    })
}

function RootRedirect() {
  const { isAuthenticated, isLoading, user } = useAuth()

  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-base">
        <Spinner className="h-8 w-8" />
      </div>
    )
  }

  if (isAuthenticated && user) {
    return <Navigate to={dashboardPathForRole(user.role)} replace />
  }

  // Was a hard redirect to /login - the public landing page (previously
  // nonexistent) now owns "/" for guests; /login is still reachable
  // from its header and from any deep link.
  return <LandingPage />
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
            <Route path="applications" element={<StudentApplicationsPage />} />
            <Route path="applications/:id" element={<ApplicationDetailPage />} />
            <Route path="interviews" element={<StudentInterviewsPage />} />
            <Route path="jobs" element={<StudentJobsPage />} />
            <Route path="jobs/:id" element={<JobDetailPage />} />
            <Route path="internships" element={<StudentInternshipsPage />} />
            <Route path="internships/:id" element={<InternshipDetailPage />} />
            <Route path="my-internship" element={<MyInternshipPage />} />
            {placeholderRoutesFor('STUDENT')}
          </Route>
        </Route>

        <Route element={<ProtectedRoute allowedRoles={['FACULTY']} />}>
          <Route path="/faculty" element={<FacultyLayout />}>
            <Route path="dashboard" element={<FacultyDashboardPage />} />
            <Route path="students" element={<FacultyStudentsPage />} />
            <Route path="internships" element={<FacultyInternshipsPage />} />
            <Route path="attendance" element={<FacultyAttendancePage />} />
            <Route path="progress" element={<FacultyProgressPage />} />
            <Route path="documents" element={<FacultyDocumentsPage />} />
            <Route path="evaluations" element={<FacultyEvaluationsPage />} />
            <Route path="reports" element={<FacultyReportsPage />} />
            {placeholderRoutesFor('FACULTY')}
          </Route>
        </Route>

        <Route element={<ProtectedRoute allowedRoles={['PLACEMENT_ADMIN']} />}>
          <Route path="/admin" element={<AdminLayout />}>
            <Route path="dashboard" element={<AdminDashboardPage />} />
            <Route path="notifications" element={<AdminNotificationsPage />} />
            <Route path="jobs" element={<AdminJobsPage />} />
            <Route path="internships" element={<AdminInternshipsPage />} />
            <Route path="excel-sync" element={<AdminExcelSyncPage />} />
            <Route path="reports" element={<AdminReportsPage />} />
            <Route path="faculty" element={<AdminFacultyPage />} />
            <Route path="students" element={<AdminStudentsPage />} />
            <Route path="companies" element={<AdminCompaniesPage />} />
            <Route path="applications" element={<AdminApplicationsPage />} />
            <Route path="interviews" element={<AdminInterviewsPage />} />
            <Route path="placement-drives" element={<AdminPlacementDrivesPage />} />
            {placeholderRoutesFor('PLACEMENT_ADMIN')}
          </Route>
        </Route>

        <Route element={<ProtectedRoute allowedRoles={['SUPER_ADMIN']} />}>
          <Route path="/super-admin" element={<SuperAdminLayout />}>
            <Route path="dashboard" element={<SuperAdminDashboardPage />} />
            <Route path="departments" element={<SuperAdminDepartmentsPage />} />
            <Route path="batches" element={<SuperAdminBatchesPage />} />
            <Route path="users" element={<SuperAdminUsersPage />} />
            <Route path="student-data" element={<SuperAdminStudentDataPage />} />
            <Route path="roles" element={<SuperAdminRolesPage />} />
            <Route path="security" element={<SuperAdminSecurityPage />} />
            <Route path="audit-logs" element={<SuperAdminAuditLogsPage />} />
            <Route path="settings" element={<SuperAdminSettingsPage />} />
            {placeholderRoutesFor('SUPER_ADMIN')}
          </Route>
        </Route>

        <Route path="*" element={<NotFoundPage />} />
      </Routes>
      <ToastViewport />
    </AuthProvider>
  )
}
