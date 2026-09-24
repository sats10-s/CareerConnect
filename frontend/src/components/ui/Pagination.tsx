import { ChevronLeft, ChevronRight } from 'lucide-react'

interface PaginationProps {
  page: number
  totalPages: number
  onChange: (page: number) => void
}

/** New shared primitive - no pagination component existed before this design-system pass. Not wired into any page yet. */
export function Pagination({ page, totalPages, onChange }: PaginationProps) {
  if (totalPages <= 1) return null

  return (
    <nav className="flex items-center justify-between gap-4" aria-label="Pagination">
      <button
        type="button"
        onClick={() => onChange(page - 1)}
        disabled={page <= 1}
        className="btn-secondary px-3 py-1.5 text-xs"
      >
        <ChevronLeft className="h-4 w-4" />
        Previous
      </button>
      <p className="text-sm text-ink-soft">
        Page <span className="font-medium text-ink">{page}</span> of {totalPages}
      </p>
      <button
        type="button"
        onClick={() => onChange(page + 1)}
        disabled={page >= totalPages}
        className="btn-secondary px-3 py-1.5 text-xs"
      >
        Next
        <ChevronRight className="h-4 w-4" />
      </button>
    </nav>
  )
}
