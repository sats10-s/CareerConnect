import type { OpportunityListItem, WorkMode } from '@/types/opportunity'

/**
 * Shared formatting for anywhere an OpportunityListItem is rendered
 * (OpportunitiesPage's list/detail modal, OpportunityCard, the
 * dashboard's "recently posted" widget) - extracted so the deadline
 * urgency logic and work-mode/compensation labels live in one place
 * instead of being re-typed at each call site.
 */
export function formatWorkMode(mode: WorkMode | string | null): string {
  switch (mode) {
    case 'on_site':
      return 'On-site'
    case 'remote':
      return 'Remote'
    case 'hybrid':
      return 'Hybrid'
    default:
      return '—'
  }
}

export function formatDeadline(dateString: string | null): { label: string; tone: 'neutral' | 'warning' | 'error' } {
  if (!dateString) return { label: 'No deadline', tone: 'neutral' }
  const deadline = new Date(dateString)
  const daysLeft = Math.ceil((deadline.getTime() - Date.now()) / (1000 * 60 * 60 * 24))
  const formatted = deadline.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
  if (daysLeft < 0) return { label: `Closed ${formatted}`, tone: 'error' }
  if (daysLeft <= 3) return { label: `${formatted} · ${daysLeft === 0 ? 'today' : `${daysLeft}d left`}`, tone: 'warning' }
  return { label: formatted, tone: 'neutral' }
}

/**
 * Job -> salary_range (a free-text field the admin sets); Internship ->
 * stipend/mo, or "Unpaid" when unset - mirrors how the detail view
 * already describes each type. Takes salary_range/stipend as optional
 * (not required, unlike OpportunityListItem's shape) so a
 * JobPostingDetail/InternshipDetail - which only ever carries the ONE
 * field that applies to its own type, not both nulled keys like the
 * list resource sends - can be passed straight through.
 */
export function formatCompensation(item: {
  type: OpportunityListItem['type']
  salary_range?: string | null
  stipend?: number | string | null
}): string {
  if (item.type === 'internship') {
    return item.stipend ? `₹${item.stipend}/mo` : 'Unpaid'
  }
  return item.salary_range ?? 'Not disclosed'
}
