import type { LucideIcon } from 'lucide-react'

type Tone = 'navy' | 'blue' | 'green' | 'amber' | 'red'

// Kept as boxed stat cards for the dashboards that still use this
// pattern (Faculty/Admin/Super Admin) - the Student dashboard's own
// metrics now use MetricBand (a divided strip, not boxed cards) per
// the design system's "never 4 identical boxed stat cards" rule.
const TONE_CLASSES: Record<Tone, string> = {
  navy: 'bg-indigo-tint text-indigo',
  blue: 'bg-indigo-tint text-indigo',
  green: 'bg-success-bg text-success',
  amber: 'bg-warning-bg text-warning',
  red: 'bg-error-bg text-error',
}

interface StatCardProps {
  label: string
  value: number | string
  icon: LucideIcon
  tone?: Tone
  suffix?: string
}

export function StatCard({ label, value, icon: Icon, tone = 'navy', suffix }: StatCardProps) {
  return (
    <div className="card animate-fade-in p-5">
      <div className="flex items-center justify-between">
        <p className="text-sm font-medium text-ink-soft">{label}</p>
        <span className={`flex h-9 w-9 items-center justify-center rounded-lg ${TONE_CLASSES[tone]}`}>
          <Icon className="h-[18px] w-[18px]" />
        </span>
      </div>
      <p className="mt-3 text-2xl font-bold tabular-nums tracking-tight text-ink">
        {value}
        {suffix && <span className="ml-0.5 text-base font-semibold text-ink-faint">{suffix}</span>}
      </p>
    </div>
  )
}
