import { X } from 'lucide-react'
import type { ReactNode } from 'react'

interface FilterChipProps {
  label: ReactNode
  active?: boolean
  onClick?: () => void
  onRemove?: () => void
}

/**
 * New shared primitive - a toggleable filter pill (e.g. work-mode or
 * status filters on a list page) or, with onRemove, a dismissible
 * "active filter" tag. Not wired into any page yet - OpportunitiesPage
 * currently uses plain Select dropdowns for its filters, which still
 * work; this is available for pages that want the pill-filter pattern
 * from the reference instead.
 */
export function FilterChip({ label, active = false, onClick, onRemove }: FilterChipProps) {
  return (
    <span
      className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium transition-colors duration-150 ${
        active ? 'border-primary bg-primary-tint text-primary' : 'border-hairline bg-surface text-muted hover:text-ink'
      }`}
    >
      {onClick ? (
        <button type="button" onClick={onClick} className="inline-flex items-center gap-1.5">
          {label}
        </button>
      ) : (
        label
      )}
      {onRemove && (
        <button type="button" onClick={onRemove} aria-label="Remove filter" className="text-current opacity-70 hover:opacity-100">
          <X className="h-3 w-3" />
        </button>
      )}
    </span>
  )
}
