import { ErrorState } from '@/components/ui/ErrorState'
import { LoadingSkeleton } from '@/components/ui/LoadingSkeleton'

/** First-load placeholder shaped like a dashboard (header, stat row, one panel) instead of a lone spinner. */
export function DashboardLoading({ stats = 4 }: { stats?: number }) {
  return (
    <div className="space-y-6" aria-busy="true" aria-label="Loading dashboard">
      <div className="space-y-2">
        <LoadingSkeleton className="h-8 w-64 max-w-full" />
        <LoadingSkeleton className="h-4 w-80 max-w-full" />
      </div>
      <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
        {Array.from({ length: stats }).map((_, i) => (
          <div key={i} className="card space-y-3 p-5">
            <LoadingSkeleton className="h-3.5 w-1/2" />
            <LoadingSkeleton className="h-7 w-1/3" />
          </div>
        ))}
      </div>
      <div className="card space-y-3 p-5">
        <LoadingSkeleton className="h-4 w-1/4" />
        <LoadingSkeleton className="h-4 w-full" />
        <LoadingSkeleton className="h-4 w-5/6" />
      </div>
    </div>
  )
}

/** Dashboard-level load failure with a retry, in place of a bare "unable to load" line. */
export function DashboardError({ message, onRetry }: { message: string | null; onRetry: () => void }) {
  return (
    <div className="card" role="alert">
      <ErrorState title="Couldn't load your dashboard" description={message ?? 'Please try again in a moment.'} onRetry={onRetry} />
    </div>
  )
}
