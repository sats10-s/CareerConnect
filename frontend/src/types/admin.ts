import type { ApplicationStatus, OpportunitySummary } from './application'
import type { InternshipAssignmentStatus } from './internshipAssignment'
import type { StatusChange } from './coordination'
import type { StudentProfileData } from './studentProfile'

/** Mirrors the Laravel resources behind the /api/admin/* endpoints the Placement Admin screens use. */

export type OpportunityLifecycle = 'draft' | 'published' | 'closed' | 'archived'
export type EmploymentTypeValue = 'full_time' | 'part_time' | 'contract' | 'temporary'
export type WorkModeValue = 'on_site' | 'remote' | 'hybrid'

export interface CompanyRef {
  id: number
  name: string
  industry?: string | null
  logo_path: string | null
}

export interface OpportunityEligibility {
  enforced: boolean
  minimum_cgpa: number | null
  maximum_backlogs: number | null
  /** Only present on show/create/update responses, not on list rows. */
  departments?: { id: number; name: string | null }[]
  batches?: { id: number; name: string | null }[]
  years?: number[]
  required_skills?: string[]
}

/** JobPostingResource / InternshipResource (one shape; job-only and internship-only fields are optional). */
export interface AdminOpportunity {
  id: number
  type: 'job' | 'internship'
  title: string
  description: string | null
  location: string | null
  work_mode: WorkModeValue | null
  application_deadline: string | null
  openings: number | null
  status: OpportunityLifecycle
  is_active: boolean
  salary_range?: string | null
  employment_type?: EmploymentTypeValue | null
  duration_months?: number | null
  stipend?: string | null
  company: CompanyRef | null
  creator: { id: number; name: string } | null
  updater?: { id: number; name: string } | null
  eligibility: OpportunityEligibility
  created_at: string
  updated_at: string
}

export interface OpportunityPayload {
  company_id: number
  title: string
  description?: string | null
  location?: string | null
  work_mode?: WorkModeValue | null
  application_deadline?: string | null
  openings?: number | null
  status?: OpportunityLifecycle
  is_active?: boolean
  salary_range?: string | null
  employment_type?: EmploymentTypeValue | null
  duration_months?: number | null
  stipend?: string | null
  eligibility_enforced?: boolean
  minimum_cgpa?: number | null
  maximum_backlogs?: number | null
  eligible_department_ids?: number[]
  eligible_batch_ids?: number[]
  eligible_years?: number[]
  required_skills?: string[]
}

export interface AdminApplicationRow {
  id: number
  status: ApplicationStatus
  student: { id: number; name: string; roll_number: string; department: string | null } | null
  opportunity: OpportunitySummary | null
  applied_at: string | null
  updated_at: string | null
}

export interface AdminApplicationDetail extends AdminApplicationRow {
  student:
    | (NonNullable<AdminApplicationRow['student']> & {
        email: string
        batch: string | null
        year: number | null
        cgpa: number | null
        backlogs: number | null
        resume: unknown
      })
    | null
  withdrawn_at: string | null
  selected_at: string | null
  rejection_reason: string | null
  notes: string | null
  status_history: StatusChange<ApplicationStatus>[]
}

export type InterviewMode = 'ONLINE' | 'OFFLINE' | 'HYBRID'
export type InterviewStatus = 'SCHEDULED' | 'COMPLETED' | 'CANCELLED' | 'RESCHEDULED'

export interface InterviewRow {
  id: number
  round: number
  title: string | null
  scheduled_at: string
  duration_minutes: number | null
  mode: InterviewMode
  status: InterviewStatus
  student: { id: number; name: string; roll_number: string; department: string | null } | null
  opportunity: OpportunitySummary | null
  application: { id: number; status: ApplicationStatus } | null
}

export interface InterviewDetail extends InterviewRow {
  meeting_url: string | null
  venue: string | null
  interviewer_name: string | null
  interviewer_email: string | null
  panel_notes: string | null
  cancelled_at: string | null
  cancellation_reason: string | null
  completed_at: string | null
  created_by: { id: number; name: string } | null
  updated_by: { id: number; name: string } | null
  status_history: StatusChange<InterviewStatus>[]
}

