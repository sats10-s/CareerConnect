import type { OpportunitySummary } from './application'

export type InternshipAssignmentStatus = 'ASSIGNED' | 'ACTIVE' | 'COMPLETED' | 'CANCELLED'

export interface InternshipAssignmentStatusHistoryItem {
  id: number
  from_status: InternshipAssignmentStatus | null
  to_status: InternshipAssignmentStatus
  remarks: string | null
  changed_by: { id: number; name: string } | null
  created_at: string
}

/**
 * Mirrors InternshipAssignmentResource as a non-admin viewer sees it
 * (notes/assigned_by/created_at/updated_at are admin-only and never
 * sent to a student - see that Resource's docblock).
 */
export interface InternshipAssignmentDetail {
  id: number
  status: InternshipAssignmentStatus
  assigned_at: string | null
  started_at: string | null
  ended_at: string | null
  faculty: { id: number; name: string; email?: string } | null
  internship: OpportunitySummary | null
  application: { id: number; status: string } | null
  status_history?: InternshipAssignmentStatusHistoryItem[]
}
