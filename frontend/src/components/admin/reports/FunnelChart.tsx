import { labelForApplicationStatus } from '@/components/ui/StatusBadge'
import type { ApplicationStatus } from '@/types/application'
import type { FunnelData } from '@/types/admin'

/** Pipeline order first, then the two exits - matches how an application actually moves. */
const ORDER: ApplicationStatus[] = ['APPLIED', 'UNDER_REVIEW', 'SHORTLISTED', 'ASSESSMENT', 'INTERVIEW', 'SELECTED', 'REJECTED', 'WITHDRAWN']

const BAR_TONE: Partial<Record<ApplicationStatus, string>> = {
  SELECTED: 'bg-success',
  REJECTED: 'bg-error',
  WITHDRAWN: 'bg-ink-faint',
}

/** Horizontal bars, one per application status (counts are what the API returns - bar length is relative to the largest bucket). */
export function FunnelChart({ funnel }: { funnel: FunnelData }) {
  const rows = ORDER.filter((status) => funnel[status])
  const max = Math.max(1, ...rows.map((status) => funnel[status].applications))
  return (
    <ul className="card divide-y divide-hairline">
      {rows.map((status) => {
        const { applications, students } = funnel[status]
        return (
          <li key={status} className="grid grid-cols-[7.5rem_1fr_auto] items-center gap-3 px-4 py-3 sm:grid-cols-[9rem_1fr_13rem]">
            <span className="text-sm text-ink">{labelForApplicationStatus(status)}</span>
            <span className="h-2 overflow-hidden rounded-full bg-sidebar" aria-hidden>
              <span className={`block h-full rounded-full ${BAR_TONE[status] ?? 'bg-primary'} transition-[width] duration-slow ease-out`} style={{ width: `${(applications / max) * 100}%` }} />
            </span>
            <span className="whitespace-nowrap text-right text-sm tabular-nums text-ink-soft">
              <span className="font-medium text-ink">{applications}</span> <span className="hidden sm:inline">applications · {students} students</span>
              <span className="sm:hidden">/ {students}</span>
            </span>
          </li>
        )
      })}
    </ul>
  )
}
