import type { StatusTone } from '@/components/ui/StatusBadge'

export interface StatusMeta {
  label: string
  tone: StatusTone
}

type MetaMap<K extends string> = Record<K, StatusMeta>

export const ASSIGNMENT_STATUS: MetaMap<'ASSIGNED' | 'ACTIVE' | 'COMPLETED' | 'CANCELLED'> = {
  ASSIGNED: { label: 'Assigned', tone: 'info' },
  ACTIVE: { label: 'Active', tone: 'success' },
  COMPLETED: { label: 'Completed', tone: 'neutral' },
  CANCELLED: { label: 'Cancelled', tone: 'error' },
}

export const ATTENDANCE_STATUS: MetaMap<'PRESENT' | 'ABSENT' | 'LEAVE'> = {
  PRESENT: { label: 'Present', tone: 'success' },
  ABSENT: { label: 'Absent', tone: 'error' },
  LEAVE: { label: 'On leave', tone: 'warning' },
}

export const WEEKLY_REPORT_STATUS: MetaMap<'DRAFT' | 'SUBMITTED' | 'UNDER_REVIEW' | 'CHANGES_REQUESTED' | 'APPROVED'> = {
  DRAFT: { label: 'Draft', tone: 'neutral' },
  SUBMITTED: { label: 'Submitted', tone: 'info' },
  UNDER_REVIEW: { label: 'Under review', tone: 'warning' },
  CHANGES_REQUESTED: { label: 'Changes requested', tone: 'error' },
  APPROVED: { label: 'Approved', tone: 'success' },
}

export const DOCUMENT_STATUS: MetaMap<'SUBMITTED' | 'UNDER_REVIEW' | 'CHANGES_REQUESTED' | 'APPROVED'> = {
  SUBMITTED: { label: 'Submitted', tone: 'info' },
  UNDER_REVIEW: { label: 'Under review', tone: 'warning' },
  CHANGES_REQUESTED: { label: 'Changes requested', tone: 'error' },
  APPROVED: { label: 'Approved', tone: 'success' },
}

export const DOCUMENT_TYPE_LABEL: Record<string, string> = {
  OFFER_LETTER: 'Offer letter',
  JOINING_LETTER: 'Joining letter',
  INTERNSHIP_CERTIFICATE: 'Internship certificate',
  WORK_EVIDENCE: 'Work evidence',
  FINAL_REPORT: 'Final report',
  COMPLETION_CERTIFICATE: 'Completion certificate',
  OTHER: 'Other',
}

export const COMPLETION_STATUS: MetaMap<'DRAFT' | 'FINALIZED'> = {
  DRAFT: { label: 'Draft', tone: 'warning' },
  FINALIZED: { label: 'Finalized', tone: 'success' },
}

export const COMPLETION_OUTCOME: MetaMap<'COMPLETED' | 'NOT_COMPLETED'> = {
  COMPLETED: { label: 'Completed', tone: 'success' },
  NOT_COMPLETED: { label: 'Not completed', tone: 'error' },
}

export const PPO_STATUS: MetaMap<'OFFERED' | 'ACCEPTED' | 'DECLINED'> = {
  OFFERED: { label: 'Offered', tone: 'info' },
  ACCEPTED: { label: 'Accepted', tone: 'success' },
  DECLINED: { label: 'Declined', tone: 'error' },
}

/** Looks a value up in one of the maps above, falling back to the raw value so a status added on the backend later still renders instead of vanishing. */
export function metaFor<K extends string>(map: MetaMap<K>, value: string | null | undefined): StatusMeta {
  if (!value) return { label: '—', tone: 'neutral' }
  return (map as Record<string, StatusMeta>)[value] ?? { label: value, tone: 'neutral' }
}

export function optionsFor<K extends string>(map: MetaMap<K>): { value: K; label: string }[] {
  return (Object.keys(map) as K[]).map((value) => ({ value, label: map[value].label }))
}

