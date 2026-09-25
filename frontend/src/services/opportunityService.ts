import { api } from './api'
import type { ApiEnvelope, PaginatedList } from '@/types/common'
import type { JobPostingDetail, InternshipDetail, OpportunityListItem, OpportunityType, WorkMode } from '@/types/opportunity'
import type { ApplicationDetail } from '@/types/application'

export interface OpportunityListParams {
  search?: string
  type?: OpportunityType
  location?: string
  work_mode?: WorkMode
  page?: number
  per_page?: number
}

/**
 * GET/POST /api/student/opportunities/* - see
 * Api\Student\OpportunityController. Backs BOTH the Jobs and
 * Internships nav pages: index() is one endpoint returning a union of
 * job_postings/internships rows, filtered by `type` rather than split
 * across two routes, so both pages share this one service (and the
 * OpportunitiesPage component that calls it). Applying also lives
 * here rather than on applicationService, matching the backend
 * (OpportunityController owns applyToJob/applyToInternship so it can
 * reuse the same Gate::authorize('view', $opportunity) showJob/
 * showInternship already do).
 */
export const opportunityService = {
  list: (params: OpportunityListParams = {}) =>
    api
      .get<ApiEnvelope<PaginatedList<OpportunityListItem>>>('/student/opportunities', { params })
      .then((r) => r.data.data),

  showJob: (id: number) =>
    api.get<ApiEnvelope<JobPostingDetail>>(`/student/opportunities/jobs/${id}`).then((r) => r.data.data),

  showInternship: (id: number) =>
    api.get<ApiEnvelope<InternshipDetail>>(`/student/opportunities/internships/${id}`).then((r) => r.data.data),

  applyToJob: (id: number) =>
    api.post<ApiEnvelope<ApplicationDetail>>(`/student/opportunities/jobs/${id}/apply`).then((r) => r.data.data),

  applyToInternship: (id: number) =>
    api.post<ApiEnvelope<ApplicationDetail>>(`/student/opportunities/internships/${id}/apply`).then((r) => r.data.data),
}
