import { ChevronRight } from 'lucide-react'
import type { CSSProperties } from 'react'
import { CompanyLogo } from './CompanyLogo'
import { StatusBadge, toneForApplicationStatus, labelForApplicationStatus } from '@/components/ui/StatusBadge'
import { formatCompensation, formatDeadline, formatEmploymentType, formatWorkMode } from '@/utils/opportunityFormatting'
import type { OpportunityListItem } from '@/types/opportunity'
import type { ApplicationStatus } from '@/types/application'

/**
 * Dense opportunity row per the visual reference: logo left, role/
 * company/metadata center, compensation/deadline-or-application-state/
 * action right - not a grid of tall cards. Shared by OpportunitiesPage
 * (Jobs/Internships) and the dashboard's "recently posted" widget.
 *
 * No eligibility marker on the row: OpportunityListResource (the list
 * endpoint's row shape) carries no eligibility field by design - see
 * OpportunitiesPage.tsx's docblock on the backend's "never filter/mark
 * by eligibility in the list" product rule. Eligibility only appears
 * once a row is opened (the detail page).
 *
 * `appliedStatus` is optional and caller-supplied (see
 * hooks/useAppliedOpportunities.ts) rather than derived here - this
 * component has no opinion on where that data comes from, since the
 * opportunity endpoints themselves don't carry it.
 */
export function OpportunityCard({
  item,
  onSelect,
  appliedStatus,
  style,
}: {
  item: OpportunityListItem
  onSelect: (item: OpportunityListItem) => void
  appliedStatus?: ApplicationStatus
  /** Entrance-stagger delay - see styles/motion.ts's staggerDelay(). */
  style?: CSSProperties
}) {
  const { label: deadlineLabel, tone: deadlineTone } = formatDeadline(item.application_deadline)

  return (
    <button
      type="button"
      onClick={() => onSelect(item)}
      style={style}
      className="card animate-fade-in flex w-full items-center gap-4 p-4 text-left transition duration-fast ease-out hover:shadow-md active:scale-[0.99]"
    >
      <CompanyLogo name={item.company?.name} logoPath={item.company?.logo_path} />

      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-semibold text-ink">{item.title}</p>
        <p className="mt-0.5 truncate text-xs text-muted">{item.company?.name ?? 'Company not listed'}</p>
        <div className="mt-1.5 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-muted">
          <span>{item.location ?? 'Location unspecified'}</span>
          {item.work_mode && (
            <>
              <span aria-hidden="true">·</span>
              <span>{formatWorkMode(item.work_mode)}</span>
            </>
          )}
          {item.type === 'internship' && item.duration_months && (
            <>
              <span aria-hidden="true">·</span>
              <span>{item.duration_months} mo</span>
            </>
          )}
          {item.type === 'job' && item.employment_type && (
            <>
              <span aria-hidden="true">·</span>
              <span>{formatEmploymentType(item.employment_type)}</span>
            </>
          )}
        </div>
        {/* Below sm the right-hand column is hidden, so pay and state move under the metadata instead of disappearing. */}
        <div className="mt-2 flex flex-wrap items-center gap-2 sm:hidden">
          <span className="text-xs font-semibold text-ink">{formatCompensation(item)}</span>
          {appliedStatus ? (
            <StatusBadge tone={toneForApplicationStatus(appliedStatus)}>{labelForApplicationStatus(appliedStatus)}</StatusBadge>
          ) : (
            <StatusBadge tone={deadlineTone}>{deadlineLabel}</StatusBadge>
          )}
        </div>
      </div>

      <div className="hidden shrink-0 flex-col items-end gap-1.5 text-right sm:flex">
        <span className="text-sm font-semibold text-ink">{formatCompensation(item)}</span>
        {appliedStatus ? (
          <StatusBadge tone={toneForApplicationStatus(appliedStatus)}>{labelForApplicationStatus(appliedStatus)}</StatusBadge>
        ) : (
          <StatusBadge tone={deadlineTone}>{deadlineLabel}</StatusBadge>
        )}
      </div>

      <ChevronRight className="hidden h-4 w-4 shrink-0 text-ink-faint sm:block" aria-hidden="true" />
    </button>
  )
}
