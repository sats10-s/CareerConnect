import { OpportunityCard } from './OpportunityCard'
import type { OpportunityListItem } from '@/types/opportunity'

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
}: {
  items: OpportunityListItem[]
  onSelect: (item: OpportunityListItem) => void
}) {
  return (
    <div className="space-y-3">
      {items.map((item) => (
        <OpportunityCard key={item.id} item={item} onSelect={onSelect} />
      ))}
    </div>
  )
}
