import { api } from './api'
import type { ApiEnvelope } from '@/types/common'
import type { AttendanceListItem, AttendanceSummary } from '@/types/attendance'

export interface AssignmentAttendance {
  summary: AttendanceSummary
  records: AttendanceListItem[]
}

/**
 * GET /api/student/internship-assignments/{id}/attendance - see
 * Api\Student\AttendanceController::forAssignment(). Read-only - no
 * student-facing write route exists anywhere on this resource
 * (marking/correcting attendance is faculty/admin-only).
 */
export const attendanceService = {
  forAssignment: (assignmentId: number) =>
    api
      .get<ApiEnvelope<{ assignment: unknown; summary: AttendanceSummary; records: AttendanceListItem[] }>>(
        `/student/internship-assignments/${assignmentId}/attendance`,
      )
      .then((r): AssignmentAttendance => ({ summary: r.data.data.summary, records: r.data.data.records })),
}
