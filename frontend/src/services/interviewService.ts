import { api } from './api'
import type { ApiEnvelope, PaginatedList } from '@/types/common'
import type { InterviewDetail, InterviewListItem, InterviewStatus } from '@/types/interview'

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
  /** GET /api/student/interviews/{interview} - see Api\Student\InterviewController::show. Added for the Application Detail page's Interview connection (Frontend Phase 3 section 13), which needs meeting_url/venue/interviewer_name that the list row doesn't carry. */
  show: (id: number) =>
    api.get<ApiEnvelope<InterviewDetail>>(`/student/interviews/${id}`).then((r) => r.data.data),
}
