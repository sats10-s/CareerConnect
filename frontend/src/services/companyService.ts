import { api, ensureCsrfCookie } from './api'
import type { ApiEnvelope, PaginatedList } from '@/types/common'
import type { AuthUser, CompanyRegisterPayload, CompanyStatus } from '@/types/auth'
import type { CompanyRecord, InterviewPayload, InterviewMode } from '@/types/admin'
import type { ApplicationStatus } from '@/types/application'
import type {
  CompanyApplicant,
  CompanyApplicantParams,
  CompanyInterview,
  CompanyMember,
  CompanyPosting,
  CompanyPostingPayload,
} from '@/types/company'

export type CompanyProfilePayload = Partial<
  Pick<CompanyRecord, 'name' | 'industry' | 'website' | 'description' | 'headquarters' | 'company_size' | 'registration_number' | 'contact_name' | 'contact_email' | 'contact_phone'>
>

export interface CompanyDashboardData {
  company: { id: number; name: string; status: CompanyStatus }
  stats: { live_postings: number; in_review: number; drafts: number; applicants: number; shortlisted: number; selected: number }
  upcoming_interviews: CompanyInterview[]
}

const unwrap = <T>(p: Promise<{ data: ApiEnvelope<T> }>) => p.then((r) => r.data.data)

/**
 * /api/company/* (role:COMPANY). The company is always the signed-in
 * recruiter's own - no endpoint here takes a company id. /me, /profile and
 * notifications work while the company is pending; /dashboard needs it
 * approved (403 otherwise).
 */
export const companyService = {
  async register(payload: CompanyRegisterPayload): Promise<AuthUser> {
    await ensureCsrfCookie()
    return unwrap(api.post<ApiEnvelope<AuthUser>>('/company/register', payload))
  },
  me: () => unwrap(api.get<ApiEnvelope<CompanyRecord>>('/company/me')),
  updateProfile: (payload: CompanyProfilePayload) => unwrap(api.patch<ApiEnvelope<CompanyRecord>>('/company/profile', payload)),
  dashboard: () => unwrap(api.get<ApiEnvelope<CompanyDashboardData>>('/company/dashboard')),

  postings: {
    list: (params: { type?: string; status?: string; search?: string } = {}) =>
      unwrap(api.get<ApiEnvelope<{ items: CompanyPosting[] }>>('/company/postings', { params })).then((d) => d.items),
    show: (type: 'job' | 'internship', id: number) => unwrap(api.get<ApiEnvelope<CompanyPosting>>(`/company/postings/${type}/${id}`)),
    create: (payload: CompanyPostingPayload & { type: 'job' | 'internship'; title: string }) => unwrap(api.post<ApiEnvelope<CompanyPosting>>('/company/postings', payload)),
    update: (type: 'job' | 'internship', id: number, payload: CompanyPostingPayload) =>
      unwrap(api.patch<ApiEnvelope<CompanyPosting>>(`/company/postings/${type}/${id}`, payload)),
    submit: (type: 'job' | 'internship', id: number) =>
      api.post<ApiEnvelope<CompanyPosting>>(`/company/postings/${type}/${id}/submit`).then((r) => ({ posting: r.data.data, message: r.data.message })),
    close: (type: 'job' | 'internship', id: number) => unwrap(api.post<ApiEnvelope<CompanyPosting>>(`/company/postings/${type}/${id}/close`)),
  },

  applicants: {
    list: (params: CompanyApplicantParams = {}) => unwrap(api.get<ApiEnvelope<PaginatedList<CompanyApplicant>>>('/company/applications', { params })),
    show: (id: number) => unwrap(api.get<ApiEnvelope<CompanyApplicant>>(`/company/applications/${id}`)),
    resume: (id: number) => api.get<Blob>(`/company/applications/${id}/resume`, { responseType: 'blob' }).then((r) => r.data),
    updateStatus: (id: number, status: ApplicationStatus, remarks?: string) =>
      unwrap(api.patch<ApiEnvelope<CompanyApplicant>>(`/company/applications/${id}/status`, { status, remarks })),
  },

  interviews: {
    list: (params: { status?: string; upcoming?: 1; page?: number; per_page?: number } = {}) =>
      unwrap(api.get<ApiEnvelope<PaginatedList<CompanyInterview>>>('/company/interviews', { params })),
    show: (id: number) => unwrap(api.get<ApiEnvelope<CompanyInterview>>(`/company/interviews/${id}`)),
    schedule: (applicationId: number, payload: InterviewPayload) =>
      unwrap(api.post<ApiEnvelope<CompanyInterview>>(`/company/applications/${applicationId}/interviews`, payload)),
    reschedule: (id: number, payload: { scheduled_at: string; duration_minutes?: number | null; mode?: InterviewMode; meeting_url?: string | null; venue?: string | null; remarks?: string | null }) =>
      unwrap(api.post<ApiEnvelope<CompanyInterview>>(`/company/interviews/${id}/reschedule`, payload)),
    complete: (id: number, remarks?: string) => unwrap(api.post<ApiEnvelope<CompanyInterview>>(`/company/interviews/${id}/complete`, { remarks })),
    cancel: (id: number, reason: string) => unwrap(api.post<ApiEnvelope<CompanyInterview>>(`/company/interviews/${id}/cancel`, { reason })),
  },

  members: {
    list: () => unwrap(api.get<ApiEnvelope<{ items: CompanyMember[]; can_manage: boolean }>>('/company/members')),
    add: (payload: { name: string; email: string; password: string; password_confirmation: string }) =>
      unwrap(api.post<ApiEnvelope<CompanyMember>>('/company/members', payload)),
    setActive: (id: number, is_active: boolean) => unwrap(api.patch<ApiEnvelope<CompanyMember>>(`/company/members/${id}/status`, { is_active })),
  },
}
