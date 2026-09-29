import type { OpportunitySummary } from './application'
import type { AttendanceStatus } from './attendance'
import type { InternshipAssignmentStatus, InternshipAssignmentStatusHistoryItem } from './internshipAssignment'
import type {
  InternshipCompletionOutcome,
  InternshipCompletionRecordStatus,
  PpoStatus,
} from './internshipCompletion'
import type { InternshipDocumentStatus, InternshipDocumentType } from './internshipDocument'
import type { WeeklyReportStatus } from './weeklyReport'

/**
 * Shapes shared by the Faculty and Placement Admin coordination
 * screens (students on an internship, their assignments, attendance,
 * weekly reports, documents, completion and PPO). Every one mirrors a
 * Laravel API Resource that both roles receive - faculty just only
 * ever sees rows tied to their own assignments, enforced server-side
 * (InternshipAssignmentPolicy and friends), never here.
 */

export interface StudentBrief {
  id: number
  name: string
  roll_number: string
}

export interface StudentSummary extends StudentBrief {
  department: string | null
  year: number | null
}

export interface FacultyBrief {
  id: number
  name: string
  email?: string
}

/** The student/faculty/internship trio nearly every coordination Resource appends. */
export interface CoordinationContext {
  student: StudentBrief | null
  faculty: FacultyBrief | null
  internship: OpportunitySummary | null
}

/** GET /faculty/students - FacultyStudentListResource. */
export interface FacultyStudentItem {
  id: number
  name: string
  email: string
  roll_number: string
  department: string | null
  batch: string | null
  year: number | null
  assignment: {
    id: number
    status: InternshipAssignmentStatus
    assigned_at: string | null
    started_at: string | null
    ended_at: string | null
    internship: OpportunitySummary | null
  } | null
}

/** GET /{faculty|admin}/internship-assignments - list rows. */
export interface AssignmentListItem {
  id: number
  status: InternshipAssignmentStatus
  assigned_at: string | null
  started_at: string | null
  ended_at: string | null
  student: StudentSummary | null
  faculty: FacultyBrief | null
  internship: OpportunitySummary | null
}

export interface FacultyHistoryItem {
  id: number
  from_faculty: FacultyBrief | null
  to_faculty: FacultyBrief | null
  remarks: string | null
  changed_by: { id: number; name: string } | null
  created_at: string
}

/** GET /{faculty|admin}/internship-assignments/{id}. `notes`, `assigned_by` and timestamps are admin-only. */
export interface AssignmentDetail extends AssignmentListItem {
  application: { id: number; status: string } | null
  status_history: InternshipAssignmentStatusHistoryItem[]
  faculty_history: FacultyHistoryItem[]
  notes?: string | null
  assigned_by?: { id: number; name: string } | null
}

export interface AttendanceRecord extends CoordinationContext {
  id: number
  internship_assignment_id: number
  attendance_date: string
  status: AttendanceStatus
  check_in_time: string | null
  check_out_time: string | null
}

export interface AttendanceRecordDetail extends AttendanceRecord {
  remarks: string | null
}

export interface AssignmentAttendanceSummary {
  internship_assignment_id: number
  total_days: number
  present_days: number
  absent_days: number
  leave_days: number
  attendance_percentage: number
  last_attendance_date: string | null
  current_streak: number
}

export interface WeeklyReportRow extends CoordinationContext {
  id: number
  internship_assignment_id: number
  week_number: number
  week_start_date: string
  week_end_date: string
  status: WeeklyReportStatus
  student_submitted_at: string | null
  faculty_reviewed_at: string | null
}

export interface StatusChange<S extends string> {
  id: number
  from_status: S | null
  to_status: S
  changed_by: { id: number; name: string } | null
  remarks: string | null
  created_at: string
}

export interface WeeklyReportFull extends WeeklyReportRow {
  work_done: string
  learning_outcomes: string | null
  challenges: string | null
  next_week_plan: string | null
  faculty_review_remarks: string | null
  history: StatusChange<WeeklyReportStatus>[]
}

export interface DocumentRow extends CoordinationContext {
  id: number
  internship_assignment_id: number
  document_type: InternshipDocumentType
  title: string
  original_filename: string
  file_size: number
  status: InternshipDocumentStatus
  submitted_at: string | null
  reviewed_at: string | null
}

export interface DocumentFull extends DocumentRow {
  mime_type: string
  student_notes: string | null
  faculty_review_remarks: string | null
  approved_at: string | null
  changes_requested_at: string | null
  uploaded_by: { id: number; name: string } | null
  history: StatusChange<InternshipDocumentStatus>[]
}

export interface CompletionRow extends CoordinationContext {
  id: number
  internship_assignment_id: number
  completion_date: string | null
  outcome: InternshipCompletionOutcome | null
  status: InternshipCompletionRecordStatus
  finalized_at: string | null
}

export interface CompletionFull extends CompletionRow {
  final_remarks: string | null
  completion_certificate_document_id: number | null
  created_at: string
  updated_at: string
  completed_by: { id: number; name: string } | null
  certificate_document: { id: number; title: string } | null
}

export interface PpoRow extends CoordinationContext {
  id: number
  internship_assignment_id: number
  company: { id: number; name: string } | null
  offered_at: string | null
  designation: string | null
  status: PpoStatus
}

export interface PpoFull extends PpoRow {
  joining_date: string | null
  package: string | null
  remarks: string | null
  created_at: string
  updated_at: string
}

/** GET /faculty/reports/summary. */
export interface FacultyWorkload {
  faculty_id: number
  faculty_name: string
  department: string | null
  assigned_students: number
  active_students: number
  pending_weekly_reports: number
  pending_documents: number
}
