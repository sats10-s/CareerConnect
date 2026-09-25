export type AttendanceStatus = 'PRESENT' | 'ABSENT' | 'LEAVE'

/** Mirrors App\Http\Resources\AttendanceListResource, minus the student/faculty/internship summary block (redundant - this is always the caller's own assignment). */
export interface AttendanceListItem {
  id: number
  internship_assignment_id: number
  attendance_date: string
  status: AttendanceStatus
  check_in_time: string | null
  check_out_time: string | null
}

/** Mirrors the plain array App\Services\AttendanceService::getAssignmentAttendanceSummary() returns, as formatted by AttendanceSummaryResource. */
export interface AttendanceSummary {
  internship_assignment_id: number
  total_days: number
  present_days: number
  absent_days: number
  leave_days: number
  attendance_percentage: number
  last_attendance_date: string | null
  current_streak: number
}
