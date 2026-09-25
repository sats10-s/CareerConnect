import { AlertTriangle } from 'lucide-react'
import { Button } from './Button'

interface ErrorStateProps {
  title?: string
  description?: string
  onRetry?: () => void
}

/**
 * New shared primitive - the error-with-retry pattern every API-backed
 * component needs per the design system ("human-readable error message
 * + [Try Again]"), previously hand-rolled per page as a one-off error
 * banner. Not wired into any page yet - a later pass can replace those
 * inline banners with this.
 */
export function ErrorState({ title = 'Something went wrong', description, onRetry }: ErrorStateProps) {
  return (
    <div className="flex flex-col items-center px-6 py-12 text-center">
      <span className="flex h-12 w-12 items-center justify-center rounded-full bg-error-bg text-error">
        <AlertTriangle className="h-6 w-6" />
      </span>
      <p className="mt-4 text-sm font-semibold text-ink">{title}</p>
      {description && <p className="mt-1 max-w-sm text-sm text-ink-soft">{description}</p>}
      {onRetry && (
        <div className="mt-5">
          <Button variant="secondary" onClick={onRetry}>
            Try again
          </Button>
        </div>
      )}
    </div>
  )
}
