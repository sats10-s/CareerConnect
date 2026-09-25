import { api } from './api'
import type { ApiEnvelope, PaginatedList } from '@/types/common'
import type { InterviewListItem, InterviewStatus } from '@/types/interview'

export interface InterviewListParams {
  status?: InterviewStatus
  when?: 'upcoming' | 'past'
  page?: number
  per_page?: number
}

/** GET /api/student/interviews - see Api\Student\InterviewController. Read-only for the student (no student-facing write route exists - scheduling/rescheduling is admin-only). */
export const interviewService = {
  list: (params: InterviewListParams = {}) =>
    api.get<ApiEnvelope<PaginatedList<InterviewListItem>>>('/student/interviews', { params }).then((r) => r.data.data),
}
