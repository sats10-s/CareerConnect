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
 * Maps a backend application status string to a badge tone. "selected"
 * gets the gold tone - this is the one interactive-context place gold
 * signals achievement, per the design system's <5%-of-the-UI rule.
 */
export function toneForApplicationStatus(status: string): StatusTone {
  switch (status) {
    case 'selected':
      return 'gold'
    case 'interview':
      return 'info'
    case 'shortlisted':
      return 'warning'
    case 'rejected':
      return 'error'
    default:
      return 'neutral'
  }
}
