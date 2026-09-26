import type { ReactNode } from 'react'
import { Check } from 'lucide-react'

export type TimelineItemStatus = 'completed' | 'current' | 'upcoming'

export interface TimelineItem {
  id: string | number
  title: ReactNode
  description?: ReactNode
  timestamp?: ReactNode
  status: TimelineItemStatus
}

/**
 * Reusable dot/line timeline per the design-system spec - extracted
 * from the ad-hoc version previously built inline in
 * StudentApplicationsPage's status-history modal, so internship
 * workflows (attendance, weekly reports, completion) can reuse the
 * same visual instead of each hand-rolling their own. completed =
 * solid success green, current = solid accent orange with a soft
 * ring for emphasis, upcoming = hollow/muted dot - per spec.
 */
export function Timeline({ items }: { items: TimelineItem[] }) {
  return (
    <ol className="space-y-3">
      {items.map((item, index) => (
        <li key={item.id} className="relative flex gap-3 pl-1">
          {index !== items.length - 1 && (
            <span className="absolute left-[5px] top-4 h-full w-px bg-hairline" aria-hidden="true" />
          )}
          <TimelineDot status={item.status} />
          <div className="pb-3 text-sm">
            <p className={item.status === 'upcoming' ? 'text-muted' : 'text-ink font-medium'}>{item.title}</p>
            {item.description && <p className="mt-0.5 text-muted">{item.description}</p>}
            {item.timestamp && <p className="mt-0.5 text-xs text-ink-faint">{item.timestamp}</p>}
          </div>
        </li>
      ))}
    </ol>
  )
}

function TimelineDot({ status }: { status: TimelineItemStatus }) {
  if (status === 'completed') {
    return (
      <span className="relative mt-0.5 flex h-[18px] w-[18px] shrink-0 items-center justify-center rounded-full bg-success text-[color:var(--color-primary-ink)]" aria-hidden="true">
        <Check className="h-3 w-3" strokeWidth={3} />
      </span>
    )
  }
  if (status === 'current') {
    return (
      <span className="relative mt-1 flex h-3 w-3 shrink-0 items-center justify-center rounded-full bg-accent ring-4 ring-accent-tint" aria-hidden="true" />
    )
  }
  return (
    <span className="relative mt-1 h-2.5 w-2.5 shrink-0 rounded-full border-2 border-hairline bg-surface" aria-hidden="true" />
  )
}
