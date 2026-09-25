export type InternshipCompletionOutcome = 'COMPLETED' | 'NOT_COMPLETED'
export type InternshipCompletionRecordStatus = 'DRAFT' | 'FINALIZED'

/** Mirrors the base (non-admin/faculty) fields of InternshipCompletionResource, returned by GET /student/internship-completion. */
export interface InternshipCompletionDetail {
  id: number
  internship_assignment_id: number
  completion_date: string | null
  outcome: InternshipCompletionOutcome | null
  final_remarks: string | null
  status: InternshipCompletionRecordStatus
  finalized_at: string | null
}

export type PpoStatus = 'OFFERED' | 'ACCEPTED' | 'DECLINED'

/** Mirrors the base (non-admin/faculty) fields of PpoRecordResource, returned by GET /student/ppo. */
export interface PpoRecordDetail {
  id: number
  internship_assignment_id: number
  company: { id: number; name: string } | null
  offered_at: string | null
  joining_date: string | null
  designation: string | null
  package: string | null
  status: PpoStatus
  remarks: string | null
}
