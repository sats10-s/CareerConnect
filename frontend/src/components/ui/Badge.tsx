import type { ReactNode } from 'react'

type BadgeTone = 'neutral' | 'success' | 'warning' | 'error' | 'info'

const TONE_CLASSES: Record<BadgeTone, string> = {
  neutral: 'bg-gray-100 text-gray-700',
  success: 'bg-emerald-100 text-emerald-700',
  warning: 'bg-amber-100 text-amber-800',
  error: 'bg-red-100 text-red-700',
  info: 'bg-blue-100 text-blue-700',
}

export function Badge({ tone = 'neutral', children }: { tone?: BadgeTone; children: ReactNode }) {
  return (
    <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${TONE_CLASSES[tone]}`}>
      {children}
    </span>
  )
}

/** Maps a backend application status string to a badge tone. */
export function toneForStatus(status: string): BadgeTone {
  switch (status) {
    case 'selected':
      return 'success'
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
