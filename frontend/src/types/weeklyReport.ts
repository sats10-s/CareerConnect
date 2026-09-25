export type WeeklyReportStatus = 'DRAFT' | 'SUBMITTED' | 'UNDER_REVIEW' | 'CHANGES_REQUESTED' | 'APPROVED'

/** Mirrors App\Http\Resources\WeeklyProgressReportListResource, minus the student/faculty/internship summary block (faculty/admin-only - see that Resource's docblock). */
export interface WeeklyReportListItem {
  id: number
  internship_assignment_id: number
  week_number: number
  week_start_date: string
  week_end_date: string
  status: WeeklyReportStatus
  student_submitted_at: string | null
  faculty_reviewed_at: string | null
}

/** Mirrors the base (non-admin/faculty) fields of WeeklyProgressReportResource. */
export interface WeeklyReportDetail extends WeeklyReportListItem {
  work_done: string
  learning_outcomes: string | null
  challenges: string | null
  next_week_plan: string | null
  faculty_review_remarks: string | null
}
