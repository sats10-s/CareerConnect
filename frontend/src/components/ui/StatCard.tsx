import type { LucideIcon } from 'lucide-react'

type Tone = 'navy' | 'blue' | 'green' | 'amber' | 'red'

const TONE_CLASSES: Record<Tone, string> = {
  navy: 'bg-navy-50 text-navy-700',
  blue: 'bg-blue-50 text-blue-700',
  green: 'bg-emerald-50 text-emerald-700',
  amber: 'bg-amber-50 text-amber-700',
  red: 'bg-red-50 text-red-700',
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
        <p className="text-sm font-medium text-gray-500">{label}</p>
        <span className={`flex h-9 w-9 items-center justify-center rounded-lg ${TONE_CLASSES[tone]}`}>
          <Icon className="h-[18px] w-[18px]" />
        </span>
      </div>
      <p className="mt-3 text-2xl font-bold tracking-tight text-gray-900">
        {value}
        {suffix && <span className="ml-0.5 text-base font-semibold text-gray-400">{suffix}</span>}
      </p>
    </div>
  )
}
