import { api } from './api'
import type { ApiEnvelope, PaginatedList } from '@/types/common'
import type { ApplicationDetail, ApplicationListItem, ApplicationStatus } from '@/types/application'

export interface ApplicationListParams {
  status?: ApplicationStatus
  type?: 'job' | 'internship'
  search?: string
  page?: number
  per_page?: number
}

/** GET/POST /api/student/applications/* - see Api\Student\ApplicationController. Applying itself lives on opportunityService (Api\Student\OpportunityController owns that action). */
export const applicationService = {
  list: (params: ApplicationListParams = {}) =>
    api.get<ApiEnvelope<PaginatedList<ApplicationListItem>>>('/student/applications', { params }).then((r) => r.data.data),

  show: (id: number) =>
    api.get<ApiEnvelope<ApplicationDetail>>(`/student/applications/${id}`).then((r) => r.data.data),

  withdraw: (id: number, remarks?: string) =>
    api
      .post<ApiEnvelope<ApplicationDetail>>(`/student/applications/${id}/withdraw`, { remarks })
      .then((r) => r.data.data),
}
