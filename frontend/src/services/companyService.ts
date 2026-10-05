import { api, ensureCsrfCookie } from './api'
import type { ApiEnvelope } from '@/types/common'
import type { AuthUser, CompanyRegisterPayload, CompanyStatus } from '@/types/auth'
import type { CompanyRecord } from '@/types/admin'

export type CompanyProfilePayload = Partial<
  Pick<CompanyRecord, 'name' | 'industry' | 'website' | 'description' | 'headquarters' | 'company_size' | 'registration_number' | 'contact_name' | 'contact_email' | 'contact_phone'>
>

export interface CompanyDashboardData {
  company: { id: number; name: string; status: CompanyStatus }
  stats: { job_postings: number; internships: number }
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
}
