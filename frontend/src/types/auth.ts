export type Role = 'STUDENT' | 'FACULTY' | 'PLACEMENT_ADMIN' | 'SUPER_ADMIN' | 'COMPANY'

/** Approval state of a company (App\Enums\CompanyStatus). */
export type CompanyStatus = 'PENDING' | 'APPROVED' | 'REJECTED' | 'SUSPENDED'

/** The company a COMPANY-role login belongs to, as /auth/me returns it. */
export interface CompanyMembership {
  id: number
  name: string
  status: CompanyStatus
  review_note: string | null
  member_role: 'OWNER' | 'RECRUITER' | null
}

export interface StudentProfile {
  roll_number: string
  department_id: number | null
  department: string | null
  batch_id: number | null
  batch: string | null
  year: number | null
  cgpa: number | null
  backlogs: number | null
  phone: string | null
}

export interface FacultyProfile {
  employee_code: string
  department: string | null
  designation: string | null
}

export interface AuthUser {
  id: number
  name: string
  email: string
  role: Role
  is_active: boolean
  student?: StudentProfile
  faculty?: FacultyProfile
  company?: CompanyMembership
  created_at: string
}

export interface LoginPayload {
  email: string
  password: string
}

export interface RegisterPayload {
  name: string
  email: string
  password: string
  password_confirmation: string
  roll_number: string
  department_id: number
  batch_id: number
  year: number
  phone?: string
}

/** POST /company/register - public company self-registration. */
export interface CompanyRegisterPayload {
  company_name: string
  industry?: string | null
  website?: string | null
  description?: string | null
  headquarters?: string | null
  company_size?: string | null
  registration_number?: string | null
  contact_name: string
  email: string
  contact_phone?: string | null
  password: string
  password_confirmation: string
}

/** Payload for PUT /student/profile - what a student can edit about themselves. */
export interface StudentProfileUpdatePayload {
  roll_number: string
  department_id: number
  batch_id: number
  year: number
  cgpa?: number | null
  backlogs?: number | null
  phone?: string | null
}

export interface ApiValidationError {
  message: string
  errors?: Record<string, string[]>
}
