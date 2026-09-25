export type OpportunityType = 'job' | 'internship'

/** Mirrors App\Enums\OpportunityStatus (lowercase values). */
export type OpportunityStatus = 'draft' | 'published' | 'closed' | 'archived'

/** Mirrors App\Enums\WorkMode (lowercase values). */
export type WorkMode = 'on_site' | 'remote' | 'hybrid'

export interface OpportunityCompanySummary {
  id: number
  name: string
  logo_path: string | null
}

export interface OpportunityCompanyDetail extends OpportunityCompanySummary {
  industry: string | null
}

/**
 * Mirrors App\Http\Resources\OpportunityListResource (GET
 * /student/opportunities). Job-only fields (employment_type,
 * salary_range) and internship-only fields (duration_months, stipend)
 * are both present but nulled for the type they don't apply to - the
 * backend always sends every key so one row shape covers both types.
 */
export interface OpportunityListItem {
  id: number
  type: OpportunityType
  title: string
  location: string | null
  work_mode: WorkMode | null
  application_deadline: string | null
  status: OpportunityStatus | null
  company: OpportunityCompanySummary | null
  employment_type: string | null
  salary_range: string | null
  duration_months: number | null
  stipend: number | string | null
}

/** Mirrors the `eligibility` block on JobPostingResource/InternshipResource - the admin-configured CRITERIA, same for every student. */
export interface EligibilityCriteria {
  enforced: boolean
  minimum_cgpa: number | null
  maximum_backlogs: number | null
  departments: { id: number; name: string | null }[]
  batches: { id: number; name: string | null }[]
  years: number[]
  required_skills: string[]
}

export interface EligibilityReason {
  code: string
  message: string
}

/**
 * Mirrors OpportunityEligibilityService::evaluate()'s return shape -
 * merged into showJob()/showInternship() as a `my_eligibility` sibling
 * key. THIS student's own status against the opportunity's criteria,
 * as distinct from `eligibility` above (the criteria themselves).
 */
export interface MyEligibility {
  eligibility_enforced: boolean
  eligible: boolean
  reasons: EligibilityReason[]
}

interface OpportunityDetailBase {
  id: number
  title: string
  description: string
  location: string | null
  work_mode: WorkMode | null
  application_deadline: string | null
  openings: number | null
  status: OpportunityStatus | null
  is_active: boolean
  company: OpportunityCompanyDetail | null
  creator: { id: number; name: string } | null
  updater: { id: number; name: string } | null
  eligibility: EligibilityCriteria
  my_eligibility: MyEligibility
  created_at: string
  updated_at: string
}

/** Mirrors App\Http\Resources\JobPostingResource (GET /student/opportunities/jobs/{id}). */
export interface JobPostingDetail extends OpportunityDetailBase {
  type: 'job'
  salary_range: string | null
  employment_type: string | null
}

/** Mirrors App\Http\Resources\InternshipResource (GET /student/opportunities/internships/{id}). */
export interface InternshipDetail extends OpportunityDetailBase {
  type: 'internship'
  duration_months: number | null
  stipend: number | string | null
}

export type OpportunityDetail = JobPostingDetail | InternshipDetail
