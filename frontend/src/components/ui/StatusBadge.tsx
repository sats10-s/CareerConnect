import type { ReactNode } from 'react'

export type StatusTone = 'neutral' | 'success' | 'warning' | 'error' | 'info' | 'gold'

/**
 * Each tone pairs a tinted background with its matching *text-safe*
 * foreground token (never the raw --gold on a tint, which measures
 * 3.10:1 - see index.css's token comment / the design-system report).
 * Status is never color alone: the children text IS the status label,
 * these classes are reinforcement, not the only signal.
 */
const TONE_CLASSES: Record<StatusTone, string> = {
  neutral: 'bg-sidebar text-ink-soft',
  success: 'bg-success-bg text-success',
  warning: 'bg-warning-bg text-warning',
  error: 'bg-error-bg text-error',
  // The dedicated info-bg/info-text pair, not the primary-brand
  // indigo/indigo-tint tokens - a status color should read as a status,
  // not as "this row is the brand color for some reason."
  info: 'bg-info-bg text-info',
  // Gold is reserved for achievement/selection - the <5% accent, never a
  // default interactive or informational tone.
  gold: 'bg-gold-bg text-gold-ink',
}

export function StatusBadge({ tone = 'neutral', children }: { tone?: StatusTone; children: ReactNode }) {
  return (
    <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ${TONE_CLASSES[tone]}`}>
      {children}
    </span>
  )
}

/**
 * Maps a backend Application status (App\Enums\ApplicationStatus - see
 * that enum's 8 UPPER_SNAKE cases) to a badge tone. Fixed to match the
 * real enum values: this previously matched on lowercase strings
 * ('selected', 'interview', ...) that the actual API never sends
 * (ApplicationResource always serializes the enum's real value, e.g.
 * 'SELECTED') - so every application status rendered through this
 * function fell through to 'neutral' the moment it was ever wired to
 * real data. Discovered while building the Applications page.
 *
 * SELECTED gets the gold tone - this is the one interactive-context
 * place gold signals achievement, per the design system's
 * <5%-of-the-UI rule.
 */
export function toneForApplicationStatus(status: string): StatusTone {
  switch (status) {
    case 'SELECTED':
      return 'gold'
    case 'UNDER_REVIEW':
    case 'ASSESSMENT':
    case 'INTERVIEW':
      return 'info'
    case 'SHORTLISTED':
      return 'warning'
    case 'REJECTED':
      return 'error'
    case 'WITHDRAWN':
    case 'APPLIED':
    default:
      return 'neutral'
  }
}

const APPLICATION_STATUS_LABELS: Record<string, string> = {
  APPLIED: 'Applied',
  UNDER_REVIEW: 'Under Review',
  SHORTLISTED: 'Shortlisted',
  ASSESSMENT: 'Assessment',
  INTERVIEW: 'Interview',
  SELECTED: 'Selected',
  REJECTED: 'Rejected',
  WITHDRAWN: 'Withdrawn',
}

/** Human-readable label for an ApplicationStatus value - falls back to the raw value for anything unrecognized, so a new backend case never renders as literally nothing. */
export function labelForApplicationStatus(status: string): string {
  return APPLICATION_STATUS_LABELS[status] ?? status
}

/** Maps a backend Interview status (App\Enums\InterviewStatus) to a badge tone. */
export function toneForInterviewStatus(status: string): StatusTone {
  switch (status) {
    case 'COMPLETED':
      return 'success'
    case 'CANCELLED':
      return 'error'
    case 'RESCHEDULED':
      return 'warning'
    case 'SCHEDULED':
    default:
      return 'info'
  }
}

const INTERVIEW_STATUS_LABELS: Record<string, string> = {
  SCHEDULED: 'Scheduled',
  COMPLETED: 'Completed',
  CANCELLED: 'Cancelled',
  RESCHEDULED: 'Rescheduled',
}

/** Human-readable label for an InterviewStatus value. */
export function labelForInterviewStatus(status: string): string {
  return INTERVIEW_STATUS_LABELS[status] ?? status
}
