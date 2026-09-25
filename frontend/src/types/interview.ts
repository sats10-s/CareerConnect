import type { OpportunitySummary } from './application'

export type InterviewMode = 'ONLINE' | 'OFFLINE' | 'HYBRID'
export type InterviewStatus = 'SCHEDULED' | 'COMPLETED' | 'CANCELLED' | 'RESCHEDULED'

/** Mirrors App\Http\Resources\InterviewListResource (GET /student/interviews). */
export interface InterviewListItem {
  id: number
  round: number | string
  title: string | null
  scheduled_at: string | null
  duration_minutes: number | null
  mode: InterviewMode
  status: InterviewStatus
  opportunity: OpportunitySummary | null
}

/**
 * Mirrors App\Http\Resources\InterviewResource (GET
 * /student/interviews/{id}) - the full detail payload. Used to show
 * meeting link/venue/interviewer on an application's Interview
 * connection (see pages/student/ApplicationDetail.tsx) once an
 * interview has already been matched via InterviewListItem.opportunity.
 * Deliberately excludes panel_notes/interviewer_email, which the
 * backend never sends to a student - see that Resource's docblock.
 */
export interface InterviewDetail {
  id: number
  round: number | string
  title: string | null
  scheduled_at: string | null
  duration_minutes: number | null
  mode: InterviewMode
  meeting_url: string | null
  venue: string | null
  interviewer_name: string | null
  status: InterviewStatus
  cancelled_at: string | null
  cancellation_reason: string | null
  completed_at: string | null
  opportunity: OpportunitySummary | null
}
