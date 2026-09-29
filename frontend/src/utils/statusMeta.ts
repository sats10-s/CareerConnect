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
