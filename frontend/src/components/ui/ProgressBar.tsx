type ProgressTone = 'primary' | 'accent' | 'success'

const TONE_CLASSES: Record<ProgressTone, string> = {
  primary: 'bg-primary',
  accent: 'bg-accent',
  success: 'bg-success',
}

/**
 * New shared primitive, extracted from the bar previously built inline
 * inside ProfileStrength (dashboard/ProfileStrength.tsx now consumes
 * this instead of its own markup) so other progress use cases
 * (weekly-report/document review progress, etc.) don't re-implement it.
 */
export function ProgressBar({
  value,
  tone = 'primary',
  className = '',
}: {
  /** 0-100. Values outside that range are clamped. */
  value: number
  tone?: ProgressTone
  className?: string
}) {
  const clamped = Math.max(0, Math.min(100, value))
  return (
    <div
      className={`h-2 overflow-hidden rounded-full bg-sidebar ${className}`}
      role="progressbar"
      aria-valuenow={clamped}
      aria-valuemin={0}
      aria-valuemax={100}
    >
      <div
        className={`h-full rounded-full transition-[width] duration-200 ${TONE_CLASSES[tone]}`}
        style={{ width: `${clamped}%` }}
      />
    </div>
  )
}
