type RingTone = 'primary' | 'accent' | 'success'

const STROKE_CLASSES: Record<RingTone, string> = {
  primary: 'stroke-primary',
  accent: 'stroke-accent',
  success: 'stroke-success',
}

/**
 * New shared primitive - circular counterpart to ProgressBar, for
 * compact metrics like an attendance percentage. Not wired into any
 * page yet; AttendanceSection currently shows its percentage as a
 * StatCard number, which reads fine - this is available for a spot
 * that wants a ring instead (e.g. a dashboard summary tile).
 */
export function ProgressRing({
  value,
  size = 56,
  strokeWidth = 6,
  tone = 'primary',
  showValue = true,
  className = '',
}: {
  /** 0-100. Values outside that range are clamped. */
  value: number
  size?: number
  strokeWidth?: number
  tone?: RingTone
  showValue?: boolean
  className?: string
}) {
  const clamped = Math.max(0, Math.min(100, value))
  const radius = (size - strokeWidth) / 2
  const circumference = 2 * Math.PI * radius
  const offset = circumference * (1 - clamped / 100)

  return (
    <span className={`relative inline-flex items-center justify-center ${className}`} style={{ width: size, height: size }}>
      <svg
        width={size}
        height={size}
        viewBox={`0 0 ${size} ${size}`}
        className="-rotate-90"
        role="progressbar"
        aria-valuenow={clamped}
        aria-valuemin={0}
        aria-valuemax={100}
      >
        <circle cx={size / 2} cy={size / 2} r={radius} strokeWidth={strokeWidth} className="fill-none stroke-hairline" />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          strokeWidth={strokeWidth}
          strokeLinecap="round"
          strokeDasharray={circumference}
          strokeDashoffset={offset}
          className={`fill-none transition-[stroke-dashoffset] duration-300 ${STROKE_CLASSES[tone]}`}
        />
      </svg>
      {showValue && (
        <span className="absolute text-xs font-semibold tabular-nums text-ink">{Math.round(clamped)}%</span>
      )}
    </span>
  )
}
