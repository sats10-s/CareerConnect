import { adminService } from '@/services/adminService'
import { useApiResource } from './useApiResource'

export interface CompanyOption {
  id: number
  name: string
}

/**
 * Companies to offer in pickers (new job/internship/drive, filters).
 * The backend has NO companies list endpoint. The best real sources it
 * does expose are the company-wise placement report (every company with
 * at least one application) and the companies already embedded in
 * postings - so this merges those two. A company that has neither an
 * application nor a posting cannot appear here; that gap is on the API,
 * not something to paper over with a fake endpoint.
 */
export function useCompanyOptions() {
  return useApiResource<CompanyOption[]>(async () => {
    const found = new Map<number, string>()
    const [report, jobs, internships] = await Promise.allSettled([
      adminService.reports.placements({ dimension: 'company' }),
      adminService.jobs.list({ per_page: 100 }),
      adminService.internships.list({ per_page: 100 }),
    ])
    if (report.status === 'fulfilled') {
      for (const row of report.value.breakdown.rows) if (row.id !== null) found.set(row.id, row.label)
    }
    for (const result of [jobs, internships]) {
      if (result.status === 'fulfilled') for (const item of result.value.items) if (item.company) found.set(item.company.id, item.company.name)
    }
    if (found.size === 0 && report.status === 'rejected' && jobs.status === 'rejected' && internships.status === 'rejected') {
      throw report.reason
    }
    return [...found.entries()].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name))
  }, 'company-options')
}

/** Faculty list for the "assign a coordinator" picker - from the faculty workload report, the only endpoint that lists faculty ids. */
export function useFacultyOptions() {
  return useApiResource(() => adminService.reports.faculty(), 'faculty-options')
}
