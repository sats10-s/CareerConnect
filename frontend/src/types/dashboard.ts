export interface StudentDashboardData {
  stats: {
    applications: number
    shortlisted: number
    interviews: number
    selected: number
    profile_completion: number
  }
  /** College record for the header strip; null = not reported. */
  academics?: { cgpa: number | null; backlogs: number | null; year: number | null; section: string | null }
  recent_applications: Array<{
    id: number
    title: string | null
    company: string | null
    type: 'job' | 'internship'
    status: string
    applied_at: string
  }>
  notifications: Array<{ id: number; message: string; read: boolean }>
}

export interface FacultyDashboardData {
  stats: {
    assigned_students: number
    active_internships: number
    pending_reports: number
    attendance_alerts: number
  }
  students: Array<{
    id: number
    name: string | null
    roll_number: string
    department: string | null
    batch: string | null
  }>
}

export interface AdminDashboardData {
  stats: {
    total_students: number
    companies: number
    active_jobs: number
    active_internships: number
    applications: number
    selected_students: number
    placed_students: number
    not_placed_students: number
    unconfirmed_placement_status: number
  }
  recent_activity: Array<{ type: string; description: string; at: string }>
}

export interface SuperAdminDashboardData {
  stats: {
    users: number
    students: number
    faculty: number
    admins: number
    active_accounts: number
    audit_events: number
  }
  recent_users: Array<{
    id: number
    name: string
    email: string
    role: string
    is_active: boolean
    created_at: string
  }>
}