export const COMPANY_STATUS: MetaMap<'PENDING' | 'APPROVED' | 'REJECTED' | 'SUSPENDED'> = {
  PENDING: { label: 'Pending review', tone: 'warning' },
  APPROVED: { label: 'Approved', tone: 'success' },
  REJECTED: { label: 'Rejected', tone: 'error' },
  SUSPENDED: { label: 'Suspended', tone: 'neutral' },
}

/** A company-portal posting as the recruiter reads it (Api\Company\PostingController::reviewState). */
export const POSTING_REVIEW_STATE: MetaMap<'draft' | 'in_review' | 'live' | 'closed' | 'archived'> = {
  draft: { label: 'Draft', tone: 'neutral' },
  in_review: { label: 'In review', tone: 'warning' },
  live: { label: 'Live', tone: 'success' },
  closed: { label: 'Closed', tone: 'info' },
  archived: { label: 'Archived', tone: 'neutral' },
}

export const COMPANY_SIZES = ['1-10', '11-50', '51-200', '201-500', '501-1000', '1000+'] as const

// ---- Placement Admin ------------------------------------------------------

export const OPPORTUNITY_STATUS: MetaMap<'draft' | 'published' | 'closed' | 'archived'> = {
  draft: { label: 'Draft', tone: 'neutral' },
  published: { label: 'Published', tone: 'success' },
  closed: { label: 'Closed', tone: 'warning' },
  archived: { label: 'Archived', tone: 'neutral' },
}

/** OpportunityStatus::canTransitionTo() - draft -> published|archived, published -> closed|archived, closed -> archived. */
export const OPPORTUNITY_TRANSITIONS: Record<string, ('published' | 'closed' | 'archived')[]> = {
  draft: ['published', 'archived'],
  published: ['closed', 'archived'],
  closed: ['archived'],
  archived: [],
}

export const WORK_MODE_LABEL: Record<string, string> = { on_site: 'On-site', remote: 'Remote', hybrid: 'Hybrid' }
export const EMPLOYMENT_TYPE_LABEL: Record<string, string> = { full_time: 'Full-time', part_time: 'Part-time', contract: 'Contract', temporary: 'Temporary' }

export const DRIVE_STATUS: MetaMap<'DRAFT' | 'PUBLISHED' | 'CLOSED' | 'CANCELLED'> = {
  DRAFT: { label: 'Draft', tone: 'neutral' },
  PUBLISHED: { label: 'Published', tone: 'success' },
  CLOSED: { label: 'Closed', tone: 'warning' },
  CANCELLED: { label: 'Cancelled', tone: 'error' },
}

export const DRIVE_TYPE_LABEL: Record<string, string> = { CAMPUS_PLACEMENT: 'Campus placement', INTERNSHIP: 'Internship', BOTH: 'Placement + internship' }

export const INTERVIEW_STATUS: MetaMap<'SCHEDULED' | 'COMPLETED' | 'CANCELLED' | 'RESCHEDULED'> = {
  SCHEDULED: { label: 'Scheduled', tone: 'info' },
  RESCHEDULED: { label: 'Rescheduled', tone: 'warning' },
  COMPLETED: { label: 'Completed', tone: 'success' },
  CANCELLED: { label: 'Cancelled', tone: 'error' },
}

export const INTERVIEW_MODE_LABEL: Record<string, string> = { ONLINE: 'Online', OFFLINE: 'On campus', HYBRID: 'Hybrid' }

/** ApplicationStatus::canTransitionTo() - what an ADMIN may set (WITHDRAWN is student-only, and the API refuses it). */
export const APPLICATION_TRANSITIONS: Record<string, string[]> = {
  APPLIED: ['UNDER_REVIEW'],
  UNDER_REVIEW: ['SHORTLISTED', 'REJECTED'],
  SHORTLISTED: ['ASSESSMENT', 'INTERVIEW', 'REJECTED'],
  ASSESSMENT: ['INTERVIEW', 'SELECTED', 'REJECTED'],
  INTERVIEW: ['SELECTED', 'REJECTED'],
  SELECTED: [],
  REJECTED: [],
  WITHDRAWN: [],
}