export interface InterviewPayload {
  round: number
  title?: string | null
  scheduled_at: string
  duration_minutes?: number | null
  mode: InterviewMode
  meeting_url?: string | null
  venue?: string | null
  interviewer_name?: string | null
  interviewer_email?: string | null
  panel_notes?: string | null
}

export type DriveType = 'CAMPUS_PLACEMENT' | 'INTERNSHIP' | 'BOTH'
export type DriveStatus = 'DRAFT' | 'PUBLISHED' | 'CLOSED' | 'CANCELLED'

export interface DriveRow {
  id: number
  title: string
  drive_type: DriveType
  status: DriveStatus
  venue: string | null
  scheduled_at: string | null
  application_deadline: string | null
  company: CompanyRef | null
  opportunity_count: number
  application_count: number
  selected_count: number
  created_at: string
}

export interface DriveDetail extends Omit<DriveRow, 'opportunity_count'> {
  description: string | null
  cancelled_at: string | null
  cancellation_reason: string | null
  opportunities: { attachment_id: number; opportunity: OpportunitySummary; attached_at: string }[]
  interview_count: number
  status_history: StatusChange<DriveStatus>[]
}

export interface DrivePayload {
  company_id: number
  title: string
  description?: string | null
  drive_type: DriveType
  venue?: string | null
  scheduled_at?: string | null
  application_deadline?: string | null
}

export type ExcelImportType =
  | 'STUDENT_MASTER'
  | 'PLACEMENT_STATUS'
  | 'INTERNSHIP_STATUS'
  | 'INTERVIEW_SCHEDULE'
  | 'SHORTLIST'
  | 'ASSESSMENT_RESULT'
  | 'FINAL_SELECTION'
  | 'ATTENDANCE'

export type ExcelExportType =
  | 'STUDENTS'
  | 'APPLICATIONS'
  | 'INTERVIEWS'
  | 'INTERNSHIP_ASSIGNMENTS'
  | 'PPO_RECORDS'
  | 'ATTENDANCE'
  | 'INTERNSHIP_COMPLETIONS'
  | 'WEEKLY_PROGRESS_REPORTS'
  | 'INTERNSHIP_DOCUMENTS'
  | 'PLACEMENT_DRIVES'
  | 'COMPANIES'
  | 'APPLICATION_SUMMARY'
  | 'STATUS_SUMMARY'
  | 'COMPANY_WISE_PLACEMENT_REPORT'
  | 'DEPARTMENT_WISE_PLACEMENT_REPORT'
  | 'BATCH_WISE_PLACEMENT_REPORT'
  | 'STUDENT_APPLICATION_REPORT'
  | 'UNPLACED_STUDENT_REPORT'
  | 'STUDENT_APPLICATION_ACTIVITY'

export type ExcelBatchStatus = 'UPLOADED' | 'VALIDATED' | 'READY_FOR_CONFIRMATION' | 'CONFIRMED' | 'FAILED' | 'ROLLED_BACK'

export interface ExcelBatch {
  id: number
  import_type: ExcelImportType
  import_type_label: string
  status: ExcelBatchStatus
  original_filename: string
  counts: { total_rows: number; matched_rows: number; valid_rows: number; warning_rows: number; error_rows: number }
  uploaded_by?: { id: number; name: string } | null
  confirmed_by?: { id: number; name: string } | null
  confirmed_at: string | null
  rolled_back_by?: { id: number; name: string } | null
  rolled_back_at: string | null
  error_message: string | null
  created_at: string
  updated_at: string
}

export interface ExcelPreviewRow {
  id: number
  row_number: number
  raw_row: Record<string, string | null>
  entity_type: string | null
  entity_id: number | null
  outcome: 'VALID' | 'WARNING' | 'ERROR'
  messages: string[]
  changes: { field: string; old_value: string | null; new_value: string | null }[]
  applied: boolean
  applied_at: string | null
  apply_error: string | null
}

