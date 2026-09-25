import { OpportunityCard } from './OpportunityCard'
import { staggerDelay } from '@/styles/motion'
import type { OpportunityListItem } from '@/types/opportunity'
import type { ApplicationStatus } from '@/types/application'

/**
 * Dense vertical stack of OpportunityCard rows, per spec ("dense
 * vertical list... not a giant grid"). Deliberately no built-in
 * loading/empty/error handling here - OpportunitiesPage's copy for
 * those differs by context (active filters vs. none, load failure,
 * etc.) and the dashboard widget's differs again, so each caller
 * keeps owning that, same as before this component existed.
 */
export function OpportunityList({
  items,
  onSelect,
  appliedStatusFor,
}: {
  items: OpportunityListItem[]
  onSelect: (item: OpportunityListItem) => void
  /** Optional - see hooks/useAppliedOpportunities.ts. Omit for contexts that don't track application state (none currently). */
  appliedStatusFor?: (item: OpportunityListItem) => ApplicationStatus | undefined
}) {
  return (
    <div className="space-y-3">
      {items.map((item, index) => (
        <OpportunityCard
          key={item.id}
          item={item}
          onSelect={onSelect}
          appliedStatus={appliedStatusFor?.(item)}
          style={staggerDelay(index)}
        />
      ))}
    </div>
  )
}
