import { lazy, Suspense, type ComponentType } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider } from '@/context/AuthContext'
import { useAuth } from '@/hooks/useAuth'
import { Spinner } from '@/components/ui/Spinner'
import { PlaceholderPage } from '@/components/common/PlaceholderPage'
import { ProtectedRoute } from '@/routes/ProtectedRoute'
import { FirstLoginPage } from '@/pages/auth/FirstLoginPage'
import { ForgotPasswordPage } from '@/pages/auth/ForgotPasswordPage'
import { ResetPasswordPage } from '@/pages/auth/ResetPasswordPage'
import { AuthLayout } from '@/layouts/AuthLayout'
import { StudentLayout } from '@/layouts/StudentLayout'
import { FacultyLayout } from '@/layouts/FacultyLayout'
import { AdminLayout } from '@/layouts/AdminLayout'
import { SuperAdminLayout } from '@/layouts/SuperAdminLayout'
import { CompanyLayout } from '@/layouts/CompanyLayout'
import { LandingPage } from '@/pages/public/LandingPage'
import { LoginPage } from '@/pages/auth/LoginPage'
import { RegisterPage } from '@/pages/auth/RegisterPage'
import { NotFoundPage } from '@/pages/NotFound'
import { ToastViewport } from '@/components/ui/Toast'
import { NAV_ITEMS } from '@/utils/navConfig'
import { dashboardPathForRole } from '@/utils/roles'
import type { Role } from '@/types/auth'

/**
 * Each signed-in page is its own chunk, downloaded the first time it is
 * opened, so the landing and login pages don't carry the whole app.
 * Navigations run in a transition (React Router v7), so the current page
 * stays on screen while the next one loads; the Suspense fallback below
 * only shows on a cold load straight onto a signed-in page.
 */
function lazyPage<M extends Record<string, unknown>>(load: () => Promise<M>, name: keyof M & string) {
  return lazy(() => load().then((module) => ({ default: module[name] as ComponentType })))
}

const CompanyRegisterPage = lazyPage(() => import('@/pages/company/Register'), 'CompanyRegisterPage')
const CompanyDashboardPage = lazyPage(() => import('@/pages/company/Dashboard'), 'CompanyDashboardPage')
const CompanyProfilePage = lazyPage(() => import('@/pages/company/Profile'), 'CompanyProfilePage')
const CompanyNotificationsPage = lazyPage(() => import('@/pages/company/Notifications'), 'CompanyNotificationsPage')
const CompanyPostingsPage = lazyPage(() => import('@/pages/company/Postings'), 'CompanyPostingsPage')
const CompanyApplicantsPage = lazyPage(() => import('@/pages/company/Applicants'), 'CompanyApplicantsPage')
const CompanyInterviewsPage = lazyPage(() => import('@/pages/company/Interviews'), 'CompanyInterviewsPage')
const CompanyTeamPage = lazyPage(() => import('@/pages/company/Team'), 'CompanyTeamPage')
const StudentDashboardPage = lazyPage(() => import('@/pages/student/Dashboard'), 'StudentDashboardPage')
const StudentProfilePage = lazyPage(() => import('@/pages/student/Profile'), 'StudentProfilePage')
const StudentNotificationsPage = lazyPage(() => import('@/pages/student/Notifications'), 'StudentNotificationsPage')
const StudentApplicationsPage = lazyPage(() => import('@/pages/student/Applications'), 'StudentApplicationsPage')
const ApplicationDetailPage = lazyPage(() => import('@/pages/student/ApplicationDetail'), 'ApplicationDetailPage')
const StudentJobsPage = lazyPage(() => import('@/pages/student/Jobs'), 'StudentJobsPage')
const StudentInternshipsPage = lazyPage(() => import('@/pages/student/Internships'), 'StudentInternshipsPage')
const JobDetailPage = lazyPage(() => import('@/pages/student/JobDetail'), 'JobDetailPage')
const InternshipDetailPage = lazyPage(() => import('@/pages/student/InternshipDetail'), 'InternshipDetailPage')
const MyInternshipPage = lazyPage(() => import('@/pages/student/MyInternship'), 'MyInternshipPage')
const StudentInterviewsPage = lazyPage(() => import('@/pages/student/Interviews'), 'StudentInterviewsPage')
const FacultyDashboardPage = lazyPage(() => import('@/pages/faculty/Dashboard'), 'FacultyDashboardPage')
const FacultyStudentsPage = lazyPage(() => import('@/pages/faculty/Students'), 'FacultyStudentsPage')
const FacultyInternshipsPage = lazyPage(() => import('@/pages/faculty/Internships'), 'FacultyInternshipsPage')
const FacultyAttendancePage = lazyPage(() => import('@/pages/faculty/Attendance'), 'FacultyAttendancePage')
const FacultyProgressPage = lazyPage(() => import('@/pages/faculty/Progress'), 'FacultyProgressPage')
const FacultyDocumentsPage = lazyPage(() => import('@/pages/faculty/Documents'), 'FacultyDocumentsPage')
const FacultyEvaluationsPage = lazyPage(() => import('@/pages/faculty/Evaluations'), 'FacultyEvaluationsPage')
const FacultyReportsPage = lazyPage(() => import('@/pages/faculty/Reports'), 'FacultyReportsPage')
const AdminDashboardPage = lazyPage(() => import('@/pages/admin/Dashboard'), 'AdminDashboardPage')
const AdminNotificationsPage = lazyPage(() => import('@/pages/admin/Notifications'), 'AdminNotificationsPage')
const AdminJobsPage = lazyPage(() => import('@/pages/admin/Jobs'), 'AdminJobsPage')
const AdminInternshipsPage = lazyPage(() => import('@/pages/admin/Internships'), 'AdminInternshipsPage')
const AdminExcelSyncPage = lazyPage(() => import('@/pages/admin/ExcelSync'), 'AdminExcelSyncPage')
const AdminReportsPage = lazyPage(() => import('@/pages/admin/Reports'), 'AdminReportsPage')
const AdminFacultyPage = lazyPage(() => import('@/pages/admin/Faculty'), 'AdminFacultyPage')
const AdminStudentsPage = lazyPage(() => import('@/pages/admin/Students'), 'AdminStudentsPage')
const AdminCompaniesPage = lazyPage(() => import('@/pages/admin/Companies'), 'AdminCompaniesPage')
const SuperAdminDepartmentsPage = lazyPage(() => import('@/pages/superadmin/MetaLists'), 'SuperAdminDepartmentsPage')
const SuperAdminBatchesPage = lazyPage(() => import('@/pages/superadmin/MetaLists'), 'SuperAdminBatchesPage')
const AdminApplicationsPage = lazyPage(() => import('@/pages/admin/Applications'), 'AdminApplicationsPage')
const AdminInterviewsPage = lazyPage(() => import('@/pages/admin/Interviews'), 'AdminInterviewsPage')
const AdminPlacementDrivesPage = lazyPage(() => import('@/pages/admin/PlacementDrives'), 'AdminPlacementDrivesPage')
const SuperAdminDashboardPage = lazyPage(() => import('@/pages/superadmin/Dashboard'), 'SuperAdminDashboardPage')
const SuperAdminUsersPage = lazyPage(() => import('@/pages/superadmin/Users'), 'SuperAdminUsersPage')
const SuperAdminCompanyApprovalsPage = lazyPage(() => import('@/pages/superadmin/CompanyApprovals'), 'SuperAdminCompanyApprovalsPage')
const SuperAdminRolesPage = lazyPage(() => import('@/pages/superadmin/Roles'), 'SuperAdminRolesPage')
const SuperAdminAuditLogsPage = lazyPage(() => import('@/pages/superadmin/AuditLogs'), 'SuperAdminAuditLogsPage')
const SuperAdminSecurityPage = lazyPage(() => import('@/pages/superadmin/Security'), 'SuperAdminSecurityPage')
const SuperAdminSettingsPage = lazyPage(() => import('@/pages/superadmin/Settings'), 'SuperAdminSettingsPage')
const SuperAdminStudentDataPage = lazyPage(() => import('@/pages/superadmin/StudentData'), 'SuperAdminStudentDataPage')

