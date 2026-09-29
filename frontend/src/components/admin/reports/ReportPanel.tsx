import type { ReactNode } from 'react'
import { ErrorState } from '@/components/ui/ErrorState'
import { LoadingSkeleton } from '@/components/ui/LoadingSkeleton'
import { SectionHeader } from '@/components/ui/SectionHeader'

interface ReportPanelProps {
  title: string
  subtitle?: string
  action?: ReactNode
  isLoading: boolean
  hasData: boolean
  error: string | null
  onRetry: () => void
  children: ReactNode
}

/**
 * One report section with its own loading / error / content states, so a
 * single failing report endpoint never blanks the whole page. On a filter
 * change the previous content stays visible (dimmed) instead of flashing a
 * skeleton.
 */
export function ReportPanel({ title, subtitle, action, isLoading, hasData, error, onRetry, children }: ReportPanelProps) {
  return (
    <section aria-label={title}>
      <SectionHeader title={title} subtitle={subtitle} action={action} />
      <div className="mt-4">
        {error ? (
          <div className="card" role="alert"><ErrorState title={`Couldn't load ${title.toLowerCase()}`} description={error} onRetry={onRetry} /></div>
        ) : isLoading && !hasData ? (
          <div className="card space-y-3 p-5" aria-busy="true" aria-label={`Loading ${title.toLowerCase()}`}>
            <LoadingSkeleton className="h-4 w-1/3" />
            <LoadingSkeleton className="h-4 w-full" />
            <LoadingSkeleton className="h-4 w-2/3" />
          </div>
        ) : (
          <div className={isLoading ? 'opacity-60 transition-opacity' : 'transition-opacity'} aria-busy={isLoading}>{children}</div>
        )}
      </div>
    </section>
  )
}