export const EXCEL_BATCH_STATUS: MetaMap<'UPLOADED' | 'VALIDATED' | 'READY_FOR_CONFIRMATION' | 'CONFIRMED' | 'FAILED' | 'ROLLED_BACK'> = {
  UPLOADED: { label: 'Uploaded', tone: 'neutral' },
  VALIDATED: { label: 'Validated', tone: 'info' },
  READY_FOR_CONFIRMATION: { label: 'Ready to confirm', tone: 'warning' },
  CONFIRMED: { label: 'Confirmed', tone: 'success' },
  FAILED: { label: 'Failed', tone: 'error' },
  ROLLED_BACK: { label: 'Rolled back', tone: 'neutral' },
}

export const EXCEL_OUTCOME: MetaMap<'VALID' | 'WARNING' | 'ERROR'> = {
  VALID: { label: 'Valid', tone: 'success' },
  WARNING: { label: 'Warning', tone: 'warning' },
  ERROR: { label: 'Error', tone: 'error' },
}

export const EXCEL_IMPORT_TYPES: { value: string; label: string; hint: string }[] = [
  { value: 'PLACEMENT_STATUS', label: 'Placement / application status', hint: 'Update application statuses by roll number, company and job role.' },
  { value: 'STUDENT_MASTER', label: 'Student master data', hint: 'Create or update student records by roll number.' },
  { value: 'INTERNSHIP_STATUS', label: 'Internship status', hint: 'Update internship application and assignment status.' },
  { value: 'INTERVIEW_SCHEDULE', label: 'Interview schedule', hint: 'Schedule interviews in bulk.' },
  { value: 'SHORTLIST', label: 'Shortlist', hint: 'Mark applications as shortlisted in bulk.' },
  { value: 'ASSESSMENT_RESULT', label: 'Assessment results', hint: 'Record assessment outcomes in bulk.' },
  { value: 'FINAL_SELECTION', label: 'Final selection', hint: 'Mark final selections in bulk.' },
  { value: 'ATTENDANCE', label: 'Attendance', hint: 'Import attendance records by roll number and internship.' },
]

export const EXCEL_EXPORT_TYPES: { value: string; label: string; group: string }[] = [
  { value: 'STUDENTS', label: 'Students', group: 'Records' },
  { value: 'APPLICATIONS', label: 'Applications', group: 'Records' },
  { value: 'INTERVIEWS', label: 'Interviews', group: 'Records' },
  { value: 'PLACEMENT_DRIVES', label: 'Placement drives', group: 'Records' },
  { value: 'COMPANIES', label: 'Companies', group: 'Records' },
  { value: 'INTERNSHIP_ASSIGNMENTS', label: 'Internship assignments', group: 'Internships' },
  { value: 'ATTENDANCE', label: 'Attendance', group: 'Internships' },
  { value: 'WEEKLY_PROGRESS_REPORTS', label: 'Weekly progress reports', group: 'Internships' },
  { value: 'INTERNSHIP_DOCUMENTS', label: 'Internship documents', group: 'Internships' },
  { value: 'INTERNSHIP_COMPLETIONS', label: 'Internship completions', group: 'Internships' },
  { value: 'PPO_RECORDS', label: 'PPO records', group: 'Internships' },
  { value: 'APPLICATION_SUMMARY', label: 'Application summary', group: 'Reports' },
  { value: 'STATUS_SUMMARY', label: 'Status summary', group: 'Reports' },
  { value: 'COMPANY_WISE_PLACEMENT_REPORT', label: 'Company-wise placements', group: 'Reports' },
  { value: 'DEPARTMENT_WISE_PLACEMENT_REPORT', label: 'Department-wise placements', group: 'Reports' },
  { value: 'BATCH_WISE_PLACEMENT_REPORT', label: 'Batch-wise placements', group: 'Reports' },
  { value: 'STUDENT_APPLICATION_REPORT', label: 'Student application report', group: 'Reports' },
  { value: 'UNPLACED_STUDENT_REPORT', label: 'Unplaced students', group: 'Reports' },
  { value: 'STUDENT_APPLICATION_ACTIVITY', label: 'Student application activity', group: 'Reports' },
]