/** Renders every non-dashboard nav item for a role as a PlaceholderPage. */
function placeholderRoutesFor(role: Role) {
  return NAV_ITEMS[role]
    .filter((item) => !item.implemented)
    .map((item) => {
      const relativePath = item.path.split('/').slice(2).join('/')
      return <Route key={item.path} path={relativePath} element={<PlaceholderPage title={item.label} icon={item.icon} note={item.note} />} />
    })
}

function FullPageSpinner() {
  return (
    <div className="flex min-h-screen items-center justify-center bg-base">
      <Spinner className="h-8 w-8" />
    </div>
  )
}

function RootRedirect() {
  const { isAuthenticated, isLoading, user } = useAuth()

  if (isLoading) {
    return <FullPageSpinner />
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
      <Suspense fallback={<FullPageSpinner />}>
        <Routes>
          <Route path="/" element={<RootRedirect />} />

          <Route element={<AuthLayout />}>
            <Route path="/login" element={<LoginPage />} />
            <Route path="/register" element={<RegisterPage />} />
            <Route path="/forgot-password" element={<ForgotPasswordPage />} />
            <Route path="/reset-password" element={<ResetPasswordPage />} />
            <Route path="/first-login" element={<FirstLoginPage />} />
            <Route path="/company/register" element={<CompanyRegisterPage />} />
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
              <Route path="students" element={<AdminStudentsPage />} />
              <Route path="company-approvals" element={<SuperAdminCompanyApprovalsPage />} />
              <Route path="roles" element={<SuperAdminRolesPage />} />
              <Route path="security" element={<SuperAdminSecurityPage />} />
              <Route path="audit-logs" element={<SuperAdminAuditLogsPage />} />
              <Route path="settings" element={<SuperAdminSettingsPage />} />
              {placeholderRoutesFor('SUPER_ADMIN')}
            </Route>
          </Route>

          {/* Company portal. The dashboard shows the review status until the
              company is approved; the recruiting pages show a locked state. */}
          <Route element={<ProtectedRoute allowedRoles={['COMPANY']} />}>
            <Route path="/company" element={<CompanyLayout />}>
              <Route path="dashboard" element={<CompanyDashboardPage />} />
              <Route path="postings" element={<CompanyPostingsPage />} />
              <Route path="applicants" element={<CompanyApplicantsPage />} />
              <Route path="interviews" element={<CompanyInterviewsPage />} />
              <Route path="team" element={<CompanyTeamPage />} />
              <Route path="profile" element={<CompanyProfilePage />} />
              <Route path="notifications" element={<CompanyNotificationsPage />} />
              {placeholderRoutesFor('COMPANY')}
            </Route>
          </Route>

          <Route path="*" element={<NotFoundPage />} />
        </Routes>
      </Suspense>
      <ToastViewport />
    </AuthProvider>
  )
}
