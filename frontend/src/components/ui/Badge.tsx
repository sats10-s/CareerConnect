import type { ReactNode } from 'react'
import { StatusBadge, toneForApplicationStatus, type StatusTone } from './StatusBadge'

type BadgeTone = 'neutral' | 'success' | 'warning' | 'error' | 'info'

/**
 * @deprecated Prefer StatusBadge directly for new work. Kept as a thin
 * wrapper so existing call sites (e.g. the Super Admin dashboard) pick
 * up the token palette without an API change.
 */
export function Badge({ tone = 'neutral', children }: { tone?: BadgeTone; children: ReactNode }) {
  return <StatusBadge tone={tone}>{children}</StatusBadge>
}

export function toneForStatus(status: string): StatusTone {
  return toneForApplicationStatus(status)
}
