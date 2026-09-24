/** New shared primitive - no skeleton-loading component existed before this design-system pass (Spinner is the only existing loading indicator). Not wired into any page yet. */
export function LoadingSkeleton({ className = 'h-4 w-full' }: { className?: string }) {
  return <span className={`block animate-pulse rounded-md bg-sidebar ${className}`} aria-hidden="true" />
}

export function LoadingSkeletonLines({ count = 3, className = 'h-4 w-full' }: { count?: number; className?: string }) {
  return (
    <div className="space-y-2" aria-hidden="true">
      {Array.from({ length: count }).map((_, index) => (
        <LoadingSkeleton key={index} className={className} />
      ))}
    </div>
  )
}