export interface ExcelConfirmResult {
  batch: ExcelBatch
  result: { applied: number; failed: number; skipped_errors: number }
}

export interface ExcelRollbackResult {
  batch: ExcelBatch
  result: { reverted: number; conflicts: number; not_rollbackable: number; details: { change_id: number; entity_type: string; entity_id: number; field: string; result: string; message: string | null }[] }
}

export interface ReportFilters {
  department_id?: number
  batch_id?: number
  year?: number
  company_id?: number
  faculty_id?: number
  date_from?: string
  date_to?: string
}

export interface PlacementSummary {
  total_students: number
  students_applied: number
  students_not_applied: number
  total_applications: number
  unique_applicants: number
  avg_applications_per_applicant: number
  students_shortlisted: number
  students_interviewed: number
  students_selected: number
  applications_rejected: number
  applications_withdrawn: number
  students_unplaced: number
  companies_count: number
  job_opportunities_count: number
  selection_rate: number
}

export type FunnelData = Record<ApplicationStatus, { applications: number; students: number }>

export interface InternshipReport {
  total_assignments: number
  assignments_by_status: Record<InternshipAssignmentStatus, number>
  completions_finalized: number
  completions_outcome_completed: number
  completions_outcome_not_completed: number
  completion_rate: number
  ppo_offered: number
  ppo_accepted: number
  ppo_declined: number
  ppo_offer_rate: number
}

export interface BreakdownRow {
  id: number | null
  label: string
  total_applications: number
  distinct_students: number
  students_selected: number
  selection_rate: number
}

export interface AttendanceReportRow {
  internship_assignment_id: number
  student_id: number
  student_name: string
  roll_number: string
  department: string | null
  batch: string | null
  faculty_name: string | null
  present_days: number
  absent_days: number
  leave_days: number
  total_marked_days: number
  attendance_percentage: number
}

export interface FacultyWorkloadRow {
  faculty_id: number
  faculty_name: string
  department: string | null
  assigned_students: number
  active_students: number
  pending_weekly_reports: number
  pending_documents: number
}

// --- Students directory (GET /admin/students[...]) -----------------------

export interface AdminStudentRow {
  id: number
  name: string | null
  email: string | null
  is_active: boolean
  roll_number: string
  department: string | null
  batch: string | null
  year: number | null
  cgpa: number | null
  backlogs: number | null
  applications_count: number
  selected_count: number
  /** "SELECTED" when the student has at least one selected application, otherwise null. */
  placement_status: 'SELECTED' | null
}

export interface AdminStudentDetail {
  profile: StudentProfileData
  is_active: boolean
  applications: { id: number; status: ApplicationStatus; applied_at: string | null; opportunity: OpportunitySummary | null }[]
  internship_assignments: {
    id: number
    status: InternshipAssignmentStatus
    assigned_at: string | null
    started_at: string | null
    ended_at: string | null
    faculty: { id: number; name: string | null } | null
    internship: OpportunitySummary | null
  }[]
}

export interface StudentDirectoryParams {
  search?: string
  department_id?: number
  batch_id?: number
  year?: number
  placement?: 'placed' | 'unplaced'
  sort?: 'name' | 'roll_number' | 'cgpa'
  direction?: 'asc' | 'desc'
  page?: number
  per_page?: number
}

// --- Companies (GET/POST/PATCH /admin/companies[...]) --------------------

export interface CompanyRecord {
  id: number
  name: string
  industry: string | null
  website: string | null
  description: string | null
  logo_path: string | null
  job_postings_count?: number
  internships_count?: number
  postings?: { id: number; type: 'job' | 'internship'; title: string; status: string | null; created_at: string | null }[]
  created_at: string | null
  updated_at: string | null
}

export interface CompanyPayload {
  name: string
  industry?: string | null
  website?: string | null
  description?: string | null
}
