export type Role = 'STUDENT' | 'FACULTY' | 'PLACEMENT_ADMIN' | 'SUPER_ADMIN'

export interface StudentProfile {
  roll_number: string
  department: string | null
  batch: string | null
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
  phone?: string
}

export interface ApiValidationError {
  message: string
  errors?: Record<string, string[]>
}
