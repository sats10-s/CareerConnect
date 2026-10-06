import type { AdminOpportunity, EmploymentTypeValue, InterviewDetail, WorkModeValue } from './admin'
import type { ApplicationStatus, OpportunitySummary } from './application'
import type { StatusChange } from './coordination'

/** How a recruiter should read a posting's state (Api\Company\PostingController::reviewState). */
export type PostingReviewState = 'draft' | 'in_review' | 'live' | 'closed' | 'archived'

/** GET /company/postings[...] - the admin posting shape plus applicant count and review state. */
export interface CompanyPosting extends AdminOpportunity {
  applications_count: number
  review_state: PostingReviewState
}

export interface CompanyPostingPayload {
  type?: 'job' | 'internship'
  title?: string
  description?: string | null
  location?: string | null
  work_mode?: WorkModeValue | null
  application_deadline?: string | null
  openings?: number | null
  salary_range?: string | null
  employment_type?: EmploymentTypeValue | null
  duration_months?: number | null
  stipend?: string | null
}

/**
 * An applicant as a recruiter sees it (CompanyApplicantResource). There is
 * deliberately no phone, email or address - contact goes through the
 * placement office. The resume file downloads separately.
 */
export interface CompanyApplicant {
  id: number
  status: ApplicationStatus
  student: {
    id: number
    name: string | null
    roll_number: string
    department: string | null
    batch: string | null
    year: number | null
    cgpa: number | null
    backlogs: number | null
    linkedin_url: string | null
    github_url: string | null
    portfolio_url: string | null
    resume: { original_name: string | null; size: number | null; uploaded_at: string | null } | null
    skills?: string[]
    projects?: { title: string; description: string | null; technologies: string | null; project_url: string | null }[]
    certifications?: { name: string; issuing_organization: string | null; issue_date: string | null }[]
  }
  opportunity: OpportunitySummary | null
  applied_at: string | null
  selected_at: string | null
  rejection_reason: string | null
  interviews_count?: number
  status_history?: StatusChange<ApplicationStatus>[]
}

export interface CompanyApplicantParams {
  type?: 'job' | 'internship'
  posting_id?: number
  status?: ApplicationStatus
  search?: string
  page?: number
  per_page?: number
}

/** CompanyInterviewResource - the admin interview detail minus who created/updated it. */
export type CompanyInterview = Omit<InterviewDetail, 'created_by' | 'updated_by'>

export interface CompanyMember {
  id: number
  name: string
  email: string
  member_role: 'OWNER' | 'RECRUITER' | null
  is_active: boolean
  is_self: boolean
  last_login_at: string | null
  created_at: string | null
}
