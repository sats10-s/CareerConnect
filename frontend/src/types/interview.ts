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
