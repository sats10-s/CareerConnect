import type { InterviewWriteService } from '@/components/admin/InterviewFormModal'
import type { InterviewDrawerService } from '@/components/admin/InterviewDrawer'
import { companyService } from '@/services/companyService'
import type { InterviewDetail } from '@/types/admin'

/** The company-portal endpoints for the shared interview form and drawer. */
export const COMPANY_INTERVIEW_WRITES: InterviewWriteService = {
  schedule: companyService.interviews.schedule,
  reschedule: companyService.interviews.reschedule,
}

export const COMPANY_INTERVIEWS: InterviewDrawerService = {
  // CompanyInterview is InterviewDetail without created_by/updated_by, which the drawer doesn't read.
  show: (id) => companyService.interviews.show(id) as Promise<InterviewDetail>,
  complete: companyService.interviews.complete,
  cancel: companyService.interviews.cancel,
  writes: COMPANY_INTERVIEW_WRITES,
}
