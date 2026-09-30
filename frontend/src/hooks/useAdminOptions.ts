import { adminService } from '@/services/adminService'
import { useApiResource } from './useApiResource'

export interface CompanyOption {
  id: number
  name: string
}

/** Companies to offer in pickers (new job/internship/drive, filters) - GET /admin/companies, sorted by name. */
export function useCompanyOptions() {
  return useApiResource<CompanyOption[]>(
    () => adminService.companies.list({ per_page: 100 }).then((page) => page.items.map((c) => ({ id: c.id, name: c.name }))),
    'company-options',
  )
}

/** Faculty list for the "assign a coordinator" picker - from the faculty workload report, the only endpoint that lists faculty ids. */
export function useFacultyOptions() {
  return useApiResource(() => adminService.reports.faculty(), 'faculty-options')
}
