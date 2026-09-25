import { ChevronRight } from 'lucide-react'
import { CompanyLogo } from '@/components/opportunities/CompanyLogo'
import { StatusBadge, toneForApplicationStatus, labelForApplicationStatus } from '@/components/ui/StatusBadge'
import { formatRelativeTime } from '@/utils/formatRelativeTime'
import type { ApplicationListItem } from '@/types/application'

/**
 * A single row in the Applications list - Frontend Phase 3 section 6
 * ("premium list/table hybrid... should feel like a student's
 * personal career tracker", explicitly NOT the generic admin
 * DataTable aesthetic the page used before). Mirrors OpportunityCard's
 * visual language (logo, dense single row, real StatusBadge) rather
 * than introducing a new pattern - same company, same list, same
 * design system.
 *
 * Desktop columns (Opportunity/Company/Type, Applied, Last Updated,
 * Current Status, Action) all render in one row; mobile collapses to
 * the header block only, plus an explicit "View application" row per
 * spec section 20's mobile card field list.
 */
export function ApplicationRow({
  item,
  onSelect,
}: {
  item: ApplicationListItem
  onSelect: (item: ApplicationListItem) => void
}) {
  const opportunity = item.opportunity
  const typeLabel = opportunity?.type === 'internship' ? 'Internship' : 'Job'

  return (
    <button
      type="button"
      onClick={() => onSelect(item)}
      className="card block w-full p-4 text-left transition-shadow duration-150 hover:shadow-md"
    >
      <div className="flex items-center gap-4">
        <CompanyLogo name={opportunity?.company?.name} logoPath={opportunity?.company?.logo_path} />

        <div className="min-w-0 flex-1">
          <p className="truncate font-medium text-ink">{opportunity?.title ?? 'Opportunity unavailable'}</p>
          <p className="mt-0.5 truncate text-xs text-muted">
            {opportunity?.company?.name ?? '—'}
            <span className="mx-1.5 text-hairline">·</span>
            {typeLabel}
          </p>
        </div>

        <div className="hidden shrink-0 text-right sm:block sm:w-28">
          <p className="text-[11px] uppercase tracking-wide text-ink-faint">Applied</p>
          <p className="text-sm text-ink">{item.applied_at ? formatRelativeTime(item.applied_at) : '—'}</p>
        </div>

        <div className="hidden shrink-0 text-right md:block md:w-32">
          <p className="text-[11px] uppercase tracking-wide text-ink-faint">Last updated</p>
          <p className="text-sm text-ink">{item.updated_at ? formatRelativeTime(item.updated_at) : '—'}</p>
        </div>

        <div className="shrink-0">
          <StatusBadge tone={toneForApplicationStatus(item.status)}>{labelForApplicationStatus(item.status)}</StatusBadge>
        </div>

        <ChevronRight className="hidden h-4 w-4 shrink-0 text-ink-faint sm:block" aria-hidden="true" />
      </div>

      <div className="mt-3 flex items-center justify-between border-t border-hairline pt-3 sm:hidden">
        <p className="text-xs text-muted">Applied {item.applied_at ? formatRelativeTime(item.applied_at) : '—'}</p>
        <span className="inline-flex items-center gap-1 text-xs font-medium text-primary">
          View application
          <ChevronRight className="h-3.5 w-3.5" aria-hidden="true" />
        </span>
      </div>
    </button>
  )
}
