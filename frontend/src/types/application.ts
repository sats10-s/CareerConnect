/** Mirrors App\Enums\ApplicationStatus exactly (8 cases, UPPER_SNAKE). */
export type ApplicationStatus =
  | 'APPLIED'
  | 'UNDER_REVIEW'
  | 'SHORTLISTED'
  | 'ASSESSMENT'
  | 'INTERVIEW'
  | 'SELECTED'
  | 'REJECTED'
  | 'WITHDRAWN'

/** Mirrors Concerns\FormatsOpportunitySummary::opportunitySummary(). */
export interface OpportunitySummary {
  id: number
  type: 'job' | 'internship'
  title: string
  status: string | null
  company: { id: number; name: string; logo_path: string | null } | null
}

/** Mirrors App\Http\Resources\ApplicationStatusHistoryResource. */
export interface ApplicationStatusHistoryItem {
  id: number
  from_status: ApplicationStatus | null
  to_status: ApplicationStatus
  remarks: string | null
  changed_by: { id: number; name: string } | null
  created_at: string
}

/** Mirrors App\Http\Resources\ApplicationListResource (GET /student/applications). */
export interface ApplicationListItem {
  id: number
  status: ApplicationStatus
  opportunity: OpportunitySummary | null
  applied_at: string | null
  updated_at: string | null
}

/**
 * Mirrors App\Http\Resources\ApplicationResource (GET
 * /student/applications/{id} and the withdraw() response).
 * `my_eligibility` is merged in by the controller
 * (OpportunityEligibilityService::evaluate()'s shape isn't pinned down
 * here - not surfaced in the UI yet, kept as `unknown` rather than
 * guessed at).
 */
export interface ApplicationDetail {
  id: number
  status: ApplicationStatus
  opportunity: OpportunitySummary | null
  applied_at: string | null
  withdrawn_at: string | null
  selected_at: string | null
  rejection_reason: string | null
  notes: string | null
  status_history: ApplicationStatusHistoryItem[]
  my_eligibility?: unknown
  created_at: string
  updated_at: string
}
