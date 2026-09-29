import type { ReactNode } from 'react'
import { ErrorState } from '@/components/ui/ErrorState'
import { LoadingSkeleton } from '@/components/ui/LoadingSkeleton'

interface ListStateProps {
  isLoading: boolean
  error: string | null
  isEmpty: boolean
  onRetry: () => void
  /** Rendered (inside a card) when the list loaded fine but has nothing to show - pass a specific EmptyState, not generic copy. */
  empty: ReactNode
  /** Skeleton rows shown on the first load only; later reloads keep the current rows visible (dimmed) so filters don't flash the page blank. */
  skeletonRows?: number
  hasLoadedOnce?: boolean
  children: ReactNode
}

/**
 * One place for the four states every list page needs: loading,
 * error-with-retry, empty, populated. The error and empty states sit
 * in a card so the page keeps its shape; a reload after a filter
 * change dims the existing rows instead of swapping in a skeleton.
 */
export function ListState({ isLoading, error, isEmpty, onRetry, empty, skeletonRows = 6, hasLoadedOnce = false, children }: ListStateProps) {
  if (error) {
    return (
      <div className="card" role="alert">
        <ErrorState description={error} onRetry={onRetry} />
      </div>
    )
  }

  if (isLoading && !hasLoadedOnce) {
    return (
      <div className="card divide-y divide-hairline" aria-busy="true" aria-label="Loading">
        {Array.from({ length: skeletonRows }).map((_, index) => (
          <div key={index} className="flex items-center gap-4 p-4">
            <LoadingSkeleton className="h-9 w-9 shrink-0 rounded-full" />
            <div className="min-w-0 flex-1 space-y-2">
              <LoadingSkeleton className="h-3.5 w-1/3" />
              <LoadingSkeleton className="h-3 w-1/2" />
            </div>
            <LoadingSkeleton className="hidden h-6 w-20 sm:block" />
          </div>
        ))}
      </div>
    )
  }

  if (isEmpty && !isLoading) return <div className="card">{empty}</div>

  return <div className={isLoading ? 'pointer-events-none opacity-60 transition-opacity' : 'transition-opacity'}>{children}</div>
}
