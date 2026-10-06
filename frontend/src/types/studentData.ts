/** Super Admin > Student Data - mirrors StudentImportBatchResource / StudentImportRowResource. */

export type StudentImportType = 'students' | 'academic_update'

export type StudentImportStatus = 'UPLOADED' | 'VALIDATED' | 'READY_FOR_CONFIRMATION' | 'PROCESSING' | 'CONFIRMED' | 'FAILED' | 'ROLLED_BACK' | 'CANCELLED'

export interface StudentImportCounts {
  new: number
  updated: number
  unchanged: number
  error: number
  warning: number
}

export interface StudentImportSheet extends StudentImportCounts {
  name: string
  rows: number
  skipped: boolean
  reason: string | null
  columns: string[]
  /** A sheet-wide problem reported once instead of on every row (e.g. a CGPA column holding percentages). */
  note?: string | null
}

export interface StudentImportBatch {
  id: number
  type: StudentImportType
  type_label: string
  status: StudentImportStatus
  original_filename: string
  semester_label: string | null
  counts: StudentImportCounts
  total_rows: number
  sheets: StudentImportSheet[]
  unknown_departments: { value: string; rows: number }[]
  result: { created: number; updated: number; failed: number; one_time_passwords: number } | null
  progress: { done: number; total: number }
  has_one_time_passwords: boolean
  can_rollback: boolean
  rollback_blocker: string | null
  uploaded_by?: { id: number; name: string }
  confirmed_by?: { id: number; name: string } | null
  rolled_back_by?: { id: number; name: string } | null
  confirmed_at: string | null
  rolled_back_at: string | null
  error_message: string | null
  created_at: string
}

export type StudentImportTab = 'new' | 'updated' | 'unchanged' | 'warnings' | 'errors' | 'flagged'

export interface StudentImportChange {
  field: string
  label: string
  old_value: string | number | null
  new_value: string | number | null
}

export interface StudentImportRow {
  id: number
  sheet: string | null
  sheet_row: number | null
  category: 'new' | 'updated' | 'unchanged' | 'error'
  outcome: 'VALID' | 'WARNING' | 'ERROR'
  roll_number: string
  name: string | null
  values: Record<string, string | number | null>
  flagged: boolean
  unknown_department: string | null
  needs_one_time_password: boolean
  messages: string[]
  changes: StudentImportChange[]
  applied: boolean
  apply_error: string | null
}

export interface OneTimePassword {
  roll_number: string
  name: string
  password: string
}

export interface DepartmentAliasRecord {
  id: number
  alias: string
  department: { id: number; name: string; code: string }
}
