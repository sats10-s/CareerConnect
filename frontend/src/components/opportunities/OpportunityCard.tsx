import { ChevronRight } from 'lucide-react'
import { CompanyLogo } from './CompanyLogo'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { formatCompensation, formatDeadline, formatWorkMode } from '@/utils/opportunityFormatting'
import type { OpportunityListItem } from '@/types/opportunity'

/**
 * Dense opportunity row per the visual reference: logo left, role/
 * company/metadata center, compensation/deadline/action right - not a
 * grid of tall cards. Shared by OpportunitiesPage (Jobs/Internships)
 * and the dashboard's "recently posted" widget, replacing two
 * separate, narrower hand-rolled rows.
 *
 * No eligibility marker on the row: OpportunityListResource (the list
 * endpoint's row shape) carries no eligibility field by design - see
 * OpportunitiesPage.tsx's docblock on the backend's "never filter/mark
 * by eligibility in the list" product rule. Eligibility only appears
 * once a row is opened (the detail modal).
 */
export function OpportunityCard({
  item,
  onSelect,
}: {
  item: OpportunityListItem
  onSelect: (item: OpportunityListItem) => void
}) {
  const { label: deadlineLabel, tone: deadlineTone } = formatDeadline(item.application_deadline)

  return (
    <button
      type="button"
      onClick={() => onSelect(item)}
      className="card flex w-full items-center gap-4 p-4 text-left transition-shadow duration-150 hover:shadow-md"
    >
      <CompanyLogo name={item.company?.name} logoPath={item.company?.logo_path} />

      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-semibold text-ink">{item.title}</p>
        <p className="mt-0.5 truncate text-xs text-muted">{item.company?.name ?? 'Company not listed'}</p>
        <div className="mt-1.5 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-muted">
          <span>{item.location ?? 'Location unspecified'}</span>
          <span aria-hidden="true">·</span>
          <span>{formatWorkMode(item.work_mode)}</span>
          {item.type === 'internship' && item.duration_months && (
            <>
              <span aria-hidden="true">·</span>
              <span>{item.duration_months} mo</span>
            </>
          )}
          {item.type === 'job' && item.employment_type && (
            <>
              <span aria-hidden="true">·</span>
              <span>{item.employment_type}</span>
            </>
          )}
        </div>
      </div>

      <div className="hidden shrink-0 flex-col items-end gap-1.5 text-right sm:flex">
        <span className="text-sm font-semibold text-ink">{formatCompensation(item)}</span>
        <StatusBadge tone={deadlineTone}>{deadlineLabel}</StatusBadge>
      </div>

      <ChevronRight className="hidden h-4 w-4 shrink-0 text-ink-faint sm:block" aria-hidden="true" />
    </button>
  )
}
