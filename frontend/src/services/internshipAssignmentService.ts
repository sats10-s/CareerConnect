import { api } from './api'
import type { ApiEnvelope } from '@/types/common'
import type { InternshipAssignmentDetail } from '@/types/internshipAssignment'

/**
 * GET /api/student/internship-assignment - see
 * Api\Student\InternshipAssignmentController, added during this
 * build-out (Phase 1 found no student-facing route existed). `data`
 * is null when the student has no assignment yet - the ordinary state
 * before they're selected for an internship, not an error.
 */
export const internshipAssignmentService = {
  current: () =>
    api.get<ApiEnvelope<InternshipAssignmentDetail | null>>('/student/internship-assignment').then((r) => r.data.data),
}
