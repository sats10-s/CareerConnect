import type { CompanyStatus } from './auth'
import type { CompanyRecord } from './admin'
import type { Role } from './auth'

export interface ManagedUser {
  id: number
  name: string
  email: string
  role: Role
  role_label: string
  is_active: boolean
  is_self: boolean
  last_login_at: string | null
  created_at: string | null
  tokens_count?: number
  student?: { id: number; roll_number: string; department: string | null; batch: string | null; year: number | null } | null
  faculty?: { id: number; employee_code: string; department_id: number; department: string | null; designation: string | null; phone: string | null } | null
}

export type StaffRole = 'FACULTY' | 'PLACEMENT_ADMIN' | 'SUPER_ADMIN'

export interface CreateUserPayload {
  name: string
  email: string
  role: StaffRole
  password: string
  password_confirmation: string
  employee_code?: string
  department_id?: number
  designation?: string | null
  phone?: string | null
}

export type UpdateUserPayload = Partial<Pick<CreateUserPayload, 'name' | 'email' | 'employee_code' | 'department_id' | 'designation' | 'phone'>> & {
  role?: 'PLACEMENT_ADMIN' | 'SUPER_ADMIN'
}

export interface UserListParams {
  search?: string
  role?: Role
  status?: 'active' | 'inactive'
  page?: number
  per_page?: number
}

export interface RoleSummary {
  name: Role
  label: string
  users_count: number
  active_count: number
  capabilities: string[]
}

export interface DepartmentRecord {
  id: number
  name: string
  code: string
  /** Gets this department's weekly placement summary (when switched on in Settings). */
  hod_email: string | null
  students_count: number
  faculty_count: number
  created_at: string | null
}

export interface BatchRecord {
  id: number
  name: string
  start_year: number
  end_year: number
  students_count: number
  created_at: string | null
}

export interface AuditLogEntry {
  id: number
  action: string
  action_label: string
  description: string
  actor: { id: number | null; name: string; role: Role | null } | null
  subject_type: string | null
  subject_id: number | null
  metadata: Record<string, unknown> | null
  ip_address: string | null
  user_agent: string | null
  created_at: string | null
}

export interface AuditLogParams {
  search?: string
  action?: string
  area?: 'auth' | 'user' | 'department' | 'batch' | 'company' | 'settings'
  date_from?: string
  date_to?: string
  page?: number
  per_page?: number
}

export interface SecurityOverview {
  stats: {
    successful_logins_24h: number
    failed_logins_24h: number
    failed_logins_7d: number
    blocked_logins_7d: number
    inactive_accounts: number
    never_signed_in: number
    api_tokens: number
    web_sessions: number | null
  }
  failed_by_account: { email: string; attempts: number; last_attempt_at: string | null; ip_addresses: string[] }[]
  recent_events: { id: number; action: string; description: string; ip_address: string | null; created_at: string | null }[]
  policy: { password_min_length: number; password_rules: string[]; session_lifetime_minutes: number; deactivation_signs_out: boolean }
}

export interface InstitutionSettings {
  institution_name: string
  support_email: string | null
  academic_year: string | null
  allow_student_registration: boolean
  allow_company_registration: boolean
  company_postings_require_review: boolean
  weekly_hod_summary: boolean
  attendance_threshold: number
}

export interface PublicSettings {
  institution_name: string
  support_email: string | null
  allow_student_registration: boolean
  allow_company_registration: boolean
}

/** A row in Super Admin > Company Approvals: the company plus reviewer hints. */
export interface CompanyApprovalRecord extends CompanyRecord {
  possible_duplicates: { id: number; name: string; status: CompanyStatus | null; reasons: ('name' | 'website')[] }[]
}

export type CompanyStatusCounts = Record<CompanyStatus, number>
